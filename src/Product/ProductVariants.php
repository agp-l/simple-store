<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;

/** Validate variant definitions independently from storage and form presentation. */
final class ProductVariants
{
    public const LIMIT = 1000;

    public static function choices(array $choices): array
    {
        if ($choices === [] || count($choices) > 8) throw new InvalidArgumentException('Varianta potřebuje 1 až 8 vlastností.');
        $result = [];
        foreach ($choices as $name => $value) {
            if (!is_string($name) || !is_string($value) || trim($name) === '' || trim($value) === '' ||
                strlen($name) > 80 || strlen($value) > 120 || preg_match('//u', $name . $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name . $value)) {
                throw new InvalidArgumentException('Vyplň název a hodnotu každé vlastnosti varianty.');
            }
            $name = trim($name);
            if (isset($result[$name])) throw new InvalidArgumentException('Duplicitní vlastnost varianty.');
            $result[$name] = trim($value);
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    public static function label(array $choices): string
    {
        $parts = [];
        foreach ($choices as $name => $value) $parts[] = $name . ': ' . $value;
        return implode(' · ', $parts);
    }

    public static function normalize(array $rows): array
    {
        if (count($rows) > self::LIMIT) throw new InvalidArgumentException('Produkt může mít nejvýše 1 000 variant.');
        $result = []; $combinations = []; $ids = []; $dimensions = null;
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff(array_keys($row), ['id', 'choices', 'quantity', 'stock_status', 'sku', 'price_czk', 'image_path', 'enabled']) !== []) {
                throw new InvalidArgumentException('Neplatná definice varianty.');
            }
            if (!is_array($row['choices'] ?? null)) throw new InvalidArgumentException('choices musí být objekt vlastností.');
            $choices = self::choices($row['choices']);
            $signature = json_encode($choices, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $id = $row['id'] ?? bin2hex(random_bytes(16));
            $quantity = $row['quantity'] ?? 0;
            $price = $row['price_czk'] ?? null;
            $sku = $row['sku'] ?? '';
            $image = $row['image_path'] ?? '';
            $status = $row['stock_status'] ?? 'in_stock';
            $enabled = $row['enabled'] ?? true;
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || isset($ids[$id]) ||
                isset($combinations[$signature]) || !is_int($quantity) || $quantity < 0 || $quantity > 1000000 ||
                ($price !== null && (!is_int($price) || $price < 1 || $price > 10000000)) ||
                !is_string($sku) || strlen($sku) > 100 || preg_match('//u', $sku) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $sku) ||
                !is_string($image) || ($image !== '' && !ProductDetails::imagePath($image)) || !is_bool($enabled) ||
                !in_array($status, ['in_stock', 'on_order', 'out_of_stock'], true)) {
                throw new InvalidArgumentException('Zkontroluj varianty: kombinace nesmí být duplicitní, počet 0–1 000 000, cena kladná.');
            }
            if ($enabled) {
                $dimensions ??= array_keys($choices);
                if ($dimensions !== array_keys($choices)) throw new InvalidArgumentException('Aktivní varianty musí používat stejné názvy vlastností.');
            }
            $ids[$id] = true; $combinations[$signature] = true;
            $result[] = ['id' => $id, 'choices' => $choices, 'quantity' => $quantity, 'stock_status' => $status,
                'sku' => trim($sku), 'price_czk' => $price, 'image_path' => $image, 'enabled' => $enabled];
        }
        return $result;
    }

    /** Each line is Name: value | value; generate only on an explicit administrator request. */
    public static function generate(string $text): array
    {
        if (strlen($text) > 16000 || preg_match('//u', $text) !== 1) throw new InvalidArgumentException('Vlastnosti musí být platný text do 16 000 bajtů.');
        $groups = [];
        foreach (preg_split('/\R/u', trim($text)) as $line) {
            if (trim($line) === '') continue;
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) throw new InvalidArgumentException('Použij řádek například Velikost: S | M | L.');
            $name = trim($parts[0]);
            if (isset($groups[$name])) throw new InvalidArgumentException('Vlastnost je uvedena vícekrát.');
            $groups[$name] = array_unique(array_map('trim', explode('|', $parts[1])));
        }
        $options = [];
        foreach ($groups as $name => $values) $options[] = ['name' => $name, 'values' => $values];
        return self::fromOptions($options);
    }

    /** Preserve literal punctuation in saved options; do not round-trip through textarea delimiters. */
    public static function fromOptions(array $options): array
    {
        if ($options === [] || count($options) > 8) throw new InvalidArgumentException('Vyplň 1 až 8 vlastností.');
        $choices = [[]];
        $names = [];
        foreach ($options as $group) {
            if (!is_array($group) || !is_string($group['name'] ?? null) || !is_array($group['values'] ?? null) || $group['values'] === []) throw new InvalidArgumentException('Vlastnost potřebuje název a hodnoty.');
            $name = trim($group['name']);
            if (isset($names[$name])) throw new InvalidArgumentException('Vlastnost je uvedena vícekrát.');
            $names[$name] = true;
            $values = $group['values'];
            if (count($choices) * count($values) > self::LIMIT) throw new InvalidArgumentException('Příliš mnoho kombinací. Přidávej pouze skutečně prodávané varianty.');
            $next = [];
            foreach ($choices as $choice) foreach ($values as $value) $next[] = $choice + [$name => $value];
            $choices = $next;
        }
        return self::normalize(array_map(static fn (array $choice): array => ['choices' => $choice], $choices));
    }

    public static function availability(array $variant): string
    {
        return !$variant['enabled'] || ($variant['stock_status'] === 'in_stock' && $variant['quantity'] < 1)
            ? 'out_of_stock' : $variant['stock_status'];
    }
}
