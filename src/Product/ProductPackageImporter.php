<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Media\MediaLibrary;
use Throwable;

/** Re-encode package photos first, then atomically save a revision and its private supplier links. */
final class ProductPackageImporter
{
    public function __construct(private ProductRepository $products, private CategoryRepository $categories,
        private MediaLibrary $media, private array $languages) {}

    public function import(ProductZipArchive $archive, string $folder, string $newKey, string $mode = 'skip'): array
    {
        $data = ProductImport::decode($archive->read($folder . '/product.json', ProductImport::LIMIT));
        if (!in_array($data['language'], $this->languages, true)) throw new InvalidArgumentException('Neplatný jazyk produktu.');
        $resolved = ProductZipConflictPolicy::resolve($this->products, $data, $newKey, $mode);
        if (isset($resolved['skipped'])) return $resolved;
        ['data' => $data, 'key' => $key, 'current' => $current] = $resolved;
        ProductImport::fields($data, $current);
        $images = $archive->images($folder);
        $references = [];
        if (isset($data['product']['image_path'])) $references[] = $data['product']['image_path'];
        foreach ($data['product']['gallery'] ?? [] as $image) $references[] = $image;
        foreach ($data['product']['sections'] ?? [] as $section) if (($section['type'] ?? '') === 'image') $references[] = $section['body'] ?? '';
        foreach ($data['product']['variants']['items'] ?? [] as $variant) if (($variant['image_path'] ?? '') !== '') $references[] = $variant['image_path'];
        foreach ($references as $image) {
            if (!is_string($image) || !in_array($image, $images, true)) throw new InvalidArgumentException('Fotografie chybí v ZIP images/: ' . (is_string($image) ? $image : 'neplatná cesta'));
        }
        // Validate all text and update identity before doing costly image work.
        $probe = self::mapImages($data, array_fill_keys($images, 'images/product-placeholder.png'));
        $fields = ProductImport::fields($probe, $current);
        if ($this->categories->findForAdmin($data['language'], $fields['category_path']) === null) throw new InvalidArgumentException('Kategorie neexistuje: ' . $fields['category_path']);
        $paths = [];
        try {
            foreach ($images as $image) {
                $temp = tmpfile();
                if ($temp === false) throw new \RuntimeException('Nelze vytvořit dočasný soubor fotografie.');
                try {
                    $bytes = $archive->read($folder . '/' . $image, 12 * 1024 * 1024);
                    if (fwrite($temp, $bytes) !== strlen($bytes)) throw new \RuntimeException('Nedostatek místa pro fotografii.');
                    unset($bytes);
                    $paths[$image] = $this->media->storeFile('product', $key ?? $newKey, stream_get_meta_data($temp)['uri'], basename($image));
                } finally { fclose($temp); }
            }
            $mapped = self::mapImages($data, $paths);
            if ($current === null && !isset($mapped['product']['image_path']) && $paths !== []) $mapped['product']['image_path'] = reset($paths);
            if (!array_key_exists('gallery', $mapped['product']) && $paths !== []) {
                $main = $mapped['product']['image_path'] ?? $current['image_path'] ?? '';
                $existing = $current === null ? [] : ProductDetails::decode($current['details_json'])['gallery'];
                $mapped['product']['gallery'] = array_values(array_unique(array_filter(array_merge($existing, array_values($paths)), static fn (string $path): bool => $path !== $main)));
            }
            $fields = ProductImport::fields($mapped, $current);
            $saved = $this->products->saveRevision($fields, $key, $data['revision'] ?? null,
                ProductImport::suppliers($data), $key === null ? $newKey : null);
            return $saved + ['name' => $fields['name'], 'slug' => $fields['slug']];
        } catch (Throwable $error) {
            $this->media->removeNew(array_values($paths));
            throw $error;
        }
    }

    private static function mapImages(array $data, array $paths): array
    {
        if (isset($data['product']['image_path'])) $data['product']['image_path'] = $paths[$data['product']['image_path']] ?? $data['product']['image_path'];
        if (isset($data['product']['gallery'])) $data['product']['gallery'] = array_map(static fn (string $path): string => $paths[$path] ?? $path, $data['product']['gallery']);
        if (isset($data['product']['sections'])) foreach ($data['product']['sections'] as &$section) {
            if (($section['type'] ?? '') === 'image') $section['body'] = $paths[$section['body']] ?? $section['body'];
        }
        unset($section);
        if (isset($data['product']['variants']['items'])) foreach ($data['product']['variants']['items'] as &$variant) {
            if (($variant['image_path'] ?? '') !== '') $variant['image_path'] = $paths[$variant['image_path']] ?? $variant['image_path'];
        }
        unset($variant);
        return $data;
    }
}
