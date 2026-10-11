<?php
declare(strict_types=1);
namespace SimpleStore\Product;

/** SQL-paged catalog; weight filtering reads existing structured specifications. */
final class CatalogSelection
{
    public function __construct(private ProductRepository $products) {}

    public function page(string $language, ?string $category, string $search, string $sort,
        int $offset, string $seed, array $tips, ?int $maxWeight): array
    {
        if ($maxWeight === null) {
            $batch = $this->products->publishedPage($language, $category, $search, $sort, $offset, 12, $seed, $tips);
        } else {
            $accepted = 0; $cursor = 0; $items = [];
            do {
                $part = $this->products->publishedPage($language, $category, $search, $sort, $cursor, 48, $seed, $tips);
                foreach ($part['items'] as $row) {
                    $weight = ProductWeight::grams($row['details_json'] ?? null);
                    if ($weight === null || $weight > $maxWeight) continue;
                    if ($accepted++ < $offset) continue;
                    $items[] = $row;
                    if (count($items) === 13) break 2;
                }
                $cursor = $part['nextOffset'];
            } while ($cursor !== null);
            $batch = ['items' => array_slice($items, 0, 12), 'nextOffset' => count($items) > 12 ? $offset + 12 : null];
        }
        foreach ($batch['items'] as &$row) {
            $row['is_tip'] = in_array($row['product_key'], $tips, true);
            $row['weight_grams'] = ProductWeight::grams($row['details_json'] ?? null);
        }
        unset($row);
        return $batch;
    }
}
