<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Explicitly convert one duplicated simple product into one variant without deleting its content. */
final class ProductVariantMerge
{
    public const SCHEMA = 'CREATE TABLE IF NOT EXISTS shop_product_redirects (
        language VARCHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_slug VARCHAR(190) NOT NULL,
        target_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        PRIMARY KEY (language,source_slug), KEY target_product (target_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function __construct(private MeekroDB $db) {}

    public function preview(string $target, string $slug, string $language, string $groups, string $expected): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $target) !== 1 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 190 || strlen($groups) > 16000) throw new InvalidArgumentException('Vyplň adresu produktu bez /cs/produkt/ a jeho jednu kombinaci vlastností.');
        $source = $this->db->queryFirstRow('SELECT * FROM shop_product_revisions WHERE language=%s AND active_slug=%s LIMIT 1', $language, $slug);
        if ($source === null || $source['product_key'] === $target) throw new InvalidArgumentException('Vyber jiný existující produkt.');
        $variants = ProductVariants::generate($groups);
        if (count($variants) !== 1) throw new InvalidArgumentException('Převáděný produkt musí představovat právě jednu kombinaci, například Velikost: M a Barva: Modrá.');
        $state = (new ProductVariantRepository($this->db))->snapshot($target);
        if (!$state['enabled'] || !hash_equals($state['expected'], $expected)) throw new InvalidArgumentException('Nejdřív u hlavního produktu vytvoř varianty, nastav jeho skutečné kusy a obnov stránku.');
        if ((new ProductVariantRepository($this->db))->snapshot($source['product_key'])['enabled']) throw new InvalidArgumentException('Převádět lze produkt se společným skladem, nikoli další sadu variant.');
        $rows = $this->sourceRows($source['product_key']);
        $quantity = $this->quantity($source['product_key']);
        $variant = array_replace($variants[0], ['quantity' => $quantity, 'price_czk' => (int) $source['price_czk'], 'image_path' => $source['image_path'], 'stock_status' => $source['stock_status']]);
        ProductVariants::normalize(array_merge($state['items'], [$variant]));
        return ['source_slug' => $slug, 'groups' => $groups, 'source_key' => $source['product_key'], 'source_name' => $source['name'], 'source_expected' => self::fingerprint($rows, $quantity),
            'target_expected' => $expected, 'variant' => $variant];
    }

    public function apply(string $target, array $plan): void
    {
        $source = $plan['source_key'] ?? '';
        if (!is_string($source) || preg_match('/^[a-f0-9]{32}$/D', $source) !== 1 || preg_match('/^[a-f0-9]{32}$/D', $target) !== 1 || $source === $target || !is_array($plan['variant'] ?? null)) throw new InvalidArgumentException('Neplatný náhled převodu.');
        $repository = new ProductVariantRepository($this->db);
        $repository->install();
        $this->db->query(self::SCHEMA);
        $this->db->startTransaction();
        try {
            $keys = [$source, $target]; sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                (new ProductStockRepository($this->db))->ensure($key);
                $this->quantity($key, true);
            }
            $rows = $this->sourceRows($source, true);
            $quantity = $this->quantity($source, true);
            if ($rows === [] || !is_string($plan['source_expected'] ?? null) || !hash_equals(self::fingerprint($rows, $quantity), $plan['source_expected'])) throw new InvalidArgumentException('Převáděný produkt nebo jeho sklad se změnil. Zobraz nový náhled.');
            if ($repository->snapshot($source, true)['enabled'] || (int) $this->db->queryFirstField('SELECT COUNT(*) FROM shop_order_stock_reservations WHERE product_key=%s AND state=%s', $source, 'reserved') > 0) throw new InvalidArgumentException('Nejdřív vyřiď nebo stornuj objednávky rezervující převáděný produkt. Produkt nesmí mít zapnutý sklad variant.');
            $state = $repository->snapshot($target, true);
            if (!$state['enabled'] || !is_string($plan['target_expected'] ?? null) || !hash_equals($state['expected'], $plan['target_expected'])) throw new InvalidArgumentException('Cílové varianty se změnily. Zobraz nový náhled.');
            $state['items'][] = $plan['variant'];
            // Preview quantities are verified against locked source inventory, never added twice.
            if (($plan['variant']['quantity'] ?? null) !== $quantity) throw new InvalidArgumentException('Počet v náhledu neodpovídá skladu.');
            $repository->apply($target, $state);
            foreach ($rows as $row) {
                $this->db->query('UPDATE shop_product_revisions SET active_product_key=NULL, active_slug=NULL WHERE id=%i', $row['id']);
                unset($row['id'], $row['saved_at']);
                $row['revision_number'] = (int) $row['revision_number'] + 1;
                $row['published'] = 0;
                $this->db->insert('shop_product_revisions', $row);
                $this->db->query('INSERT INTO shop_product_redirects (language,source_slug,target_key) VALUES (%s,%s,%s)
                    ON DUPLICATE KEY UPDATE target_key=VALUES(target_key)', $row['language'], $row['slug'], $target);
            }
            $this->db->query('UPDATE shop_product_inventory SET available_quantity=0 WHERE product_key=%s', $source);
            $this->db->query('UPDATE shop_product_redirects SET target_key=%s WHERE target_key=%s', $target, $source);
            $suppliers = new ProductSupplierLinkRepository($this->db);
            if ($suppliers->installed()) {
                $urls = array_column($suppliers->forProduct($target), 'url');
                foreach ($suppliers->forProduct($source) as $link) if (!in_array($link['url'], $urls, true)) {
                    $suppliers->save($target, 0, $link['label'], $link['url']); $urls[] = $link['url'];
                }
            }
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }

    public function redirect(string $language, string $slug): ?string
    {
        if ((int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_product_redirects') !== 1) return null;
        $row = $this->db->queryFirstRow('SELECT p.slug FROM shop_product_redirects r JOIN shop_product_revisions p ON p.product_key=r.target_key AND p.language=r.language
            WHERE r.language=%s AND r.source_slug=%s AND p.active_product_key IS NOT NULL AND p.published=1 LIMIT 1', $language, $slug);
        return $row === null || $row['slug'] === $slug ? null : $row['slug'];
    }
    private function sourceRows(string $key, bool $lock = false): array
    {
        return $this->db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL ORDER BY language' . ($lock ? ' FOR UPDATE' : ''), $key);
    }
    private function quantity(string $key, bool $lock = false): int
    {
        return (int) ($this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s' . ($lock ? ' FOR UPDATE' : ''), $key) ?? 0);
    }
    private static function fingerprint(array $rows, int $quantity): string
    {
        return hash('sha256', json_encode([$rows, $quantity], JSON_THROW_ON_ERROR));
    }
}
