<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Checkout\AssistedPurchase;
use Throwable;

/** A catalog-wide mode change, preserving each product's prepared partner configuration. */
final class ProductSalesMassUpdate
{
    public function __construct(private MeekroDB $db, private string $defaultLanguage = 'cs') {}

    public function installed(): bool
    {
        return (new ProductSalesRepository($this->db))->installed() && (new PurchasePartnerRepository($this->db))->installed();
    }

    public function counts(): array
    {
        $counts = ['own' => 0, 'assisted' => 0];
        if (!$this->installed()) return $counts;
        foreach ($this->db->query('SELECT COALESCE(s.mode, %s) AS mode, COUNT(DISTINCT r.product_key) AS total
            FROM shop_product_revisions r LEFT JOIN shop_product_sales s ON s.product_key=r.product_key
            WHERE r.active_product_key IS NOT NULL GROUP BY COALESCE(s.mode, %s)', 'own', 'own') as $row) {
            $counts[$row['mode']] = (int) $row['total'];
        }
        return $counts;
    }

    public function preview(mixed $mode): array
    {
        $mode = self::mode($mode);
        $rows = $this->rows();
        if ($rows === []) throw new InvalidArgumentException('V obchodě zatím nejsou žádné produkty.');
        $changed = 0; $issues = [];
        foreach ($rows as $row) {
            if (($row['sale']['mode'] ?? 'own') !== $mode) $changed++;
            if ($mode === 'assisted') {
                $reasons = self::missing($row);
                if ($reasons !== []) $issues[] = ['name' => $row['name'], 'language' => $row['language'],
                    'slug' => $row['slug'], 'reasons' => $reasons];
            }
        }
        return ['mode' => $mode, 'total' => count($rows), 'changed' => $changed,
            'unchanged' => count($rows) - $changed, 'issues' => $issues,
            'fingerprint' => self::fingerprint($mode, $rows)];
    }

    public function apply(array $preview): int
    {
        $mode = self::mode($preview['mode'] ?? null);
        $this->db->startTransaction();
        try {
            $rows = $this->rows(true);
            if (!is_string($preview['fingerprint'] ?? null) ||
                !hash_equals($preview['fingerprint'], self::fingerprint($mode, $rows))) {
                throw new InvalidArgumentException('Produkty, ceny nebo prodávající se od náhledu změnili. Zobraz nový náhled.');
            }
            $changed = 0;
            foreach ($rows as $key => $row) {
                $sale = $row['sale'];
                if (($sale['mode'] ?? 'own') === $mode) continue;
                if ($sale === null) {
                    $this->db->insert('shop_product_sales', ['product_key' => $key, 'mode' => $mode,
                        'variant_prices_json' => '{}', 'version' => 1]);
                } else {
                    // Do not route through a product form: absent fields must never erase saved data.
                    $this->db->update('shop_product_sales', ['mode' => $mode, 'version' => (int) $sale['version'] + 1],
                        'product_key=%s', $key);
                }
                $changed++;
            }
            $this->db->commit();
            return $changed;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private static function mode(mixed $mode): string
    {
        if (!in_array($mode, ['own', 'assisted'], true)) throw new InvalidArgumentException('Vyber způsob prodeje pro všechny produkty.');
        return $mode;
    }

    private function rows(bool $lock = false): array
    {
        if (!$this->installed()) throw new InvalidArgumentException('Nejprve aktualizuj databázi v administraci.');
        if ($lock) {
            // Match product/variant editors and checkout: inventory locks precede content locks.
            foreach ($this->db->query('SELECT DISTINCT product_key FROM shop_product_revisions WHERE active_product_key IS NOT NULL ORDER BY product_key') as $row) {
                (new ProductStockRepository($this->db))->ensure($row['product_key']);
                $this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE', $row['product_key']);
            }
        }
        $products = $this->db->query('SELECT product_key, id, language, slug, name, price_czk
            FROM shop_product_revisions WHERE active_product_key IS NOT NULL
            ORDER BY product_key, (language=%s) DESC, language' . ($lock ? ' FOR UPDATE' : ''), $this->defaultLanguage);
        if ($products === []) return [];
        $keys = array_values(array_unique(array_column($products, 'product_key')));
        $in = implode(',', array_fill(0, count($keys), '%s'));
        $sales = [];
        foreach ($this->db->query('SELECT * FROM shop_product_sales WHERE product_key IN (' . $in . ') ORDER BY product_key' . ($lock ? ' FOR UPDATE' : ''), ...$keys) as $sale) {
            $sales[$sale['product_key']] = $sale;
        }
        $variants = (new ProductVariantRepository($this->db))->snapshots($keys, $lock);
        $partners = [];
        $ids = array_values(array_unique(array_filter(array_column($sales, 'partner_id'))));
        if ($ids !== []) {
            foreach ($this->db->query('SELECT * FROM shop_purchase_partners WHERE id IN (' .
                implode(',', array_fill(0, count($ids), '%i')) . ') ORDER BY id' . ($lock ? ' FOR UPDATE' : ''), ...$ids) as $partner) {
                $partners[$partner['id']] = $partner;
            }
        }
        $rows = [];
        foreach ($products as $product) {
            $key = $product['product_key'];
            if (!isset($rows[$key])) {
                $state = $variants[$key] ?? ProductVariantRepository::empty();
                $prices = [];
                if ($state['enabled']) foreach ($state['items'] as $variant) {
                    if ($variant['enabled']) $prices[$variant['id']] = $variant['price_czk'];
                }
                $rows[$key] = ['name' => $product['name'], 'slug' => $product['slug'], 'language' => $product['language'],
                    'sale' => $sales[$key] ?? null, 'partner' => $partners[$sales[$key]['partner_id'] ?? 0] ?? null,
                    'variants_enabled' => $state['enabled'], 'variant_prices' => $prices, 'translations' => []];
            }
            // Prices may differ across translations; validate all of them, apply each identity once.
            $rows[$key]['translations'][] = $product;
        }
        return $rows;
    }

    private static function missing(array $row): array
    {
        $reasons = [];
        $sale = $row['sale'] ?? [];
        $partner = $row['partner'];
        if ($partner === null) $reasons[] = 'Není vybraný prodávající.';
        elseif (PurchasePartnerRepository::missing($partner) !== []) $reasons[] = 'Doplň profil prodávajícího: ' . implode(', ', PurchasePartnerRepository::missing($partner)) . '.';
        $overrides = json_decode($sale['variant_prices_json'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        if ($row['variants_enabled'] && $row['variant_prices'] === []) $reasons[] = 'Produkt nemá žádnou nabízenou variantu.';
        foreach ($row['translations'] as $product) {
            $prices = $row['variants_enabled'] ? $row['variant_prices'] : ['' => null];
            foreach ($prices as $id => $price) {
                $price = (int) ($price ?? $product['price_czk']);
                $cost = $overrides[$id] ?? $sale['supplier_price_czk'] ?? null;
                if ($cost === null || (int) $cost < 1) $reasons[] = 'Chybí cena dodavatele u produktu nebo některé nabízené varianty.';
                elseif ((int) $cost > $price) $reasons[] = 'Cena dodavatele převyšuje prodejní cenu produktu nebo některé nabízené varianty.';
                else {
                    // Use the checkout rule too, so the preview cannot promise an invalid offer is ready.
                    try { AssistedPurchase::snapshot(['mode' => 'assisted', 'supplier_price_czk' => (int) $cost, 'seller' => $partner], $price); }
                    catch (InvalidArgumentException $error) { if ($reasons === []) $reasons[] = $error->getMessage(); }
                }
            }
        }
        return array_values(array_unique($reasons));
    }

    private static function fingerprint(string $mode, array $rows): string
    {
        // Quantities are deliberately excluded: sales may continue while the preview is open.
        return hash('sha256', json_encode([$mode, $rows], JSON_THROW_ON_ERROR));
    }
}
