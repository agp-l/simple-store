<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use SimpleStore\Category\CategoryPath;

/** Turn one small on-page change into a complete, validated product snapshot. */
final class ProductInlineEditor
{
    private const FIELDS = ['name', 'slug', 'brand', 'summary', 'description',
        'category_path', 'price_czk', 'image_path', 'stock_status', 'published'];
    private const GROUPS = [
        'section' => ['section_type', 'section_heading', 'section_body'],
        'spec' => ['spec_name', 'spec_value'],
        'option' => ['option_name', 'option_values'],
    ];

    public static function starter(string $language, string $category): array
    {
        return [
            'language' => $language,
            'name' => 'Nový produkt',
            'slug' => 'novy-produkt-' . bin2hex(random_bytes(5)),
            'brand' => 'Značka',
            'summary' => 'Krátce představte produkt zákazníkovi.',
            'description' => 'Napište, na jaké cesty se produkt hodí a proč jste jej vybrali.',
            'category_path' => $category,
            'price_czk' => '1',
            'image_path' => 'images/batoh.webp',
            'stock_status' => 'out_of_stock',
            'published' => false,
            'sizes' => '',
            'gallery' => '',
            'option_name' => ['Varianta'],
            'option_values' => ['Standardní'],
            'spec_name' => ['Hmotnost', 'Materiál'],
            'spec_value' => ['Doplňte hmotnost', 'Doplňte materiál'],
            'section_type' => ['text', 'list', 'table', 'image'],
            'section_heading' => ['Proč si ho vzít s sebou', 'Vlastnosti', 'Podrobnosti', 'Z terénu'],
            'section_body' => [
                'Sem napište první odstavec. Text můžete upravit přímo na této stránce.',
                "První vlastnost\nDruhá vlastnost",
                "Parametr | Hodnota\nDalší parametr | Doplňte",
                'images/batoh.webp',
            ],
        ];
    }

    public static function fromRevision(array $product): array
    {
        $details = ProductDetails::decode($product['details_json'] ?? null, $product['sizes'] ?? '');
        return [
            'language' => $product['language'], 'name' => $product['name'],
            'slug' => $product['slug'], 'brand' => $product['brand'],
            'summary' => $product['summary'], 'description' => $product['description'],
            'category_path' => CategoryPath::fromProduct($product),
            'price_czk' => (string) $product['price_czk'],
            'image_path' => $product['image_path'], 'stock_status' => $product['stock_status'],
            'published' => (bool) $product['published'], 'sizes' => '',
            'gallery' => implode("\n", $details['gallery']),
            'option_name' => array_column($details['options'], 'name'),
            'option_values' => array_map(static fn (array $row): string => implode("\n", $row['values']), $details['options']),
            'spec_name' => array_column($details['specifications'], 'name'),
            'spec_value' => array_column($details['specifications'], 'value'),
            'section_type' => array_column($details['sections'], 'type'),
            'section_heading' => array_column($details['sections'], 'heading'),
            'section_body' => array_column($details['sections'], 'body'),
        ];
    }

    public static function change(array $form, string $operation, string $field = '',
        string $value = '', ?int $index = null): array
    {
        if ($operation === 'set' && in_array($field, self::FIELDS, true)) {
            if ($field === 'published' && !in_array($value, ['0', '1'], true)) {
                throw new InvalidArgumentException('Neplatný stav publikování.');
            }
            $form[$field] = $field === 'published' ? $value === '1' : trim($value);
            return $form;
        }
        if ($operation === 'gallery.add' || $operation === 'gallery.set' || $operation === 'gallery.remove') {
            if ($operation !== 'gallery.remove' && !ProductDetails::imagePath(trim($value))) {
                throw new InvalidArgumentException('Fotografie potřebuje cestu images/… nebo HTTPS adresu.');
            }
            $gallery = $form['gallery'] === '' ? [] : explode("\n", $form['gallery']);
            if ($operation === 'gallery.add') {
                $gallery[] = $value;
            } else {
                self::checkIndex($index, count($gallery));
                if ($operation === 'gallery.set') {
                    $gallery[$index] = $value;
                } else {
                    array_splice($gallery, $index, 1);
                }
            }
            $form['gallery'] = implode("\n", $gallery);
            return $form;
        }
        if ($operation === 'section.image.add' && ProductDetails::imagePath(trim($value))) {
            $position = $index === null ? count($form['section_type']) : $index + 1;
            if ($position < 0 || $position > count($form['section_type'])) {
                throw new InvalidArgumentException('Neplatné místo pro fotografii.');
            }
            foreach (['section_type' => 'image', 'section_heading' => 'Fotografie',
                'section_body' => trim($value)] as $fieldName => $item) {
                array_splice($form[$fieldName], $position, 0, [$item]);
            }
            return $form;
        }
        foreach (self::GROUPS as $group => $keys) {
            if ($operation === $group . '.add') {
                $defaults = self::defaults($group, $value, count($form[$keys[0]]));
                // Index -1 inserts at the beginning; no index appends the block.
                $position = $index === null ? count($form[$keys[0]]) : $index + 1;
                if ($position < 0 || $position > count($form[$keys[0]])) {
                    throw new InvalidArgumentException('Neplatné místo pro nový blok.');
                }
                foreach ($keys as $i => $key) {
                    array_splice($form[$key], $position, 0, [$defaults[$i]]);
                }
                return $form;
            }
            if (!str_starts_with($operation, $group . '.')) {
                continue;
            }
            self::checkIndex($index, count($form[$keys[0]]));
            if ($operation === $group . '.set' && in_array($field, $keys, true)) {
                if ($field === 'section_type') {
                    $defaults = self::defaults('section', $value, $index);
                    $form['section_body'][$index] = $defaults[2];
                }
                $form[$field][$index] = trim($value);
                return $form;
            }
            if ($operation === $group . '.remove') {
                foreach ($keys as $key) {
                    array_splice($form[$key], $index, 1);
                }
                return $form;
            }
            if ($operation === $group . '.move' && in_array($value, ['up', 'down'], true)) {
                $next = $index + ($value === 'up' ? -1 : 1);
                self::checkIndex($next, count($form[$keys[0]]));
                foreach ($keys as $key) {
                    [$form[$key][$index], $form[$key][$next]] = [$form[$key][$next], $form[$key][$index]];
                }
                return $form;
            }
        }
        throw new InvalidArgumentException('Neznámá úprava produktu.');
    }

    private static function defaults(string $group, string $type, int $count): array
    {
        if ($group === 'spec') return ['Nový parametr', 'Doplňte hodnotu'];
        if ($group === 'option') return ['Nový výběr ' . ($count + 1), 'Možnost'];
        $blocks = [
            'text' => ['Nový odstavec', 'Napište text produktu.'],
            'list' => ['Vlastnosti', "První vlastnost\nDruhá vlastnost"],
            'table' => ['Parametry', 'Název | Hodnota'],
            'image' => ['Fotografie', 'images/batoh.webp'],
        ];
        if (!isset($blocks[$type])) throw new InvalidArgumentException('Neznámý typ bloku.');
        return [$type, ...$blocks[$type]];
    }

    private static function checkIndex(?int $index, int $count): void
    {
        if ($index === null || $index < 0 || $index >= $count) {
            throw new InvalidArgumentException('Tento blok už neexistuje. Obnovte stránku.');
        }
    }
}
