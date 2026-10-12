<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Explicit, previewed initial inventory for products that have options but no variants yet. */
final class ProductVariantBulkCreate
{
    public function __construct(private MeekroDB $db, private string $defaultLanguage = 'cs') {}

    public static function quantity(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^[0-9]{1,7}$/D', trim($value)) !== 1 || (int) $value > 1000000) {
            throw new InvalidArgumentException('Počet nových kusů musí být celé číslo od 0 do 1 000 000.');
        }
        return (int) $value;
    }

    public function preview(mixed $quantity): array
    {
        $quantity = self::quantity($quantity);
        $rows = $this->rows();
        return $this->plan($rows) + ['quantity' => $quantity, 'fingerprint' => self::fingerprint($rows)];
    }

    public function apply(array $preview): array
    {
        $quantity = self::quantity((string) ($preview['quantity'] ?? ''));
        $variants = new ProductVariantRepository($this->db);
        // DDL must run before the transaction (MySQL implicitly commits it).
        $variants->install();
        $this->db->startTransaction();
        try {
            $rows = $this->rows(true);
            if (!is_string($preview['fingerprint'] ?? null) || !hash_equals($preview['fingerprint'], self::fingerprint($rows))) {
                throw new InvalidArgumentException('Produkty, varianty nebo sklad se od náhledu změnily. Zobraz nový náhled.');
            }
            $plan = $this->plan($rows);
            $targets = array_fill_keys(array_column($plan['products'], 'key'), true);
            foreach ($rows as $row) {
                if (!isset($targets[$row['product_key']])) continue;
                unset($targets[$row['product_key']]);
                $items = ProductVariants::fromOptions(ProductDetails::decode($row['details_json'], $row['sizes'])['options']);
                foreach ($items as &$item) $item['quantity'] = $quantity;
                unset($item);
                $variants->apply($row['product_key'], ['enabled' => true, 'items' => $items, 'expected' => $row['variant_inventory']['expected']]);
            }
            $this->db->commit();
            return ['products' => count($plan['products']), 'variants' => $plan['variant_count']];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function plan(array $rows): array
    {
        $products = []; $skipped = []; $seen = []; $count = 0;
        foreach ($rows as $row) {
            $key = $row['product_key'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $entry = ['key' => $key, 'name' => $row['name'], 'language' => $row['language'], 'slug' => $row['slug']];
            $state = $row['variant_inventory'];
            $options = ProductDetails::decode($row['details_json'], $row['sizes'])['options'];
            $reason = null;
            if ($state['enabled'] || $state['items'] !== []) $reason = 'Varianty už existují; jejich nastavení a kusy se zachovají.';
            elseif ($options === []) $reason = 'Produkt nemá volby velikosti, barvy ani jiné vlastnosti.';
            elseif ($row['reserved']) $reason = 'Produkt má kusy rezervované pro objednávky. Nejdřív je vyřiď nebo stornuj.';
            if ($reason === null) {
                try { $items = ProductVariants::fromOptions($options); }
                catch (InvalidArgumentException $error) { $reason = $error->getMessage(); }
            }
            if ($reason !== null) { $skipped[] = $entry + ['reason' => $reason]; continue; }
            $products[] = $entry + ['variants' => count($items), 'previous_quantity' => (int) $row['available_quantity']];
            $count += count($items);
        }
        return ['products' => $products, 'skipped' => $skipped, 'variant_count' => $count];
    }

    private function rows(bool $lock = false): array
    {
        if ($lock) {
            foreach ($this->db->query('SELECT DISTINCT product_key FROM shop_product_revisions WHERE active_product_key IS NOT NULL ORDER BY product_key') as $row) {
                (new ProductStockRepository($this->db))->ensure($row['product_key']);
                $this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE', $row['product_key']);
            }
        }
        $rows = $this->db->query('SELECT r.id,r.product_key,r.language,r.revision_number,r.slug,r.name,r.details_json,r.sizes,
            COALESCE(i.available_quantity,0) AS available_quantity
            FROM shop_product_revisions r LEFT JOIN shop_product_inventory i ON i.product_key=r.product_key
            WHERE r.active_product_key IS NOT NULL ORDER BY r.product_key, (r.language=%s) DESC, r.language' . ($lock ? ' FOR UPDATE' : ''), $this->defaultLanguage);
        $states = (new ProductVariantRepository($this->db))->snapshots(array_values(array_unique(array_column($rows, 'product_key'))), $lock);
        $reserved = array_fill_keys(array_column($this->db->query('SELECT DISTINCT product_key FROM shop_order_stock_reservations WHERE state=%s ORDER BY product_key' . ($lock ? ' FOR UPDATE' : ''), 'reserved'), 'product_key'), true);
        foreach ($rows as &$row) {
            $row['variant_inventory'] = $states[$row['product_key']] ?? ProductVariantRepository::empty();
            $row['reserved'] = isset($reserved[$row['product_key']]);
        }
        unset($row);
        return $rows;
    }

    private static function fingerprint(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
