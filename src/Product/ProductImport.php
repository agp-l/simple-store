<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use JsonException;
use SimpleStore\Editing\CardSummary;
use SimpleStore\Navigation\Slugger;

/** A versioned data-only boundary shared by the paste form and future API clients. */
final class ProductImport
{
    public const FORMAT = 'simple-store.product.v1';
    public const LIMIT = 131072;
    private const TEXT = ['name', 'slug', 'brand', 'summary', 'description', 'category_path', 'image_path', 'stock_status'];

    public static function decode(string $json): array
    {
        if (strlen($json) > self::LIMIT) throw new InvalidArgumentException('JSON může mít nejvýše 128 KiB.');
        $json = trim($json);
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/sD', $json, $match)) $json = $match[1];
        try {
            $object = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Neplatný JSON: ' . $error->getMessage());
        }
        if (!$object instanceof \stdClass || !($object->product ?? null) instanceof \stdClass) {
            throw new InvalidArgumentException('Očekávám JSON objekt s objektem product.');
        }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::keys($data, ['format', 'product_key', 'revision', 'language', 'product']);
        if (($data['format'] ?? null) !== self::FORMAT || !is_string($data['language'] ?? null)) {
            throw new InvalidArgumentException('Uveď format simple-store.product.v1 a language (např. cs).');
        }
        $key = $data['product_key'] ?? null;
        if (array_key_exists('product_key', $data) && (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1)) {
            throw new InvalidArgumentException('product_key musí být klíč existujícího produktu (32 znaků).');
        }
        if ($key !== null && (!is_int($data['revision'] ?? null) || $data['revision'] < 1)) {
            throw new InvalidArgumentException('Pro úpravu uveď aktuální číslo revision z exportu.');
        }
        if ($key === null && array_key_exists('revision', $data)) throw new InvalidArgumentException('Nový produkt nemá revision.');
        return $data;
    }

    /** Omitted fields survive updates; explicitly supplied collections replace the entire collection. */
    public static function fields(array $data, ?array $current = null): array
    {
        $updating = isset($data['product_key']);
        if ($updating && ($current === null || ($current['product_key'] ?? null) !== $data['product_key'] ||
            ($current['language'] ?? null) !== $data['language'] || (int) ($current['revision_number'] ?? 0) !== $data['revision'])) {
            throw new InvalidArgumentException('Produkt neexistuje nebo se mezitím změnil. Získej nový export.');
        }
        $fields = $current === null ? [
            'language' => $data['language'], 'name' => '', 'slug' => '', 'brand' => '',
            'summary' => '', 'description' => '', 'category_path' => '', 'price_czk' => '',
            'image_path' => 'images/product-placeholder.png', 'stock_status' => 'out_of_stock',
            'published' => false, 'sizes' => '', 'gallery' => '', 'option_name' => [], 'option_values' => [],
            'spec_name' => [], 'spec_value' => [], 'section_type' => [], 'section_heading' => [], 'section_body' => [],
        ] : ProductInlineEditor::fromRevision($current);
        $product = $data['product'];
        self::keys($product, array_merge(self::TEXT, ['price_czk', 'published', 'gallery', 'options', 'specifications', 'sections', 'suppliers']));
        foreach (self::TEXT as $field) {
            if (!array_key_exists($field, $product)) continue;
            if (!is_string($product[$field])) throw new InvalidArgumentException($field . ' musí být text.');
            $fields[$field] = trim($product[$field]);
        }
        if (array_key_exists('price_czk', $product)) {
            if (!is_int($product['price_czk']) || $product['price_czk'] < 1 || $product['price_czk'] > 10000000) {
                throw new InvalidArgumentException('price_czk musí být celé číslo od 1 do 10 000 000.');
            }
            $fields['price_czk'] = (string) $product['price_czk'];
        }
        if (array_key_exists('published', $product)) {
            if (!is_bool($product['published'])) throw new InvalidArgumentException('published musí být true nebo false.');
            $fields['published'] = $product['published'];
        }
        if ($fields['name'] === '' || $fields['category_path'] === '' || $fields['price_czk'] === '') {
            throw new InvalidArgumentException('Vyplň name, category_path a price_czk.');
        }
        if ($fields['slug'] === '') $fields['slug'] = Slugger::fromTitle($fields['name']);
        if (preg_match('/^.{1,255}$/usD', $fields['name']) !== 1 || preg_match('/^.{0,120}$/usD', $fields['brand']) !== 1 ||
            strlen($fields['description']) > 65535 || strlen($fields['slug']) > 190 ||
            preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $fields['slug']) !== 1) {
            throw new InvalidArgumentException('Zkontroluj název (255 znaků), značku (120 znaků), popis (65 535 bajtů) a slug (190 znaků, malá písmena, čísla a pomlčky).');
        }
        CardSummary::validate($fields['summary']);
        if (!in_array($fields['stock_status'], ['in_stock', 'on_order', 'out_of_stock'], true)) {
            throw new InvalidArgumentException('Neplatný stock_status.');
        }
        if (!ProductDetails::imagePath($fields['image_path'])) throw new InvalidArgumentException('Neplatná adresa obrázku.');
        if (array_key_exists('gallery', $product)) {
            self::list($product['gallery'], 'gallery');
            foreach ($product['gallery'] as $image) {
                if (!is_string($image) || str_contains($image, "\n")) throw new InvalidArgumentException('gallery obsahuje neplatnou adresu.');
            }
            $fields['gallery'] = implode("\n", $product['gallery']);
        }
        foreach (['options' => ['name' => 'option_name', 'values' => 'option_values'],
            'specifications' => ['name' => 'spec_name', 'value' => 'spec_value'],
            'sections' => ['type' => 'section_type', 'heading' => 'section_heading', 'body' => 'section_body']] as $collection => $mapping) {
            if (!array_key_exists($collection, $product)) continue;
            self::list($product[$collection], $collection);
            foreach ($mapping as $target) $fields[$target] = [];
            foreach ($product[$collection] as $row) {
                if (!is_array($row)) throw new InvalidArgumentException($collection . ' musí obsahovat objekty.');
                self::keys($row, array_keys($mapping));
                foreach ($mapping as $source => $target) {
                    $value = $row[$source] ?? ($source === 'heading' ? '' : null);
                    if ($source === 'values') {
                        self::list($value, 'values');
                        foreach ($value as $choice) {
                            if (!is_string($choice) || str_contains($choice, "\n")) throw new InvalidArgumentException('Každá varianta musí být jeden text.');
                        }
                        $value = implode("\n", $value);
                    }
                    if (!is_string($value)) throw new InvalidArgumentException($collection . '.' . $source . ' musí být text.');
                    $fields[$target][] = $value;
                }
            }
        }
        self::suppliers($data);
        ProductDetails::fromForm($fields);
        return $fields;
    }

    public static function export(array $row, ?array $suppliers = null): string
    {
        $fields = ProductInlineEditor::fromRevision($row);
        $product = [];
        foreach (self::TEXT as $key) $product[$key] = $fields[$key];
        $product['price_czk'] = (int) $fields['price_czk'];
        $product['published'] = (bool) $fields['published'];
        $product = array_merge($product, ProductDetails::fromForm($fields));
        if ($suppliers !== null) $product['suppliers'] = array_map(static fn (array $link): array => ['label' => $link['label'], 'url' => $link['url']], $suppliers);
        return json_encode(['format' => self::FORMAT, 'product_key' => $row['product_key'],
            'revision' => (int) $row['revision_number'], 'language' => $row['language'], 'product' => $product],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public static function suppliers(array $data): ?array
    {
        if (!array_key_exists('suppliers', $data['product'])) return null;
        $links = $data['product']['suppliers'];
        self::list($links, 'suppliers');
        foreach ($links as $link) {
            if (!is_array($link)) throw new InvalidArgumentException('suppliers musí obsahovat objekty.');
            self::keys($link, ['label', 'url']);
            if (!is_string($link['label'] ?? null) || !is_string($link['url'] ?? null)) throw new InvalidArgumentException('Dodavatel potřebuje label a url.');
            ProductSupplierLinkRepository::validate($link['label'], $link['url']);
        }
        return $links;
    }

    private static function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) throw new InvalidArgumentException('Neznámá pole: ' . implode(', ', array_diff(array_keys($value), $allowed)));
    }

    private static function list(mixed $value, string $name): void
    {
        if (!is_array($value) || ($value !== [] && array_keys($value) !== range(0, count($value) - 1))) {
            throw new InvalidArgumentException($name . ' musí být JSON seznam.');
        }
    }
}
