<?php
declare(strict_types=1);

namespace SimpleStore\Content;

use InvalidArgumentException;
use SimpleStore\Navigation\Slugger;
use SimpleStore\Product\ProductDetails;

/** Apply a small edit to a complete page or article before saving another revision. */
final class ContentInlineEditor
{
    private const FIELDS = ['title', 'slug', 'summary', 'menu_order', 'published', 'visible_in_menu'];

    public static function starter(string $type, string $language): array
    {
        if (!in_array($type, ['page', 'post'], true)) {
            throw new InvalidArgumentException('Neznámý typ dokumentu.');
        }
        return [
            'type' => $type, 'language' => $language,
            'title' => $type === 'page' ? 'Nová stránka' : 'Nový článek',
            'slug' => ($type === 'page' ? 'nova-stranka-' : 'novy-clanek-') . bin2hex(random_bytes(5)),
            'summary' => 'Sem napište krátký úvod.',
            'body' => ContentBody::encode([
                ['type' => 'text', 'heading' => 'Na začátek', 'body' => 'Napište první odstavec přímo na stránce.'],
                ['type' => 'list', 'heading' => 'Co vás čeká', 'body' => "První bod\nDruhý bod"],
                ['type' => 'table', 'heading' => 'Přehled', 'body' => "Název | Hodnota\nDalší řádek | Doplňte"],
                ['type' => 'image', 'heading' => 'Fotografie', 'body' => 'images/batoh.webp'],
            ]),
            'menu_order' => 0, 'visible_in_menu' => false, 'published' => false,
        ];
    }

    public static function fromRevision(array $row): array
    {
        return [
            'type' => $row['type'], 'language' => $row['language'],
            'title' => $row['title'], 'slug' => $row['slug'],
            'summary' => $row['summary'] ?? '',
            'sections' => ContentBody::decode($row['body']),
            'menu_order' => (int) $row['menu_order'],
            'published' => (bool) $row['published'],
            'visible_in_menu' => (bool) $row['visible_in_menu'],
        ];
    }

    public static function snapshot(array $form): array
    {
        $form['body'] = ContentBody::encode($form['sections']);
        unset($form['sections']);
        return $form;
    }

    public static function change(array $form, string $operation, string $field = '',
        string $value = '', ?int $index = null): array
    {
        if ($operation === 'section.image.add' && ProductDetails::imagePath(trim($value))) {
            $position = $index === null ? count($form['sections']) : $index + 1;
            if ($position < 0 || $position > count($form['sections'])) {
                throw new InvalidArgumentException('Neplatné místo pro fotografii.');
            }
            array_splice($form['sections'], $position, 0,
                [['type' => 'image', 'heading' => 'Fotografie', 'body' => trim($value)]]);
            return $form;
        }
        if ($operation === 'set' && in_array($field, self::FIELDS, true)) {
            if (in_array($field, ['published', 'visible_in_menu'], true)) {
                if (!in_array($value, ['0', '1'], true) ||
                    ($field === 'visible_in_menu' && $form['type'] !== 'page')) {
                    throw new InvalidArgumentException('Neplatné nastavení publikování nebo menu.');
                }
                $form[$field] = $value === '1';
            } elseif ($field === 'menu_order') {
                if ($form['type'] !== 'page' || preg_match('/^[0-9]+$/D', $value) !== 1 ||
                    (int) $value > 65535) {
                    throw new InvalidArgumentException('Pořadí v menu musí být celé číslo od 0 do 65535.');
                }
                $form[$field] = (int) $value;
            } else {
                $form[$field] = trim($value);
                if ($field === 'slug' && $form[$field] === '') {
                    $form[$field] = Slugger::fromTitle($form['title']);
                }
                if ($field === 'title' && preg_match('/^(nova-stranka|novy-clanek)-[a-f0-9]{10}$/D', $form['slug']) === 1) {
                    $form['slug'] = Slugger::fromTitle($form['title']);
                }
            }
            return $form;
        }

        if ($operation === 'section.add') {
            $position = $index === null ? count($form['sections']) : $index + 1;
            if ($position < 0 || $position > count($form['sections'])) {
                throw new InvalidArgumentException('Neplatné místo pro nový blok.');
            }
            array_splice($form['sections'], $position, 0, [self::defaults($value)]);
            return $form;
        }

        if (!in_array($operation, ['section.set', 'section.remove', 'section.move'], true) ||
            $index === null || !isset($form['sections'][$index])) {
            throw new InvalidArgumentException('Tento blok neexistuje. Obnovte stránku.');
        }
        if ($operation === 'section.set') {
            if ($field === 'section_type') {
                $form['sections'][$index] = self::defaults($value);
            } elseif (in_array($field, ['section_heading', 'section_body'], true)) {
                $form['sections'][$index][substr($field, 8)] = trim($value);
            } else {
                throw new InvalidArgumentException('Neznámé pole bloku.');
            }
        } elseif ($operation === 'section.remove') {
            array_splice($form['sections'], $index, 1);
        } else {
            if (!in_array($value, ['up', 'down'], true)) {
                throw new InvalidArgumentException('Neznámý směr přesunutí.');
            }
            $next = $index + ($value === 'up' ? -1 : 1);
            if (!isset($form['sections'][$next])) {
                throw new InvalidArgumentException('Blok už nelze posunout dál.');
            }
            [$form['sections'][$index], $form['sections'][$next]] =
                [$form['sections'][$next], $form['sections'][$index]];
        }
        return $form;
    }

    private static function defaults(string $type): array
    {
        $defaults = [
            'text' => ['Nový odstavec', 'Napište text.'],
            'list' => ['Nový seznam', "První bod\nDruhý bod"],
            'table' => ['Nová tabulka', 'Název | Hodnota'],
            'image' => ['Fotografie', 'images/batoh.webp'],
        ];
        if (!isset($defaults[$type])) {
            throw new InvalidArgumentException('Neznámý typ bloku.');
        }
        return ['type' => $type, 'heading' => $defaults[$type][0], 'body' => $defaults[$type][1]];
    }
}
