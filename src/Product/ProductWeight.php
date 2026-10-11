<?php
declare(strict_types=1);
namespace SimpleStore\Product;

/** Only unambiguous whole-product weights are eligible for filtering. */
final class ProductWeight
{
    public static function grams(?string $json): ?float
    {
        $data = json_decode($json ?? '', true);
        foreach (($data['specifications'] ?? []) as $spec) {
            if (!is_array($spec) || !is_string($spec['name'] ?? null) || !is_string($spec['value'] ?? null)) continue;
            if (!preg_match('/^(hmotnost|váha|weight)(\s*\(g\)|\s*\(kg\))?$/iuD', trim($spec['name']))) continue;
            $value = str_replace(["\u{00a0}", "\u{202f}"], ' ', trim($spec['value']));
            if (!preg_match('/^(?:cca\.?\s*|~\s*)?([0-9]+(?:[.,][0-9]+)?)\s*(kg|g)?$/iuD', $value, $match)) continue;
            $unit = strtolower($match[2] ?? '');
            if ($unit === '') {
                $unit = preg_match('/\((kg|g)\)/i', $spec['name'], $label) ? strtolower($label[1]) : '';
            }
            if ($unit === '') continue;
            $weight = (float) str_replace(',', '.', $match[1]) * ($unit === 'kg' ? 1000 : 1);
            return $weight > 0 ? $weight : null;
        }
        return null;
    }
}
