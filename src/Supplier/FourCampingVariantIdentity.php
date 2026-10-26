<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use InvalidArgumentException;

/** 4camping's public variant table binds colour hashes to exact SKU prices. */
final class FourCampingVariantIdentity
{
    public static function supported(string $url): bool
    {
        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), ['4camping.cz', 'www.4camping.cz'], true);
    }

    public static function offers(string $body, string $url, array $offer): array
    {
        if (!self::supported($url)) return [];
        $data = EmbeddedVariantData::read($body);
        if (!$data || !is_array($data['variantsInfo'] ?? null) || $data['variantsInfo'] === [] ||
            strtoupper((string) ($data['currency'] ?? '')) !== 'CZK' || !is_bool($data['variantsUrlEnabled'] ?? null)) return [];
        $parent = $offer['url'];
        try {
            if (!self::supported($parent) || !PriceParser::sameModel($parent, $url)) return [];
            $host = HttpClient::url($parent);
        } catch (InvalidArgumentException $e) { return []; }
        $path = rtrim((string) parse_url($parent, PHP_URL_PATH), '/') . '/';
        if (!str_starts_with($path, '/p/') || parse_url($parent, PHP_URL_FRAGMENT) !== null || parse_url($parent, PHP_URL_QUERY) !== null) return [];
        $out = []; $seen = [];
        foreach ($data['variantsInfo'] as $id => $variant) {
            if (!is_array($variant) || (string) ($variant['id'] ?? '') !== (string) $id ||
                !ctype_digit((string) $id) || (string) ($variant['productId'] ?? '') !== (string) ($data['productId'] ?? '') ||
                !ctype_digit((string) ($data['productId'] ?? '')) || !is_string($variant['url'] ?? null) ||
                !is_string($variant['name'] ?? null) || PriceParser::amount($variant['price'] ?? null) === null) return [];
            $source = $variant['url'];
            if (str_starts_with($source, '/') && !str_starts_with($source, '//')) $source = 'https://' . $host . $source;
            try { if (HttpClient::url($source) !== $host) return []; } catch (InvalidArgumentException $e) { return []; }
            $variantPath = (string) parse_url($source, PHP_URL_PATH);
            if (!str_starts_with($variantPath, $path) || parse_url($source, PHP_URL_QUERY) !== null || parse_url($source, PHP_URL_FRAGMENT) !== null) return [];
            $selector = trim(substr($variantPath, strlen($path)), '/');
            if ($selector === '' || isset($seen[$selector])) return [];
            $seen[$selector] = true;
            preg_match_all('/(?:^|,\s+)([^:,]+):\s*(.*?)(?=,\s+[^:,]+:|$)/u', $variant['name'], $parts, PREG_SET_ORDER);
            $values = [];
            foreach ($parts as $part) $values[trim($part[1])] = trim($part[2]);
            $attributes = StructuredVariantScope::attributes($values);
            if (!$attributes) return [];
            $exactUrl = $data['variantsUrlEnabled'] ? $source : HttpClient::withoutFragment($parent) . '#' . $selector;
            $availability = (string) ($variant['availability'] ?? '');
            $out[] = ['url' => $exactUrl, 'price' => PriceParser::amount($variant['price']), 'amount' => (string) $variant['price'],
                'product_name' => (string) ($variant['productNameWithVariant'] ?? $offer['product_name']),
                'group_name' => $offer['product_name'], 'group_url' => $parent, 'attributes' => $attributes,
                'availability' => str_contains($availability, 'stock--available') ? 'in_stock' :
                    (($variant['allowPurchase'] ?? null) === false ? 'out_of_stock' : 'unknown'),
                'identity_evidence' => ['method' => '4camping_variant_table', 'verified_url' => $exactUrl,
                    'product_id' => (string) $data['productId'], 'variant_id' => (string) $id]];
        }
        // The public schema price must agree with the selected SKU, or the minimum on a model page.
        $selected = $data['selectedVariantId'] ?? null;
        if ($selected !== null && !isset($data['variantsInfo'][$selected])) return [];
        $reference = $selected === null ? min(array_column($out, 'amount')) : $data['variantsInfo'][$selected]['price'];
        if (number_format((float) $reference, 2, '.', '') !== number_format((float) $offer['amount'], 2, '.', '')) return [];
        return $out;
    }

    public static function select(array $document, string $url): ?array
    {
        if (!self::supported($url)) return null;
        $offers = $document['scoped_offers'] ?? [];
        if ($offers === [] && parse_url($url, PHP_URL_FRAGMENT) === null) return null;
        $exact = array_values(array_filter($offers, static fn(array $o): bool => PriceParser::sameOfferUrl($o['url'], $url)));
        if (count($exact) !== 1) throw new InvalidArgumentException('Nabídka 4camping nepotvrzuje konkrétní variantu. Zkontroluj barvu a přesný partnerský odkaz.');
        return $exact[0] + ['uncertain' => false, 'uniform_variants' => false];
    }
}
