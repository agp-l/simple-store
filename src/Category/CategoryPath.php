<?php
declare(strict_types=1);

namespace SimpleStore\Category;

use InvalidArgumentException;

/** Category paths are stable, language-independent IDs such as spani/spacaky. */
final class CategoryPath
{
    private const OLD_BACKPACKS = [
        'do-25' => 'batohy-do-25-l',
        '25-50' => 'batohy-25-50-l',
        'nad-50' => 'batohy-nad-50-l',
        'prislusenstvi' => 'prislusenstvi-k-batohum',
    ];

    public static function valid(string $path): bool
    {
        return strlen($path) <= 500 &&
            preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$~D', $path) === 1;
    }

    public static function parent(string $path): string
    {
        $lastSlash = strrpos($path, '/');
        return $lastSlash === false ? '' : substr($path, 0, $lastSlash);
    }

    public static function contains(string $parent, string $path): bool
    {
        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    public static function fromProduct(array $product): string
    {
        $root = (string) ($product['category'] ?? '');
        $child = (string) ($product['subcategory'] ?? '');
        // Older snapshots keep their original values; translate only when reading them.
        if ($root === 'stany' || $root === 'spacaky') {
            return 'spani/' . $root;
        }
        if ($root === 'batohy' && isset(self::OLD_BACKPACKS[$child])) {
            $child = self::OLD_BACKPACKS[$child];
        }
        return $child === '' ? $root : $root . '/' . $child;
    }

    public static function forStorage(string $path): array
    {
        if (!self::valid($path)) {
            throw new InvalidArgumentException('Neplatná cesta kategorie.');
        }
        $parts = explode('/', $path, 2);
        return [$parts[0], $parts[1] ?? ''];
    }
}
