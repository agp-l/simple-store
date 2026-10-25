<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use InvalidArgumentException;

/** Reads explicit offers, never guesses a price from arbitrary visible numbers. */
final class PriceParser
{
    public static function read(string $body, string $url): array
    {
        $documents = [];
        preg_match_all('~<script\b[^>]*\btype\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script\s*>~is', $body, $scripts);
        preg_match_all('~^```(?:json|jsonld)\s*\n(.*?)^```~ms', $body, $fences);
        foreach (array_merge($scripts[1], $fences[1]) as $json) {
            $node = json_decode(trim($json), true, 40);
            if (is_array($node)) $documents[] = $node;
        }
        $products = [];
        foreach ($documents as $node) self::products($node, $products);
        $offers = [];
        foreach ($products as $product) {
            $raw = $product['offers'] ?? [];
            if (!is_array($raw)) continue;
            if (isset($raw['@type'])) $raw = [$raw];
            foreach ($raw as $offer) {
                if (!is_array($offer) || !self::type($offer, 'Offer') || !isset($offer['price'], $offer['priceCurrency'])) continue;
                // Conditional/member prices and expired offers must not become the general cost.
                if (isset($offer['validForMemberTier']) || isset($offer['eligibleCustomerType']) ||
                    (isset($offer['priceValidUntil']) && (!is_string($offer['priceValidUntil']) || strtotime($offer['priceValidUntil'].' 23:59:59 UTC') < time()))) continue;
                if ($offer['priceCurrency'] !== 'CZK') continue;
                $price = self::amount($offer['price']);
                if ($price === null) continue;
                $source = is_string($offer['url'] ?? null) ? $offer['url'] : (is_string($product['url'] ?? null) ? $product['url'] : '');
                $availability = basename(str_replace('#', '/', (is_string($offer['availability'] ?? null) ? $offer['availability'] : '')));
                $evidence = count($products) === 1 && count($raw) === 1 ? Pod7kiloVariantIdentity::verify($body, $url, $product, $offer,
                    static fn(string $a, string $b): bool => self::identity($a) === self::identity($b)) : null;
                $offers[] = ['price' => $price, 'amount' => (string)$offer['price'], 'url' => $source,
                    'identity_evidence' => $evidence, 'availability' => match($availability) {
                        'InStock', 'LimitedAvailability' => 'in_stock',
                        'OutOfStock', 'SoldOut', 'Discontinued' => 'out_of_stock',
                        'PreOrder', 'PreSale', 'BackOrder' => 'on_order', default => 'unknown',
                    }];
            }
        }
        if ($offers === []) throw new InvalidArgumentException('Stránka neposkytla jednoznačnou strukturovanou cenu v CZK. Cena zůstala.');
        $exact = array_values(array_filter($offers, static fn(array $o): bool => self::identity($o['url']) === self::identity($url) || $o['identity_evidence'] !== null));
        if ($exact !== []) $offers = $exact;
        elseif (count($products) !== 1 || count($offers) !== 1) throw new InvalidArgumentException('Nabídka není jednoznačná. Doplň odkaz na konkrétní variantu; původní cena zůstala.');
        if ($offers === []) throw new InvalidArgumentException('Stránka neposkytla jednoznačnou strukturovanou cenu v CZK. Cena zůstala.');
        $prices = array_unique(array_column($offers, 'price'));
        if (count($prices) !== 1) throw new InvalidArgumentException('Nabídky uvádějí různé ceny. Vyžaduje kontrolu konkrétní varianty.');
        $result = $offers[0];
        if (count(array_unique(array_column($offers, 'availability'))) > 1) $result['availability'] = 'unknown';
        $result['uncertain'] = $exact === [] && (parse_url($url, PHP_URL_QUERY) !== null ||
            ($result['url'] !== '' && self::identity($result['url']) !== self::identity($url)));
        return $result;
    }

    public static function amount(mixed $value): ?int
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', (string)$value)) return null;
        $amount = (float)$value;
        return $amount > 0 && $amount <= 9999999 ? (int)ceil($amount) : null;
    }

    private static function type(array $node, string $type): bool
    {
        return in_array($type, (array)($node['@type'] ?? []), true);
    }

    private static function products(array $node, array &$products): void
    {
        if (self::type($node, 'Product')) $products[] = $node;
        // Do not traverse ItemList, recommendations or arbitrary nested page content.
        foreach (['@graph', 'hasVariant', 'mainEntity'] as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) continue;
            $items = isset($node[$key]['@type']) ? [$node[$key]] : $node[$key];
            foreach ($items as $item) if (is_array($item)) self::products($item, $products);
        }
        if (array_is_list($node)) foreach ($node as $item) if (is_array($item)) self::products($item, $products);
    }

    private static function identity(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) return '';
        $host = preg_replace('/^www\./', '', strtolower($p['host']));
        parse_str($p['query'] ?? '', $query);
        foreach (array_keys($query) as $key) if (str_starts_with($key, 'utm_') || in_array($key, ['gclid','fbclid'], true)) unset($query[$key]);
        ksort($query);
        return $host . rtrim($p['path'] ?? '/', '/') . '?' . http_build_query($query);
    }
}
