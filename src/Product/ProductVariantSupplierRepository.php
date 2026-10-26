<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Private partner URLs; never a source of physical stock or implicit seller changes. */
final class ProductVariantSupplierRepository
{
    private ?bool $ready = null;
    public function __construct(private MeekroDB $db) {}
    public function installed(): bool
    {
        return $this->ready ??= (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_product_variant_supplier_links') === 1;
    }
    public function forProduct(string $key, bool $lock = false, bool $removed = false): array
    {
        self::key($key);
        if (!$this->installed()) return [];
        $rows = $this->db->query('SELECT * FROM shop_product_variant_supplier_links WHERE product_key=%s' . ($removed ? '' : ' AND removed=0') . ' ORDER BY variant_key,link_key' . ($lock ? ' FOR UPDATE' : ''), $key);
        foreach ($rows as &$row) {
            $row['version'] = (int) $row['version']; $row['checked_at'] = (int) $row['checked_at'];
            $row['result'] = json_decode($row['result_json'], true, 16, JSON_THROW_ON_ERROR);
            unset($row['result_json'], $row['url_hash']);
        }
        return $rows;
    }
    /** Stable price inputs only: refreshing discovery evidence must not restart price jobs. */
    public function identitiesForProduct(string $key, bool $lock = false): array
    {
        self::key($key);
        if (!$this->installed()) return [];
        return $this->db->query('SELECT link_key,variant_key,partner_key,url,origin,removed FROM shop_product_variant_supplier_links WHERE product_key=%s ORDER BY variant_key,link_key' . ($lock ? ' FOR UPDATE' : ''), $key);
    }
    /** Each product commits separately. Retrying an interrupted migration is safe. */
    public function inheritAll(): int
    {
        $count = 0;
        $keys = $this->db->queryFirstColumn('SELECT DISTINCT v.product_key FROM shop_product_variants v INNER JOIN shop_product_revisions p ON p.product_key=v.product_key AND p.active_product_key IS NOT NULL ORDER BY v.product_key');
        foreach ($keys as $key) $count += $this->inherit($key);
        return $count;
    }
    public function inherit(string $key): int
    {
        return $this->transaction($key, fn(): int => $this->inheritInTransaction($key));
    }
    /** The caller holds the product revision lock. Only missing source/variant pairs are added. */
    public function inheritInTransaction(string $key): int
    {
        if (!$this->installed()) return 0;
        $links = $this->db->query('SELECT id,label,url FROM shop_product_supplier_links WHERE product_key=%s ORDER BY id FOR UPDATE', $key);
        $variants = $this->db->queryFirstColumn('SELECT variant_key FROM shop_product_variants WHERE product_key=%s ORDER BY variant_key', $key);
        $partners = (new PurchasePartnerRepository($this->db))->all();
        $existing = $this->forProduct($key, true, true); $seen = [];
        foreach ($existing as $row) {
            $seen[$row['variant_key'] . ':' . hash('sha256', $row['url'])] = true;
            if ($row['inherited_from_hash'] !== null) $seen[$row['variant_key'] . ':' . $row['inherited_from_hash']] = true;
        }
        $added = 0;
        foreach ($variants as $variant) foreach ($links as $link) {
            $hash = hash('sha256', $link['url']); $pair = $variant . ':' . $hash;
            if (isset($seen[$pair])) continue;
            $matches = array_values(array_filter($partners, static fn(array $p): bool => ProductPartnerAssignment::host($p['website_url'] ?? '') === ProductPartnerAssignment::host($link['url'])));
            $this->db->insert('shop_product_variant_supplier_links', [
                'link_key' => bin2hex(random_bytes(16)), 'product_key' => $key, 'variant_key' => $variant,
                'partner_key' => count($matches) === 1 && !empty($matches[0]['partner_key']) ? $matches[0]['partner_key'] : null,
                'label' => $link['label'], 'url' => $link['url'], 'url_hash' => $hash,
                'inherited_from_hash' => $hash, 'origin' => 'inherited', 'verification' => 'unverified', 'result_json' => '{}',
            ]);
            $seen[$pair] = true; $added++;
        }
        return $added;
    }
    public function save(string $key, string $variant, string $id, int $version, string $partner, string $label, string $url): string
    {
        return $this->transaction($key, fn(): string => $this->saveInTransaction($key, $variant, $id, $version, $partner, $label, $url));
    }
    /** Additive imports call this under the same product lock; never accept imported verification. */
    public function saveInTransaction(string $key, string $variant, string $id, int $version, string $partner, string $label, string $url): string
    {
        self::key($variant); if ($id !== '') self::key($id);
        ProductSupplierLinkRepository::validate($label, $url);
        if ($this->db->queryFirstField('SELECT variant_key FROM shop_product_variants WHERE product_key=%s AND variant_key=%s', $key, $variant) === null) throw new InvalidArgumentException('Varianta nepatří k produktu.');
        if ($partner !== '') {
            self::key($partner);
            $p = $this->db->queryFirstRow('SELECT website_url FROM shop_purchase_partners WHERE partner_key=%s', $partner);
            if ($p === null || ProductPartnerAssignment::host($p['website_url']) !== ProductPartnerAssignment::host($url)) throw new InvalidArgumentException('Odkaz nepatří k webu zvoleného partnera.');
        }
        $old = $id === '' ? null : $this->db->queryFirstRow('SELECT * FROM shop_product_variant_supplier_links WHERE product_key=%s AND variant_key=%s AND link_key=%s AND removed=0 FOR UPDATE', $key, $variant, $id);
        if (($id !== '' && ($old === null || (int) $old['version'] !== $version)) || ($id === '' && $version !== 0)) throw new InvalidArgumentException('Odkaz se mezitím změnil. Obnov stránku nebo export.');
        $duplicate = $this->db->queryFirstRow('SELECT link_key FROM shop_product_variant_supplier_links WHERE product_key=%s AND variant_key=%s AND url_hash=%s', $key, $variant, hash('sha256', $url));
        if ($duplicate !== null && $duplicate['link_key'] !== $id) throw new InvalidArgumentException('Tato varianta už má stejný odkaz nebo byl odkaz odebrán. Uprav stávající záznam.');
        $values = ['partner_key' => $partner ?: null, 'label' => $label, 'url' => $url, 'url_hash' => hash('sha256', $url), 'origin' => 'manual', 'version' => $version + 1];
        if ($old === null || $old['url'] !== $url || ($old['partner_key'] ?? '') !== $partner) {
            $values += ['verification' => 'unverified', 'checked_at' => 0, 'result_json' => '{}'];
        }
        if ($old === null) {
            $id = bin2hex(random_bytes(16));
            $this->db->insert('shop_product_variant_supplier_links', $values + ['link_key' => $id, 'product_key' => $key, 'variant_key' => $variant]);
        } else $this->db->update('shop_product_variant_supplier_links', $values, 'link_key=%s', $id);
        return $id;
    }
    public function remove(string $key, string $variant, string $id, int $version): void
    {
        self::key($variant); self::key($id);
        $this->transaction($key, function() use ($key, $variant, $id, $version): void {
            // Retain a tombstone so repeated inheritance cannot resurrect a removed link.
            $this->db->query('UPDATE shop_product_variant_supplier_links SET removed=1,version=version+1 WHERE product_key=%s AND variant_key=%s AND link_key=%s AND version=%i AND removed=0', $key, $variant, $id, $version);
            if ($this->db->affectedRows() !== 1) throw new InvalidArgumentException('Odkaz se mezitím změnil. Obnov stránku.');
        });
    }
    public function transaction(string $key, callable $callback): mixed
    {
        self::key($key);
        if (!$this->installed()) throw new InvalidArgumentException('Nejprve aktualizuj databázi v administraci.');
        $this->db->startTransaction();
        try {
            $current = $this->db->queryFirstColumn('SELECT id FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL ORDER BY language FOR UPDATE', $key);
            if ($current === []) throw new InvalidArgumentException('Produkt již neexistuje.');
            $result = $callback(); $this->db->commit(); return $result;
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }
    private static function key(string $key): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) throw new InvalidArgumentException('Neplatný klíč produktu, varianty nebo partnera.');
    }
}
