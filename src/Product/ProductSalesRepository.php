<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Checkout\AssistedPurchase;
use Throwable;

/** Stable product settings; source links and admin purchase prices never enter public product data. */
final class ProductSalesRepository
{
    private ?bool $ready = null;

    public function __construct(private MeekroDB $db) {}

    public function installed(): bool
    {
        return $this->ready ??= (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
             AND TABLE_NAME IN (%s,%s)', 'shop_product_sales', 'shop_purchase_partners') === 2;
    }

    public function partners(): array
    {
        return $this->installed() ? (new PurchasePartnerRepository($this->db))->all() : [];
    }

    public function forProduct(string $key): array
    {
        if (!$this->installed()) return ['mode' => 'own', 'version' => 0];
        $row = $this->db->queryFirstRow('SELECT * FROM shop_product_sales WHERE product_key=%s', $key);
        if ($row === null) return ['mode' => 'own', 'version' => 0];
        $row['supplier_price_czk'] = $row['supplier_price_czk'] === null ? null : (int) $row['supplier_price_czk'];
        $row['version'] = (int) $row['version'];
        $row['variant_prices'] = json_decode((string) $row['variant_prices_json'], true, 32, JSON_THROW_ON_ERROR);
        $partner = $row['partner_id'] === null ? null : (new PurchasePartnerRepository($this->db))->find((int) $row['partner_id']);
        if ($partner !== null && PurchasePartnerRepository::missing($partner) !== []) $partner = null;
        if ($partner !== null) $partner = array_intersect_key($partner, PurchasePartnerRepository::FIELDS);
        $row['seller'] = $partner;
        return $row;
    }

    public function snapshot(string $key, string $variantKey, int $price): array
    {
        $sale = $this->forProduct($key);
        if ($variantKey !== '' && isset($sale['variant_prices'][$variantKey])) {
            $sale['supplier_price_czk'] = $sale['variant_prices'][$variantKey];
        }
        return AssistedPurchase::snapshot($sale, $price);
    }

    /** Only the mode is published on cards and product pages. */
    public function decorate(array $rows): array
    {
        if ($rows === [] || !$this->installed()) return $rows;
        $keys = array_values(array_unique(array_column($rows, 'product_key')));
        $modes = [];
        foreach ($this->db->query('SELECT product_key, mode FROM shop_product_sales WHERE product_key IN (' .
            implode(',', array_fill(0, count($keys), '%s')) . ')', ...$keys) as $sale) {
            $modes[$sale['product_key']] = $sale['mode'];
        }
        foreach ($rows as &$row) $row['sale_mode'] = $modes[$row['product_key']] ?? 'own';
        unset($row);
        return $rows;
    }

    /** Save unfinished settings too; checkout independently requires complete, confirmed data. */
    public function save(string $key, string $language, array $input): void
    {
        if (!$this->installed() || !(new PurchasePartnerRepository($this->db))->installed() || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Nejprve aktualizuj databázi v administraci.');
        }
        $mode = $input['mode'] ?? null;
        if (!in_array($mode, ['own', 'assisted'], true)) throw new InvalidArgumentException('Vyber způsob prodeje.');
        $expected = filter_var($input['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($expected === false) throw new InvalidArgumentException('Obnov formulář nastavení prodeje.');
        $cost = null; $partnerId = null; $note = ''; $variantPrices = [];
        $this->db->startTransaction();
        try {
            $product = $this->db->queryFirstRow('SELECT * FROM shop_product_revisions WHERE product_key=%s
                AND language=%s AND active_product_key IS NOT NULL LIMIT 1 FOR UPDATE', $key, $language);
            if ($product === null) throw new InvalidArgumentException('Produkt už neexistuje.');
            $stored = $this->db->queryFirstRow('SELECT version FROM shop_product_sales WHERE product_key=%s FOR UPDATE', $key);
            if ((int) ($stored['version'] ?? 0) !== $expected) throw new InvalidArgumentException('Nastavení mezitím někdo změnil. Obnov stránku.');
            $cost = ($input['supplier_price_czk'] ?? '') === '' ? null : self::price($input['supplier_price_czk']);
            $note = self::text($input['delivery_note'] ?? '', 190);
            $partnerId = filter_var($input['partner_id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($partnerId === false) throw new InvalidArgumentException('Vyber prodávajícího.');
            if ($partnerId === 0) {
                $partner = []; $hasPartner = false;
                foreach (PurchasePartnerRepository::FIELDS as $field => $limit) {
                    $partner[$field] = $input['partner_' . $field] ?? '';
                    if ($partner[$field] !== '') $hasPartner = true;
                }
                if ($hasPartner) {
                    $partner['confirmed'] = $input['partner_confirmed'] ?? '';
                    $partnerId = (new PurchasePartnerRepository($this->db))->save(0, $partner);
                } else $partnerId = null;
            } else {
                foreach (array_keys(PurchasePartnerRepository::FIELDS) as $field) {
                    if (($input['partner_' . $field] ?? '') !== '') {
                        throw new InvalidArgumentException('Vybral jsi uloženého prodávajícího a vyplnil také nového. Pro vytvoření nového zvol ve výběru „Zatím nevybrán / nový prodávající níže“, jinak vyprázdni údaje nového.');
                    }
                }
                if ((new PurchasePartnerRepository($this->db))->find($partnerId) === null) {
                    throw new InvalidArgumentException('Vybraný prodávající už neexistuje.');
                }
            }
            $variants = (new ProductVariantRepository($this->db))->snapshots([$key]);
            $inventory = $variants[$key] ?? ProductVariantRepository::empty();
            $overrides = $input['variant_price'] ?? [];
            if (!is_array($overrides)) throw new InvalidArgumentException('Neplatné ceny variant.');
            if ($inventory['enabled']) {
                foreach ($inventory['items'] as $variant) {
                    $value = $overrides[$variant['id']] ?? '';
                    if ($value !== '') $variantPrices[$variant['id']] = self::price($value);
                    if ($mode === 'assisted' && $variant['enabled'] && ($variantPrices[$variant['id']] ?? $cost) >
                        ($variant['price_czk'] ?? (int) $product['price_czk'])) {
                        throw new InvalidArgumentException('Cena dodavatele nesmí převýšit konečnou cenu žádné aktivní varianty.');
                    }
                }
            } elseif ($mode === 'assisted' && $cost > (int) $product['price_czk']) {
                throw new InvalidArgumentException('Cena dodavatele nesmí převýšit konečnou cenu produktu.');
            }
            $values = ['mode' => $mode, 'partner_id' => $partnerId, 'supplier_price_czk' => $cost,
                'delivery_note' => $note, 'variant_prices_json' => json_encode($variantPrices, JSON_THROW_ON_ERROR),
                'version' => $expected + 1];
            if ($stored === null) $this->db->insert('shop_product_sales', ['product_key' => $key] + $values);
            else $this->db->update('shop_product_sales', $values, 'product_key=%s', $key);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }

    private static function price(mixed $value): int
    {
        $price = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 9999999]]);
        if ($price === false) throw new InvalidArgumentException('Cena dodavatele musí být v celých Kč, od 1 do 9 999 999.');
        return $price;
    }

    private static function text(mixed $value, int $limit): string
    {
        if (!is_string($value) || strlen(trim($value)) > $limit ||
            preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('Doba dodání je příliš dlouhá nebo obsahuje nepovolené znaky.');
        }
        return trim($value);
    }
}
