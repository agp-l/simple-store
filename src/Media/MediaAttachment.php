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
    public static function capacity(array $current, string $type, int $count,
        ?string $mode = null, ?int $index = null, array $paths = []): void
    {
        if ($count < 1 || $count > 12) throw new InvalidArgumentException('Vyber 1 až 12 fotografií.');
        if ($type === 'product') {
            $details = ProductDetails::decode($current['details_json'] ?? null, $current['sizes'] ?? '');
            $mode ??= 'main-image';
            $gallery = count($details['gallery']);
            if ($mode === 'gallery-set') {
                self::requireIndex($index, $gallery);
                $gallery += $count - 1;
            } elseif ($mode === 'gallery-add') {
                $gallery += $count - ($current['image_path'] === 'images/batoh.webp' ? 1 : 0);
            } elseif ($mode === 'main-image') {
                $gallery += $count - 1 + ($current['image_path'] !== 'images/batoh.webp' ? 1 : 0);
            } elseif ($mode === 'section-image' || $mode === 'section-add-image') {
                if ($mode === 'section-image') self::requireImageSection($details['sections'], $index);
                elseif ($index !== null) self::requireInsertAfter($index, count($details['sections']));
                $added = $count - ($mode === 'section-image' ||
                    ($index === null && self::placeholder($details['sections']) !== null) ? 1 : 0);
                if (count($details['sections']) + $added > 30) {
                    throw new InvalidArgumentException('Popis produktu může mít nejvýše 30 bloků.');
                }
                return;
            } else {
                throw new InvalidArgumentException('Neznámé umístění fotografie.');
            }
            // An existing gallery image can be promoted even when the gallery is full.
            if ($paths !== []) {
                $promote = $mode === 'main-image' ||
                    ($mode === 'gallery-add' && $current['image_path'] === 'images/batoh.webp');
                $main = $promote ? $paths[0] : $current['image_path'];
                $galleryPaths = $details['gallery'];
                if ($mode === 'gallery-set') $galleryPaths[$index] = $paths[0];
                elseif ($mode === 'main-image' && $current['image_path'] !== 'images/batoh.webp') {
                    $galleryPaths[] = $current['image_path'];
                }
                $galleryPaths = array_merge($galleryPaths, array_slice($paths,
                    ($promote || $mode === 'gallery-set') ? 1 : 0));
                $gallery = count(array_unique(array_filter($galleryPaths,
                    static fn (string $path): bool => $path !== $main)));
            }
            if ($gallery > 12) {
                throw new InvalidArgumentException('Galerie může mít nejvýše 12 dalších fotografií. Některé nejdřív odeber.');
            }
            return;
        }
        if (!in_array($type, ['page', 'post'], true)) throw new InvalidArgumentException('Neznámý typ obsahu.');
        $mode ??= 'section-add-image';
        if (!in_array($mode, ['section-image', 'section-add-image'], true)) {
            throw new InvalidArgumentException('Neznámé umístění fotografie.');
        }
        $sections = ContentInlineEditor::fromRevision($current)['sections'];
        if ($mode === 'section-image') self::requireImageSection($sections, $index);
        elseif ($index !== null) self::requireInsertAfter($index, count($sections));
        $replaced = $mode === 'section-image' ||
            ($index === null && self::placeholder($sections) !== null);
        if (count($sections) + $count - ($replaced ? 1 : 0) > 60) {
            throw new InvalidArgumentException('Dokument může mít nejvýše 60 bloků.');
        }
    }

    public static function product(array $current, array $paths,
        string $mode = 'main-image', ?int $index = null): array
    {
        self::capacity($current, 'product', count($paths), $mode, $index, $paths);
        $form = ProductInlineEditor::fromRevision($current);
        $gallery = $form['gallery'] === '' ? [] : explode("\n", $form['gallery']);
        // A new product needs its first real photo even when opened via "add to gallery".
        if ($mode === 'gallery-add' && $form['image_path'] === 'images/batoh.webp') {
            $mode = 'main-image';
        }
        if ($mode === 'main-image') {
            $newMain = array_shift($paths);
            if ($form['image_path'] !== 'images/batoh.webp' && $form['image_path'] !== $newMain) {
                $gallery[] = $form['image_path'];
            }
            $form['image_path'] = $newMain;
            foreach ($form['section_type'] as $position => $sectionType) {
                if ($sectionType === 'image' && $form['section_body'][$position] === 'images/batoh.webp') {
                    $form['section_body'][$position] = $newMain;
                }
            }
            $gallery = array_merge($gallery, $paths);
        } elseif ($mode === 'gallery-add') {
            $gallery = array_merge($gallery, $paths);
        } elseif ($mode === 'gallery-set') {
            $gallery[$index] = array_shift($paths);
            $gallery = array_merge($gallery, $paths);
        } elseif ($mode === 'section-image') {
            $form['section_body'][$index] = array_shift($paths);
            self::insertProductSections($form, $paths, $index + 1);
        } else {
            $placeholder = $index === null ? self::placeholder(ProductDetails::decode(
                $current['details_json'] ?? null, $current['sizes'] ?? ''
            )['sections']) : null;
            if ($placeholder !== null) {
                $form['section_body'][$placeholder] = array_shift($paths);
                $insertAt = $placeholder + 1;
            } else {
                $insertAt = $index === null ? count($form['section_type']) : $index + 1;
            }
            self::insertProductSections($form, $paths, $insertAt);
        }
        $gallery = array_values(array_unique(array_filter($gallery,
            static fn (string $path): bool => $path !== $form['image_path'])));
        $form['gallery'] = implode("\n", $gallery);
        return $form;
    }

    public static function document(array $current, array $paths,
        string $mode = 'section-add-image', ?int $index = null): array
    {
        self::capacity($current, $current['type'], count($paths), $mode, $index);
        $form = ContentInlineEditor::fromRevision($current);
        if ($mode === 'section-image') {
            $form['sections'][$index]['body'] = array_shift($paths);
            $insertAt = $index + 1;
        } else {
            $placeholder = $index === null ? self::placeholder($form['sections']) : null;
            if ($placeholder !== null) {
                $form['sections'][$placeholder]['body'] = array_shift($paths);
                $insertAt = $placeholder + 1;
            } else {
                $insertAt = $index === null ? count($form['sections']) : $index + 1;
            }
        }
        array_splice($form['sections'], $insertAt, 0, array_map(static fn (string $path): array =>
            ['type' => 'image', 'heading' => 'Fotografie', 'body' => $path], $paths));
        return ContentInlineEditor::snapshot($form);
    }

    /** Merge physical files with references in the current revision, without altering stored assets. */
    public static function withUsage(array $current, string $type, array $files): array
    {
        $roles = [];
        if ($type === 'product') {
            $roles[self::originalPath($current['image_path'])]['main'] = true;
            $details = ProductDetails::decode($current['details_json'] ?? null, $current['sizes'] ?? '');
            foreach ($details['gallery'] as $path) $roles[self::originalPath($path)]['gallery'] = true;
            $sections = $details['sections'];
        } else {
            $sections = ContentInlineEditor::fromRevision($current)['sections'];
        }
        foreach ($sections as $section) {
            if ($section['type'] === 'image') $roles[self::originalPath($section['body'])]['section'] = true;
        }
        foreach ($files as &$file) {
            $file['uses'] = array_keys($roles[$file['path']] ?? []);
        }
        unset($file);
        return $files;
    }

    private static function originalPath(string $path): string
    {
        return MediaPath::isAsset($path) && !MediaPath::isManaged($path)
            ? (string) preg_replace('/-(?:card|thumb)(\.(?:webp|jpg|png))$/D', '$1', $path)
            : $path;
    }

    private static function insertProductSections(array &$form, array $paths, int $position): void
    {
        foreach ($paths as $path) {
            foreach (['section_type' => 'image', 'section_heading' => 'Fotografie',
                'section_body' => $path] as $field => $value) {
                array_splice($form[$field], $position, 0, [$value]);
            }
            $position++;
        }
    }

    private static function requireIndex(?int $index, int $count): void
    {
        if ($index === null || $index < 0 || $index >= $count) {
            throw new InvalidArgumentException('Fotografie nebo blok už neexistuje. Obnov stránku.');
        }
    }

    private static function requireImageSection(array $sections, ?int $index): void
    {
        self::requireIndex($index, count($sections));
        if ($sections[$index]['type'] !== 'image') {
            throw new InvalidArgumentException('Vybraný blok už není fotografií. Obnov stránku.');
        }
    }

    private static function requireInsertAfter(int $index, int $count): void
    {
        // The editor uses -1 to insert before its first block.
        if ($index < -1 || $index >= $count) {
            throw new InvalidArgumentException('Místo pro fotografii už neexistuje. Obnov stránku.');
        }
    }

    private static function placeholder(array $sections): ?int
    {
        foreach ($sections as $i => $section) {
            if ($section['type'] === 'image' && $section['body'] === 'images/batoh.webp') return $i;
        }
        return null;
    }
}
