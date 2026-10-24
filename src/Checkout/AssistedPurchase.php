<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** All amounts use the shop's existing integer CZK unit. Never accept browser prices. */
final class AssistedPurchase
{
    public const TERMS_SLUG = 'obchodni-podminky-asistovaneho-nakupu';
    // Shared mandate for new purchases; copied into each order, never read live for old orders.
    public const DELIVERY_POLICY = 'Nákup vaším jménem obstaráme bez zbytečného odkladu po přijetí platby a po uplynutí lhůty pro odstoupení od služby, nebo dříve na vaši výslovnou žádost. Zboží dodává uvedený prodávající v termínu podle svých podmínek, nejpozději však do 30 dnů od uzavření kupní smlouvy s ním. Jinou dodací lhůtu sjednáme pouze s vaším předchozím souhlasem.';
    public const EARLY_REQUEST = 'Žádám o zahájení asistovaného nákupu před uplynutím 14 dnů. Při odstoupení uhradím přiměřenou cenu již poskytnuté služby; po jejím úplném poskytnutí zaniká právo odstoupit od této služby. Práva ze samotné koupě zboží tím nejsou dotčena.';

    public static function has(array $items): bool
    {
        foreach ($items as $item) if (($item['sale_mode'] ?? 'own') === 'assisted') return true;
        return false;
    }

    public static function own(array $items): bool
    {
        foreach ($items as $item) if (($item['sale_mode'] ?? 'own') === 'own') return true;
        return false;
    }

    public static function snapshot(array $sale, int $price): array
    {
        if (($sale['mode'] ?? 'own') === 'own') return [];
        $cost = $sale['supplier_price_czk'] ?? null;
        $seller = $sale['seller'] ?? null;
        if (($sale['mode'] ?? '') !== 'assisted' || !is_int($cost) || $cost < 1 || $cost > $price ||
            !is_array($seller)) {
            throw new InvalidArgumentException('Nabídku partnerského produktu právě aktualizujeme. Zkuste nákup později.');
        }
        foreach (['name', 'ico', 'address', 'email', 'terms_url'] as $field) {
            if (!is_string($seller[$field] ?? null) || $seller[$field] === '') {
                throw new InvalidArgumentException('Údaje partnerského prodávajícího nejsou úplné.');
            }
        }
        if (filter_var($seller['terms_url'], FILTER_VALIDATE_URL) === false ||
            parse_url($seller['terms_url'], PHP_URL_SCHEME) !== 'https' ||
            parse_url($seller['terms_url'], PHP_URL_USER) !== null || parse_url($seller['terms_url'], PHP_URL_PASS) !== null ||
            preg_match('/^[0-9]{8}$/D', $seller['ico']) !== 1 || filter_var($seller['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Údaje partnerského prodávajícího nejsou platné.');
        }
        return ['sale_mode' => 'assisted', 'supplier_price_czk' => $cost,
            'assistance_fee_czk' => $price - $cost, 'seller' => $seller,
            'delivery_note' => ($sale['delivery_note'] ?? '') !== '' ? $sale['delivery_note'] : self::DELIVERY_POLICY];
    }

    /** Exclude entrusted purchase funds from our own invoice and taxable receipt. */
    public static function principal(array $items): int
    {
        $amount = 0;
        foreach ($items as $item) {
            if (($item['sale_mode'] ?? 'own') === 'assisted') {
                $amount += (int) $item['supplier_price_czk'] * (int) $item['quantity'];
            }
        }
        return $amount;
    }

    public static function invoiceItems(array $items): array
    {
        foreach ($items as &$item) {
            if (($item['sale_mode'] ?? 'own') !== 'assisted') continue;
            $item['name'] = 'Asistovaný nákup – ' . $item['name'];
            $item['unit_price_czk'] = (int) $item['assistance_fee_czk'];
        }
        unset($item);
        return $items;
    }

    public static function description(array $item): string
    {
        if (($item['sale_mode'] ?? 'own') !== 'assisted') return '';
        $seller = $item['seller'];
        return 'Asistovaný nákup. Cena za kus: zboží ' . number_format((int) $item['supplier_price_czk'], 0, ',', ' ') .
            ' Kč + služba ' . number_format((int) $item['assistance_fee_czk'], 0, ',', ' ') .
            ' Kč (již zahrnuta). Prodávající: ' . $seller['name'] . ', IČO ' . $seller['ico'] .
            ', ' . $seller['address'] . ', ' . $seller['email'] . '. Podmínky prodávajícího: ' .
            $seller['terms_url'] . '. Dodání: ' . $item['delivery_note'];
    }
}
