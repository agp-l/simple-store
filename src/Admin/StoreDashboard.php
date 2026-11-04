<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use MeekroDB;
use RuntimeException;

/** Read-only catalogue statistics. Prices are CZK integers; translations never multiply stock. */
final class StoreDashboard
{
    public function __construct(private MeekroDB $db) {}

    public function load(string $language, bool $includeDrafts = false): array
    {
        $tables = ['shop_product_revisions', 'shop_product_inventory', 'shop_product_sales',
            'shop_product_variant_settings', 'shop_product_variants'];
        if ((int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s,%s)', ...$tables) !== 5) {
            throw new RuntimeException('Pro přehled produktů nejprve aktualizuj databázi v sekci Databáze.');
        }
        // A single consistent snapshot, without locks, schema changes or remote supplier requests.
        $this->db->startTransaction();
        try {
            $products = $this->db->query('SELECT product_key,language,published,price_czk,stock_status
                FROM shop_product_revisions WHERE active_product_key IS NOT NULL ORDER BY product_key,language');
            $inventory = $this->db->query('SELECT product_key,available_quantity FROM shop_product_inventory');
            $sales = $this->db->query('SELECT product_key,mode,supplier_price_czk,variant_prices_json FROM shop_product_sales');
            $settings = $this->db->query('SELECT product_key,enabled FROM shop_product_variant_settings');
            $variants = $this->db->query('SELECT product_key,variant_key,enabled,price_czk,available_quantity,stock_status FROM shop_product_variants');
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return self::summarize($products, $inventory, $sales, $settings, $variants, $language, $includeDrafts);
    }

    public static function summarize(array $products, array $inventory, array $sales, array $settings,
        array $variants, string $language, bool $includeDrafts = false): array
    {
        $chosen = [];
        foreach ($products as $product) {
            $key = $product['product_key'];
            // Prefer a published translation, then the installation's language, then a stable language order.
            $rank = [(int) $product['published'], (int) ($product['language'] === $language)];
            $old = $chosen[$key] ?? null;
            if ($old === null || $rank > [(int) $old['published'], (int) ($old['language'] === $language)] ||
                ($rank === [(int) $old['published'], (int) ($old['language'] === $language)] && strcmp($product['language'], $old['language']) < 0)) {
                $chosen[$key] = $product;
            }
        }
        $inventory = array_column($inventory, null, 'product_key');
        $sales = array_column($sales, null, 'product_key');
        $settings = array_column($settings, null, 'product_key');
        $byProduct = [];
        foreach ($variants as $variant) if (!empty($variant['enabled'])) $byProduct[$variant['product_key']][] = $variant;
        $empty = array_fill_keys(['products','in_stock','on_order','unavailable','units','available_units',
            'quantity','stock_retail','stock_cost','stock_missing','product_retail','product_cost','product_missing',
            'unit_retail','unit_cost','unit_missing','need_units','need_cost','need_missing','empty_variants'], 0);
        $groups = ['own'=>$empty, 'assisted'=>$empty];
        $published = 0; $drafts = 0; $invalidPrices = 0;
        foreach ($chosen as $key => $product) {
            if (!empty($product['published'])) $published++; else $drafts++;
            if (!$includeDrafts && empty($product['published'])) continue;
            $sale = $sales[$key] ?? [];
            $mode = ($sale['mode'] ?? 'own') === 'assisted' ? 'assisted' : 'own';
            $group =& $groups[$mode];
            $group['products']++;
            $overrides = json_decode($sale['variant_prices_json'] ?? '{}', true);
            if (!is_array($overrides)) { $overrides = []; $invalidPrices++; }
            $variantMode = !empty($settings[$key]['enabled']);
            $items = $variantMode ? ($byProduct[$key] ?? []) : [[
                'variant_key'=>'', 'price_czk'=>$product['price_czk'],
                'stock_status'=>$product['stock_status'], 'available_quantity'=>$inventory[$key]['available_quantity'] ?? 0,
            ]];
            $retail = []; $costs = []; $states = [];
            foreach ($items as $item) {
                $price = (int) ($item['price_czk'] ?? $product['price_czk']);
                $override = $overrides[$item['variant_key']] ?? null;
                $cost = self::cost($override ?? ($sale['supplier_price_czk'] ?? null));
                $quantity = max(0, (int) $item['available_quantity']);
                $available = $item['stock_status'] === 'in_stock' && $quantity > 0;
                $states[] = $available ? 'in_stock' : ($item['stock_status'] === 'on_order' ? 'on_order' : 'out_of_stock');
                $group['units']++;
                $group['available_units'] += (int) $available;
                $group['unit_retail'] += $price;
                $retail[] = $price;
                if ($cost === null) $group['unit_missing']++;
                else { $group['unit_cost'] += $cost; $costs[] = $cost; }
                if ($mode === 'own') {
                    // Inventory is the remaining free quantity (reservations are already deducted).
                    $group['quantity'] += $quantity;
                    $group['stock_retail'] += $quantity * $price;
                    if ($cost === null) $group['stock_missing'] += $quantity;
                    else $group['stock_cost'] += $quantity * $cost;
                }
                // External availability does not represent pieces in our warehouse.
                if ($mode === 'assisted' || $quantity === 0) {
                    $group['need_units']++;
                    if ($cost === null) $group['need_missing']++;
                    else $group['need_cost'] += $cost;
                }
            }
            if ($items === []) $group['empty_variants']++;
            $group[in_array('in_stock', $states, true) ? 'in_stock' : (in_array('on_order', $states, true) ? 'on_order' : 'unavailable')]++;
            $group['product_retail'] += $retail === [] ? 0 : min($retail);
            if ($costs === []) $group['product_missing']++;
            else $group['product_cost'] += min($costs);
            unset($group);
        }
        $groups['total'] = $empty;
        foreach (array_keys($empty) as $field) $groups['total'][$field] = $groups['own'][$field] + $groups['assisted'][$field];
        return ['groups'=>$groups, 'published'=>$published, 'drafts'=>$drafts, 'include_drafts'=>$includeDrafts,
            'invalid_prices'=>$invalidPrices, 'language'=>$language];
    }

    private static function cost(mixed $value): ?int
    {
        $price = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>9999999]]);
        return $price === false ? null : $price;
    }
}
