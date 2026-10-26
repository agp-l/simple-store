<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use MeekroDB;

/** Admin-only index of selected sellers and saved sources, across languages and sale modes. */
final class PurchasePartnerUsage
{
    public function __construct(private MeekroDB $db) {}

    /** @return array<int, array<string, bool>> Product keys grouped by partner ID. */
    public function products(array $partners, bool $lock = false): array
    {
        $usage = []; $byKey = []; $byHost = [];
        foreach ($partners as $partner) {
            $id = (int) $partner['id']; $usage[$id] = [];
            if (!empty($partner['partner_key'])) $byKey[$partner['partner_key']] = $id;
            $host = ProductPartnerAssignment::host((string) ($partner['website_url'] ?? ''));
            if ($host !== null) $byHost[$host][] = $id;
        }
        if ($usage === []) return [];
        $suffix = $lock ? ' FOR UPDATE' : '';
        // Deleted product revisions and immutable order snapshots are not live dependencies.
        $current = array_fill_keys($this->db->queryFirstColumn('SELECT product_key FROM shop_product_revisions
            WHERE active_product_key IS NOT NULL ORDER BY product_key, language' . $suffix), true);
        foreach ($this->db->query('SELECT product_key,partner_id FROM shop_product_sales ORDER BY product_key' . $suffix) as $sale) {
            $id = (int) $sale['partner_id'];
            if (isset($usage[$id], $current[$sale['product_key']])) $usage[$id][$sale['product_key']] = true;
        }
        $tables = $this->db->queryFirstColumn('SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)',
            'shop_product_supplier_links', 'shop_product_variant_supplier_links');
        foreach ($tables as $table) {
            $variant = $table === 'shop_product_variant_supplier_links';
            $rows = $this->db->query('SELECT product_key,url' . ($variant ? ',partner_key' : '') . ' FROM ' . $table .
                ($variant ? ' WHERE removed=0' : '') . ' ORDER BY product_key' . $suffix);
            foreach ($rows as $link) {
                $key = $link['product_key'];
                if (!isset($current[$key])) continue;
                // Include explicit identity even when a website edit made the old URL invalid.
                $id = $byKey[$link['partner_key'] ?? ''] ?? null;
                if ($id !== null) $usage[$id][$key] = true;
                $host = ProductPartnerAssignment::host($link['url']);
                foreach ($byHost[$host ?? ''] ?? [] as $hostId) $usage[$hostId][$key] = true;
            }
        }
        return $usage;
    }
}
