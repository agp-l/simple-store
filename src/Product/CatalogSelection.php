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
        if (in_array($sort, ['weight-asc', 'weight-desc'], true)) {
            $batch = $this->weightPage($language, $category, $search, $sort, $offset, $maxWeight);
        } elseif ($maxWeight === null) {
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
    /** Sort the entire matching selection before slicing, not just visible cards. */
    private function weightPage(string $language, ?string $category, string $search,
        string $sort, int $offset, ?int $maxWeight): array
    {
        $items = []; $cursor = 0;
        do {
            $part = $this->products->publishedPage($language, $category, $search, 'default', $cursor, 48);
            foreach ($part['items'] as $row) {
                $row['weight_grams'] = ProductWeight::grams($row['details_json'] ?? null);
                if ($maxWeight !== null && ($row['weight_grams'] === null || $row['weight_grams'] > $maxWeight)) continue;
                $items[] = $row;
            }
            $cursor = $part['nextOffset'];
        } while ($cursor !== null);
        usort($items, static function (array $a, array $b) use ($sort): int {
            $x = $a['weight_grams']; $y = $b['weight_grams'];
            if (($x === null) !== ($y === null)) return $x === null ? 1 : -1;
            $comparison = $x <=> $y;
            if ($comparison !== 0) return $sort === 'weight-desc' ? -$comparison : $comparison;
            return strcmp($a['product_key'], $b['product_key']);
        });
        return ['items' => array_slice($items, $offset, 12),
            'nextOffset' => count($items) > $offset + 12 ? $offset + 12 : null];
    }

}
