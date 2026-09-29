<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use SimpleStore\Media\MediaPath;

/** Optional product fields are stored as one snapshot alongside each product revision. */
final class ProductDetails
{
    public static function imagePath(string $path): bool
    {
        return strlen($path) <= 1000 && (
            preg_match('~^images/[A-Za-z0-9_-]+\.(?:webp|jpg|jpeg|png|avif)$~iD', $path) === 1 ||
            MediaPath::isAsset($path) ||
            (filter_var($path, FILTER_VALIDATE_URL) && parse_url($path, PHP_URL_SCHEME) === 'https')
        );
    }

    public static function decode(?string $json, string $legacySizes = ''): array
    {
        $data = json_decode($json ?? '', true);
        if (!self::validSnapshot($data)) {
            $sizes = array_values(array_filter(array_map('trim', explode(',', $legacySizes)), 'strlen'));
            return ['options' => $sizes ? [['name' => 'Velikost', 'values' => $sizes]] : [],
                'specifications' => [], 'sections' => [], 'gallery' => []];
        }
        return ['options' => array_values($data['options']),
            'specifications' => array_values($data['specifications']),
            'sections' => array_values($data['sections']),
            'gallery' => array_values($data['gallery'])];
    }

    /** Imported or damaged JSON must not break public views or introduce unsafe image URLs. */
    private static function validSnapshot(mixed $data): bool
    {
        if (!is_array($data) || !isset($data['options'], $data['specifications'], $data['sections'], $data['gallery'])) {
            return false;
        }
        foreach (['options', 'specifications', 'sections', 'gallery'] as $key) {
            if (!is_array($data[$key])) return false;
        }
        foreach ($data['options'] as $option) {
            if (!is_array($option) || !is_string($option['name'] ?? null) ||
                !is_array($option['values'] ?? null) || $option['values'] === []) return false;
            foreach ($option['values'] as $value) {
                if (!is_string($value)) return false;
            }
        }
        foreach ($data['specifications'] as $specification) {
            if (!is_array($specification) || !is_string($specification['name'] ?? null) ||
                !is_string($specification['value'] ?? null)) return false;
        }
        foreach ($data['sections'] as $section) {
            if (!is_array($section) || !in_array($section['type'] ?? null, ['text', 'list', 'table', 'image'], true) ||
                !is_string($section['heading'] ?? null) || !is_string($section['body'] ?? null) ||
                ($section['type'] === 'image' && !self::imagePath($section['body']))) return false;
        }
        foreach ($data['gallery'] as $path) {
            if (!is_string($path) || !self::imagePath($path)) return false;
        }
        return true;
    }

    public static function fromForm(array $form): array
    {
        $data = ['options' => [], 'specifications' => [], 'sections' => [], 'gallery' => []];
        $gallery = self::field($form, 'gallery');
        foreach (preg_split('/\R/u', $gallery) ?: [] as $line) {
            $path = trim($line);
            if ($path === '') continue;
            if (!self::imagePath($path) || count($data['gallery']) >= 12) {
                throw new InvalidArgumentException('Galerie: použij nejvýše 12 obrázků ve složce images/ nebo HTTPS adresy.');
            }
            $data['gallery'][] = $path;
        }

        $names = self::rows($form, 'option_name');
        $values = self::rows($form, 'option_values');
        if (count($names) !== count($values) || count($names) > 8) {
            throw new InvalidArgumentException('Každá skupina výběru potřebuje název a hodnoty (nejvýše 8 skupin).');
        }
        $seen = [];
        foreach ($names as $index => $name) {
            $name = trim($name);
            $raw = trim($values[$index]);
            if ($name === '' && $raw === '') continue;
            if ($name === '' || $raw === '' || strlen($name) > 80 || isset($seen[strtolower($name)])) {
                throw new InvalidArgumentException('Výběr: doplň jedinečný název a možnosti na samostatných řádcích.');
            }
            $seen[strtolower($name)] = true;
            $choices = array_values(array_filter(array_map('trim', preg_split('/\R/u', $raw) ?: []), 'strlen'));
            if (count($choices) < 1 || count($choices) > 50 || count(array_unique($choices)) !== count($choices)) {
                throw new InvalidArgumentException('Výběr musí obsahovat 1 až 50 různých možností.');
            }
            foreach ($choices as $choice) {
                if (strlen($choice) > 120) throw new InvalidArgumentException('Možnost výběru je příliš dlouhá.');
            }
            $data['options'][] = ['name' => $name, 'values' => $choices];
        }

        $labels = self::rows($form, 'spec_name');
        $values = self::rows($form, 'spec_value');
        if (count($labels) !== count($values) || count($labels) > 40) {
            throw new InvalidArgumentException('Technické údaje musí mít název i hodnotu (nejvýše 40 řádků).');
        }
        foreach ($labels as $index => $label) {
            $label = trim($label);
            $value = trim($values[$index]);
            if ($label === '' && $value === '') continue;
            if ($label === '' || $value === '' || strlen($label) > 120 || strlen($value) > 500) {
                throw new InvalidArgumentException('Technický údaj vyžaduje krátký název i hodnotu.');
            }
            $data['specifications'][] = ['name' => $label, 'value' => $value];
        }

        $types = self::rows($form, 'section_type');
        $headings = self::rows($form, 'section_heading');
        $bodies = self::rows($form, 'section_body');
        if (count($types) !== count($headings) || count($types) !== count($bodies) || count($types) > 30) {
            throw new InvalidArgumentException('Každý blok popisu potřebuje typ, nadpis a text (nejvýše 30 bloků).');
        }
        foreach ($types as $index => $type) {
            $heading = trim($headings[$index]);
            $body = trim($bodies[$index]);
            if ($heading === '' && $body === '') continue;
            if (!in_array($type, ['text', 'list', 'table', 'image'], true) || strlen($heading) > 255 ||
                $body === '' || strlen($body) > 30000) {
                throw new InvalidArgumentException('Blok popisu potřebuje text a volitelný nadpis.');
            }
            if ($type === 'image' && !self::imagePath($body)) {
                throw new InvalidArgumentException('Obrázek v popisu: použij cestu images/… nebo HTTPS adresu.');
            }
            if ($type === 'table') {
                foreach (preg_split('/\R/u', $body) ?: [] as $line) {
                    if (trim($line) !== '' && count(explode('|', $line)) !== 2) {
                        throw new InvalidArgumentException('Řádek tabulky piš jako „Název | Hodnota“.');
                    }
                }
            }
            $data['sections'][] = compact('type', 'heading', 'body');
        }
        return $data;
    }

    private static function field(array $form, string $key): string
    {
        if (!isset($form[$key])) return '';
        if (!is_string($form[$key])) throw new InvalidArgumentException('Neplatný údaj produktu.');
        return $form[$key];
    }

    private static function rows(array $form, string $key): array
    {
        $rows = $form[$key] ?? [];
        if (!is_array($rows)) throw new InvalidArgumentException('Neplatné řádky produktu.');
        foreach ($rows as $row) {
            if (!is_string($row)) throw new InvalidArgumentException('Neplatný údaj produktu.');
        }
        return array_values($rows);
    }
}
