<?php
declare(strict_types=1);

namespace SimpleStore\Media;

use InvalidArgumentException;
use SimpleStore\Content\ContentInlineEditor;
use SimpleStore\Product\ProductDetails;
use SimpleStore\Product\ProductInlineEditor;

/** Insert a whole batch into one new content revision. Existing photos stay in history. */
final class MediaAttachment
{
    public static function capacity(array $current, string $type, int $count): void
    {
        if ($count < 1 || $count > 12) throw new InvalidArgumentException('Vyber 1 až 12 fotografií.');
        if ($type === 'product') {
            $details = ProductDetails::decode($current['details_json'] ?? null, $current['sizes'] ?? '');
            $oldMain = $current['image_path'] !== 'images/batoh.webp' ? 1 : 0;
            if (count($details['gallery']) + $oldMain + $count - 1 > 12) {
                throw new InvalidArgumentException('Galerie může mít nejvýše 12 dalších fotografií. Některé nejdřív odeber.');
            }
            return;
        }
        if (!in_array($type, ['page', 'post'], true)) throw new InvalidArgumentException('Neznámý typ obsahu.');
        $sections = ContentInlineEditor::fromRevision($current)['sections'];
        $placeholder = self::placeholder($sections);
        if (count($sections) + $count - ($placeholder === null ? 0 : 1) > 60) {
            throw new InvalidArgumentException('Dokument může mít nejvýše 60 bloků.');
        }
    }

    public static function product(array $current, array $paths): array
    {
        self::capacity($current, 'product', count($paths));
        $form = ProductInlineEditor::fromRevision($current);
        $gallery = $form['gallery'] === '' ? [] : explode("\n", $form['gallery']);
        if ($form['image_path'] !== 'images/batoh.webp') {
            $gallery[] = $form['image_path'];
        }
        $form['image_path'] = array_shift($paths);
        $form['gallery'] = implode("\n", array_merge($gallery, $paths));
        return $form;
    }

    public static function document(array $current, array $paths): array
    {
        self::capacity($current, $current['type'], count($paths));
        $form = ContentInlineEditor::fromRevision($current);
        $placeholder = self::placeholder($form['sections']);
        foreach ($paths as $path) {
            $section = ['type' => 'image', 'heading' => 'Fotografie', 'body' => $path];
            if ($placeholder !== null) {
                $form['sections'][$placeholder] = $section;
                $placeholder = null;
            } else {
                $form['sections'][] = $section;
            }
        }
        return ContentInlineEditor::snapshot($form);
    }

    private static function placeholder(array $sections): ?int
    {
        foreach ($sections as $i => $section) {
            if ($section['type'] === 'image' && $section['body'] === 'images/batoh.webp') return $i;
        }
        return null;
    }
}
