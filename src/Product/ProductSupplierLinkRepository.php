<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;

/** Private supplier URLs belong to a product key, across revisions and languages. */
final class ProductSupplierLinkRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_product_supplier_links') === 1;
    }

    public function forProduct(string $key): array
    {
        self::validKey($key);
        return $this->db->query('SELECT id, label, url FROM shop_product_supplier_links
            WHERE product_key=%s ORDER BY id ASC', $key);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function forOrderItems(array $items): array
    {
        $keys = [];
        foreach ($items as $item) {
            $key = is_array($item) ? ($item['product_key'] ?? null) : null;
            if (is_string($key) && preg_match('/^[a-f0-9]{32}$/D', $key) === 1) $keys[$key] = true;
        }
        if ($keys === []) return [];

        $holders = implode(',', array_fill(0, count($keys), '%s'));
        $links = [];
        foreach ($this->db->query('SELECT product_key, id, label, url FROM shop_product_supplier_links
            WHERE product_key IN (' . $holders . ') ORDER BY id ASC', ...array_keys($keys)) as $row) {
            $links[(string) $row['product_key']][] = $row;
        }
        return $links;
    }

    public function save(string $key, int $id, string $label, string $url): void
    {
        self::validKey($key);
        if ($id < 0 || preg_match('/^.{1,120}$/usD', $label) !== 1 ||
            preg_match('/[\x00-\x1f\x7f]/', $label) ||
            strlen($url) > 1000 || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Vyplň název dodavatele a platný webový odkaz.');
        }
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true) ||
            !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Odkaz musí být platná adresa HTTP nebo HTTPS bez přihlašovacích údajů.');
        }

        if ($id === 0) {
            $this->db->insert('shop_product_supplier_links', [
                'product_key' => $key, 'label' => $label, 'url' => $url,
            ]);
            return;
        }
        $this->assertOwned($id, $key);
        $this->db->query('UPDATE shop_product_supplier_links SET label=%s, url=%s
            WHERE id=%i AND product_key=%s', $label, $url, $id, $key);
    }

    public function remove(string $key, int $id): void
    {
        self::validKey($key);
        $this->assertOwned($id, $key);
        $this->db->query('DELETE FROM shop_product_supplier_links WHERE id=%i AND product_key=%s', $id, $key);
    }

    private function assertOwned(int $id, string $key): void
    {
        if ($id < 1 || $this->db->queryFirstRow('SELECT id FROM shop_product_supplier_links
            WHERE id=%i AND product_key=%s', $id, $key) === null) {
            throw new InvalidArgumentException('Odkaz už u tohoto produktu neexistuje.');
        }
    }

    private static function validKey(string $key): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Neplatný klíč produktu.');
        }
    }
}
