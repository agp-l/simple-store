<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Shared variant identities and inventory; public reads never perform schema writes. */
final class ProductVariantRepository
{
    private ?bool $ready = null;
    public const SCHEMA = [
        'CREATE TABLE IF NOT EXISTS shop_product_variant_settings (
            product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0, version INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS shop_product_variants (
            product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            variant_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            choices_json TEXT NOT NULL, available_quantity INT UNSIGNED NOT NULL DEFAULT 0,
            stock_status VARCHAR(20) NOT NULL DEFAULT \'in_stock\', sku VARCHAR(100) NOT NULL DEFAULT \'\',
            price_czk INT UNSIGNED DEFAULT NULL, image_path VARCHAR(1000) NOT NULL DEFAULT \'\',
            enabled TINYINT(1) NOT NULL DEFAULT 1, position INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (product_key, variant_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS shop_order_variant_reservations (
            order_id BIGINT UNSIGNED NOT NULL, product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            variant_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            quantity INT UNSIGNED NOT NULL, state VARCHAR(12) NOT NULL,
            PRIMARY KEY (order_id, product_key, variant_key), KEY variant_stock (product_key, variant_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];
    public function __construct(private MeekroDB $db) {}
    public function installed(): bool
    {
        return $this->ready ??= (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s)',
            'shop_product_variant_settings', 'shop_product_variants', 'shop_order_variant_reservations') === 3;
    }
    public function install(): void
    {
        foreach (self::SCHEMA as $sql) $this->db->query($sql);
        $this->ready = true;
    }
    public function snapshots(array $keys, bool $lock = false): array
    {
        if ($keys === [] || !$this->installed()) return [];
        foreach ($keys as $key) self::key($key);
        $in = implode(',', array_fill(0, count($keys), '%s'));
        $result = [];
        foreach ($this->db->query('SELECT product_key, enabled, version FROM shop_product_variant_settings WHERE product_key IN (' . $in . ') ORDER BY product_key' . ($lock ? ' FOR UPDATE' : ''), ...$keys) as $row) {
            $result[$row['product_key']] = ['enabled' => (bool) $row['enabled'], 'version' => (int) $row['version'], 'items' => []];
        }
        foreach ($this->db->query('SELECT * FROM shop_product_variants WHERE product_key IN (' . $in . ') ORDER BY product_key, position, variant_key' . ($lock ? ' FOR UPDATE' : ''), ...$keys) as $row) {
            if (!isset($result[$row['product_key']])) continue;
            $result[$row['product_key']]['items'][] = ['id' => $row['variant_key'],
                'choices' => json_decode($row['choices_json'], true, 16, JSON_THROW_ON_ERROR),
                'quantity' => (int) $row['available_quantity'], 'stock_status' => $row['stock_status'],
                'sku' => $row['sku'], 'price_czk' => $row['price_czk'] === null ? null : (int) $row['price_czk'],
                'image_path' => $row['image_path'], 'enabled' => (bool) $row['enabled']];
        }
        foreach ($result as &$snapshot) $snapshot['expected'] = self::fingerprint($snapshot);
        unset($snapshot);
        return $result;
    }
    public function snapshot(string $key, bool $lock = false): array
    {
        return $this->snapshots([$key], $lock)[$key] ?? self::empty();
    }
    /** Call only when the shared stock schema is installed. */
    public function hasReservations(string $key): bool
    {
        self::key($key);
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM shop_order_stock_reservations WHERE product_key=%s AND state=%s', $key, 'reserved') > 0 ||
            ($this->installed() && (int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM shop_order_variant_reservations WHERE product_key=%s AND state=%s', $key, 'reserved') > 0);
    }
    public static function empty(): array
    {
        $value = ['enabled' => false, 'version' => 0, 'items' => []];
        return $value + ['expected' => self::fingerprint($value)];
    }
    private static function fingerprint(array $snapshot): string
    {
        unset($snapshot['expected']);
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    public function save(string $key, array $data): void
    {
        $this->install();
        $this->db->startTransaction();
        try { $this->apply($key, $data); $this->db->commit(); }
        catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }
    /** Caller owns the transaction; take the same product inventory lock as checkout. */
    public function apply(string $key, array $data, array $explicitSupplierVariants = []): void
    {
        self::key($key);
        if (!is_bool($data['enabled'] ?? null) || !is_array($data['items'] ?? null) || !is_string($data['expected'] ?? null)) throw new InvalidArgumentException('Neplatný sklad variant.');
        $rows = ProductVariants::normalize($data['items']);
        (new ProductStockRepository($this->db))->ensure($key);
        $this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE', $key);
        if ($this->db->queryFirstField('SELECT product_key FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL LIMIT 1 FOR UPDATE', $key) === null) throw new InvalidArgumentException('Produkt již neexistuje.');
        $current = $this->snapshot($key, true);
        if (!hash_equals($current['expected'], $data['expected'])) throw new InvalidArgumentException('Varianty nebo sklad se mezitím změnily. Obnov stránku nebo export.');
        if ($data['enabled'] !== $current['enabled'] && $this->hasReservations($key)) {
            throw new InvalidArgumentException('Před změnou režimu skladu vyřiď nebo stornuj objednávky, které zde rezervují kusy.');
        }
        // Omitted identities remain as disabled inventory rows for historical reservations.
        $ids = array_column($rows, 'id');
        foreach ($current['items'] as $old) {
            if (!in_array($old['id'], $ids, true)) { $old['enabled'] = false; $rows[] = $old; }
            else {
                foreach ($rows as $row) if ($row['id'] === $old['id'] && $row['choices'] !== $old['choices']) {
                    throw new InvalidArgumentException('Vlastnosti existující varianty neměň. Vyřaď ji a vytvoř novou kombinaci.');
                }
            }
        }
        ProductVariants::normalize($rows);
        // A deliberate manual price edit opts this product out of managed family pricing.
        $pricesChanged=false;$oldPrices=array_column($current['items'],'price_czk','id');
        foreach($rows as$row)if(array_key_exists($row['id'],$oldPrices)&&$row['price_czk']!==$oldPrices[$row['id']])$pricesChanged=true;
        if($pricesChanged&&(int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','shop_product_supplier_variant_modes')===1)
            $this->db->update('shop_product_supplier_variant_modes',['enabled'=>0],'product_key=%s',$key);
        foreach ($rows as $position => $row) {
            $this->db->query('INSERT INTO shop_product_variants
                (product_key,variant_key,choices_json,available_quantity,stock_status,sku,price_czk,image_path,enabled,position)
                VALUES (%s,%s,%s,%i,%s,%s,%l,%s,%i,%i) ON DUPLICATE KEY UPDATE
                available_quantity=VALUES(available_quantity),stock_status=VALUES(stock_status),sku=VALUES(sku),
                price_czk=VALUES(price_czk),image_path=VALUES(image_path),enabled=VALUES(enabled),position=VALUES(position)',
                $key, $row['id'], json_encode($row['choices'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $row['quantity'], $row['stock_status'], $row['sku'], $row['price_czk'] === null ? 'NULL' : (string) $row['price_czk'], $row['image_path'], (int) $row['enabled'], $position);
        }
        $this->db->query('INSERT INTO shop_product_variant_settings (product_key,enabled,version) VALUES (%s,%i,1)
            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),version=version+1', $key, (int) $data['enabled']);
        // Existing manual/exact links survive; new rows inherit unverified defaults.
        (new ProductVariantSupplierRepository($this->db))->inheritInTransaction($key, $explicitSupplierVariants);
        if ($data['enabled'] !== $current['enabled']) {
            $this->db->query('UPDATE shop_product_inventory SET available_quantity=0 WHERE product_key=%s', $key);
        }
    }
    public static function resolve(array $product, string $id): ?array
    {
        if (!($product['variant_inventory']['enabled'] ?? false)) return null;
        foreach ($product['variant_inventory']['items'] as $variant) {
            if ($variant['id'] === $id && $variant['enabled']) {
                $product['stock_status'] = $variant['stock_status'];
                $product['stock_quantity'] = $variant['quantity'];
                $product['availability_status'] = ProductVariants::availability($variant);
                $product['price_czk'] = $variant['price_czk'] ?? $product['price_czk'];
                $product['image_path'] = $variant['image_path'] ?: $product['image_path'];
                $product['variant_key'] = $id;
                $product['variant_sku'] = $variant['sku'];
                $product['variant_choices'] = $variant['choices'];
                return $product;
            }
        }
        return null;
    }
    private static function key(string $key): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) throw new InvalidArgumentException('Neplatný produkt.');
    }
}
