<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;

/** Resolve a public deep link without silently choosing another size. */
final class ProductVariantSelection
{
    public static function resolve(array $product, mixed $requested): ?array
    {
        if ($requested === null) return null;
        if (!is_string($requested) || preg_match('/^[a-f0-9]{32}$/D', $requested) !== 1) {
            throw new InvalidArgumentException('Neplatná varianta.', 400);
        }
        if ($product['variant_inventory']['enabled'] ?? false) {
            foreach ($product['variant_inventory']['items'] as $variant) {
                if ($variant['id'] === $requested && $variant['enabled']) return $variant;
            }
        }
        throw new InvalidArgumentException('Varianta neexistuje.', 404);
    }
}
