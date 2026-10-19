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
        return $this->installed() ? $this->db->query('SELECT * FROM shop_purchase_partners ORDER BY name, id LIMIT 500') : [];
    }

    public function forProduct(string $key): array
    {
        if (!$this->installed()) return ['mode' => 'own', 'version' => 0];
        $row = $this->db->queryFirstRow('SELECT * FROM shop_product_sales WHERE product_key=%s', $key);
        if ($row === null) return ['mode' => 'own', 'version' => 0];
        $row['supplier_price_czk'] = $row['supplier_price_czk'] === null ? null : (int) $row['supplier_price_czk'];
        $row['version'] = (int) $row['version'];
        $row['variant_prices'] = json_decode((string) $row['variant_prices_json'], true, 32, JSON_THROW_ON_ERROR);
        $partner = $row['partner_id'] === null ? null : $this->db->queryFirstRow(
            'SELECT name, ico, address, email, terms_url FROM shop_purchase_partners WHERE id=%i', (int) $row['partner_id']);
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

    /** One atomic save; existing partner identities are immutable, corrections create a new profile. */
    public function save(string $key, string $language, array $input): void
    {
        if (!$this->installed() || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
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
            if ($mode === 'assisted') {
                $cost = self::price($input['supplier_price_czk'] ?? null);
                $note = self::text($input['delivery_note'] ?? null, 190);
                $partnerId = filter_var($input['partner_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($partnerId === false) throw new InvalidArgumentException('Vyber prodávajícího.');
                if ($partnerId === 0) {
                    if (($input['partner_confirmed'] ?? '') !== '1') throw new InvalidArgumentException('Potvrď možnost nakupovat u partnera jménem zákazníků.');
                    $partner = [];
                    foreach (['name' => 190, 'ico' => 8, 'address' => 500, 'email' => 190, 'terms_url' => 1000] as $field => $limit) {
                        $partner[$field] = self::text($input['partner_' . $field] ?? null, $limit);
                    }
                    if (preg_match('/^[0-9]{8}$/D', $partner['ico']) !== 1 ||
                        filter_var($partner['email'], FILTER_VALIDATE_EMAIL) === false ||
                        filter_var($partner['terms_url'], FILTER_VALIDATE_URL) === false ||
                        parse_url($partner['terms_url'], PHP_URL_SCHEME) !== 'https' ||
                        parse_url($partner['terms_url'], PHP_URL_USER) !== null ||
                        parse_url($partner['terms_url'], PHP_URL_PASS) !== null) {
                        throw new InvalidArgumentException('Zkontroluj IČO, e-mail a HTTPS adresu podmínek prodávajícího.');
                    }
                    $this->db->insert('shop_purchase_partners', $partner);
                    $partnerId = (int) $this->db->insertId();
                } elseif ($this->db->queryFirstRow('SELECT id FROM shop_purchase_partners WHERE id=%i', $partnerId) === null) {
                    throw new InvalidArgumentException('Vybraný prodávající už neexistuje.');
                }
                $variants = (new ProductVariantRepository($this->db))->snapshots([$key]);
                $inventory = $variants[$key] ?? ProductVariantRepository::empty();
                $overrides = $input['variant_price'] ?? [];
                if (!is_array($overrides)) throw new InvalidArgumentException('Neplatné ceny variant.');
                if ($inventory['enabled']) {
                    foreach ($inventory['items'] as $variant) {
                        $value = $overrides[$variant['id']] ?? '';
                        if ($value !== '') $variantPrices[$variant['id']] = self::price($value);
                        if ($variant['enabled'] && ($variantPrices[$variant['id']] ?? $cost) >
                            ($variant['price_czk'] ?? (int) $product['price_czk'])) {
                            throw new InvalidArgumentException('Cena dodavatele nesmí převýšit konečnou cenu žádné aktivní varianty.');
                        }
                    }
                } elseif ($cost > (int) $product['price_czk']) {
                    throw new InvalidArgumentException('Cena dodavatele nesmí převýšit konečnou cenu produktu.');
                }
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
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $limit ||
            preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('Vyplň úplné údaje prodávajícího a předpokládanou dobu dodání.');
        }
        return trim($value);
    }
}
