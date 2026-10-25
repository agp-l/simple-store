<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Matches stored URLs only. Never requests supplier pages or changes prices. */
final class ProductPartnerAssignment
{
    public function __construct(private MeekroDB $db, private string $language = 'cs') {}

    public function installed(): bool
    {
        return (new PurchasePartnerRepository($this->db))->assignmentReady() &&
            (new ProductSalesRepository($this->db))->installed() &&
            (new ProductSupplierLinkRepository($this->db))->installed();
    }

    public static function host(string $url): ?string
    {
        if (!PurchasePartnerRepository::validWebsite($url)) return null;
        $host = rtrim(strtolower((string) parse_url($url, PHP_URL_HOST)), '.');
        if (str_starts_with($host, 'www.')) $host = substr($host, 4);
        return $host === '' ? null : $host;
    }

    public static function match(array $links, array $partners): array
    {
        if ($links === []) return ['state' => 'no_links'];
        $hosts = [];
        // Stable link ordering; several URLs from the same shop are one seller candidate.
        usort($links, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        foreach ($links as $link) {
            $host = self::host((string) $link['url']);
            if ($host !== null && !isset($hosts[$host])) $hosts[$host] = $link;
        }
        $matches = [];
        foreach ($partners as $partner) {
            $host = self::host((string) ($partner['website_url'] ?? ''));
            if ($host !== null && isset($hosts[$host])) $matches[] = ['partner' => $partner, 'link' => $hosts[$host]];
        }
        if ($matches === []) return ['state' => 'no_match'];
        usort($matches, static fn (array $a, array $b): int => (int) $a['partner']['priority'] <=> (int) $b['partner']['priority']);
        $best = (int) $matches[0]['partner']['priority'];
        $winners = array_values(array_filter($matches, static fn (array $row): bool => (int) $row['partner']['priority'] === $best));
        if (count($winners) !== 1) return ['state' => 'conflict', 'names' => array_map(
            static fn (array $row): string => PurchasePartnerRepository::label($row['partner']), $winners)];
        return ['state' => 'matched'] + $winners[0];
    }

    public function previews(): array
    {
        $snapshot = $this->rows();
        return ['missing' => self::plan($snapshot, 'missing'), 'all' => self::plan($snapshot, 'all')];
    }

    public static function token(array $plan, string $csrf): string
    {
        return hash_hmac('sha256', $plan['scope'] . ':' . $plan['revision'], $csrf);
    }

    public function apply(string $scope, string $token, string $csrf): array
    {
        self::scope($scope);
        $this->db->startTransaction();
        try {
            $plan = self::plan($this->rows(true), $scope);
            if ($csrf === '' || !hash_equals(self::token($plan, $csrf), $token)) {
                throw new InvalidArgumentException('Produkty, dodavatelské odkazy nebo prodávající se mezitím změnili. Zkontroluj aktuální přehled a spusť přiřazení znovu. Nic se neuložilo.');
            }
            foreach ($plan['items'] as $item) {
                if ($item['state'] !== 'change') continue;
                $sale = $item['sale'];
                $values = ['partner_id' => (int) $item['partner']['id'], 'version' => (int) ($sale['version'] ?? 0) + 1];
                if ($sale === null) {
                    $this->db->insert('shop_product_sales', $values + ['product_key' => $item['key'],
                        'mode' => 'own', 'variant_prices_json' => '{}']);
                } else {
                    $this->db->update('shop_product_sales', $values, 'product_key=%s AND version=%i', $item['key'], $sale['version']);
                    if ($this->db->affectedRows() !== 1) throw new InvalidArgumentException('Nastavení produktu se mezitím změnilo. Obnov přehled.');
                }
            }
            $this->db->commit();
            return $plan['counts'];
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }

    private static function scope(string $scope): void
    {
        if (!in_array($scope, ['missing', 'all'], true)) throw new InvalidArgumentException('Vyber platný rozsah přiřazení.');
    }

    private static function plan(array $snapshot, string $scope): array
    {
        self::scope($scope);
        $counts = ['change' => 0, 'kept' => 0, 'same' => 0, 'no_links' => 0, 'no_match' => 0, 'conflict' => 0];
        $items = [];
        $byId = [];
        foreach ($snapshot['partners'] as $partner) $byId[$partner['id']] = $partner;
        foreach ($snapshot['products'] as $key => $product) {
            $sale = $snapshot['sales'][$key] ?? null;
            $current = $byId[$sale['partner_id'] ?? 0] ?? null;
            $match = self::match($snapshot['links'][$key] ?? [], $snapshot['partners']);
            if ($scope === 'missing' && $current !== null) $match = ['state' => 'kept'];
            elseif ($match['state'] === 'matched') {
                $match['state'] = (int) ($current['id'] ?? 0) === (int) $match['partner']['id'] ? 'same' : 'change';
            }
            $counts[$match['state']]++;
            $items[] = ['key' => $key, 'product' => $product, 'sale' => $sale, 'current' => $current] + $match;
        }
        return ['scope' => $scope, 'counts' => $counts, 'items' => $items,
            'revision' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    private function rows(bool $lock = false): array
    {
        if (!$this->installed()) throw new InvalidArgumentException('Nejprve aktualizuj databázi v administraci.');
        $suffix = $lock ? ' FOR UPDATE' : '';
        $products = [];
        foreach ($this->db->query('SELECT product_key, id, name, language, slug FROM shop_product_revisions
            WHERE active_product_key IS NOT NULL ORDER BY product_key, (language=%s) DESC, language' . $suffix, $this->language) as $row) {
            if (!isset($products[$row['product_key']])) $products[$row['product_key']] = $row;
        }
        $sales = []; $links = [];
        if ($products !== []) {
            $in = implode(',', array_fill(0, count($products), '%s'));
            foreach ($this->db->query('SELECT * FROM shop_product_sales WHERE product_key IN (' . $in . ') ORDER BY product_key' . $suffix, ...array_keys($products)) as $row) $sales[$row['product_key']] = $row;
            foreach ($this->db->query('SELECT id, product_key, label, url FROM shop_product_supplier_links WHERE product_key IN (' . $in . ') ORDER BY product_key, id' . $suffix, ...array_keys($products)) as $row) $links[$row['product_key']][] = $row;
        }
        $partners = $this->db->query('SELECT * FROM shop_purchase_partners ORDER BY id' . $suffix);
        return compact('products', 'sales', 'links', 'partners');
    }
}
