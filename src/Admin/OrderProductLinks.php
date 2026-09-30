<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use MeekroDB;

/** Resolve saved order lines to the current product URL after slug changes. */
final class OrderProductLinks
{
    public function __construct(private MeekroDB $db)
    {
    }

    /** @return array<string, string> product key and language to admin preview URL */
    public function forItems(array $items, string $basePath): array
    {
        $keys = [];
        $languages = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $key = $item['product_key'] ?? null;
            $language = $item['language'] ?? null;
            if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
                !is_string($language) || preg_match('/^[a-z]{2}$/D', $language) !== 1) continue;
            $keys[$key] = true;
            $languages[$language] = true;
        }
        if ($keys === []) return [];

        $holders = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $this->db->query('SELECT product_key, language, slug FROM product_revisions
            WHERE active_product_key IS NOT NULL AND product_key IN (' . $holders . ')',
            ...array_keys($keys));
        $links = [];
        foreach ($rows as $row) {
            $language = (string) $row['language'];
            if (!isset($languages[$language]) ||
                preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', (string) $row['slug']) !== 1) continue;
            $links[$row['product_key'] . ':' . $language] = rtrim($basePath, '/') . '/' .
                $language . '/produkt/' . rawurlencode($row['slug']) . '?edit=1';
        }
        return $links;
    }
}
