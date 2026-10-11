<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;

/** Parse browser values before applying a version-checked inventory change. */
final class ProductVariantForm
{
    public static function apply(array $snapshot, array $changes): array
    {
        if ($changes === [] || count($changes) > ProductVariants::LIMIT) throw new InvalidArgumentException('Vyplň změny variant.');
        $indexed = array_column($snapshot['items'], null, 'id');
        $seen = [];
        foreach ($changes as $change) {
            if (!is_array($change) || !is_string($change['variant_id'] ?? null) || !isset($indexed[$change['variant_id']]) || isset($seen[$change['variant_id']])) throw new InvalidArgumentException('Neplatný výběr varianty.');
            $id = $change['variant_id']; $seen[$id] = true;
            foreach (['sku', 'image_path', 'stock_status'] as $field) {
                if (!is_string($change[$field] ?? null)) throw new InvalidArgumentException('Neplatné údaje varianty.');
                $indexed[$id][$field] = trim($change[$field]);
            }
            $quantity = $change['quantity'] ?? '';
            $price = $change['price_czk'] ?? '';
            if (!is_string($quantity) || !ctype_digit($quantity) || strlen($quantity) > 7 || !is_string($price) || strlen($price) > 8 || ($price !== '' && !ctype_digit($price))) throw new InvalidArgumentException('Počet i cena musí být celá čísla.');
            $indexed[$id]['quantity'] = (int) $quantity;
            $indexed[$id]['price_czk'] = $price === '' ? null : (int) $price;
            $indexed[$id]['enabled'] = ($change['enabled'] ?? '') === '1';
        }
        $snapshot['items'] = ProductVariants::normalize(array_values($indexed));
        return $snapshot;
    }
}
