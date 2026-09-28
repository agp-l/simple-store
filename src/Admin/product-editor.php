<?php
declare(strict_types=1);

use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductDetails;
use SimpleStore\Category\CategoryPath;
use SimpleStore\Category\CategoryRepository;

// This controller is included only after admin.php has checked the session and CSRF token.
if (!isset($auth) || !$auth->signedIn()) {
    http_response_code(403);
    exit;
}

$screen = 'products';
$categories = new CategoryRepository($db);
$productRepository = new ProductRepository($db, $site['languages'], $categories);
$productForm = [];
$productHistory = [];
$currentProduct = null;
$productError = '';
$productNotice = '';
$productSchemaReady = true;

if ($method === 'POST') {
    $key = $_POST['key'] ?? '';
    $revision = $_POST['revision'] ?? '';
    $productForm = [
        'language' => $_POST['language'] ?? '',
        'slug' => $_POST['slug'] ?? '',
        'name' => $_POST['name'] ?? '',
        'brand' => $_POST['brand'] ?? '',
        'summary' => $_POST['summary'] ?? '',
        'description' => $_POST['description'] ?? '',
        'category_path' => $_POST['category_path'] ?? '',
        'price_czk' => $_POST['price_czk'] ?? '',
        'image_path' => $_POST['image_path'] ?? '',
        'sizes' => $_POST['sizes'] ?? '',
        'stock_status' => $_POST['stock_status'] ?? '',
        'published' => isset($_POST['published']),
        'gallery' => $_POST['gallery'] ?? '',
        'option_name' => $_POST['option_name'] ?? [],
        'option_values' => $_POST['option_values'] ?? [],
        'spec_name' => $_POST['spec_name'] ?? [],
        'spec_value' => $_POST['spec_value'] ?? [],
        'section_type' => $_POST['section_type'] ?? [],
        'section_heading' => $_POST['section_heading'] ?? [],
        'section_body' => $_POST['section_body'] ?? [],
    ];
    $language = $productForm['language'];
    try {
        foreach ($productForm as $field => $value) {
            if ($field !== 'published' && !in_array($field, ['option_name', 'option_values', 'spec_name', 'spec_value',
                'section_type', 'section_heading', 'section_body'], true) && !is_string($value)) {
                throw new InvalidArgumentException('Neplatný údaj produktu.');
            }
        }
        if (!is_string($key) || !is_string($revision) ||
            !in_array($language, $site['languages'], true) ||
            ($key !== '' && (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
                filter_var($revision, FILTER_VALIDATE_INT) === false))) {
            throw new InvalidArgumentException('Neplatný produkt, jazyk nebo číslo revize.');
        }
        $saved = $productRepository->saveRevision($productForm, $key === '' ? null : $key,
            $key === '' ? null : (int) $revision);
        header('Location: ' . $adminUrl . '?' . http_build_query([
            'section' => 'products', 'key' => $saved['product_key'],
            'language' => $saved['language'], 'saved' => 1,
        ]), true, 303);
        exit;
    } catch (Throwable $exception) {
        error_log((string) $exception);
        $productError = $exception->getMessage() === 'This product changed since you opened it. Reload before saving.'
            ? 'Produkt se mezitím změnil. Znovu ho načti před uložením.'
            : (str_starts_with($exception->getMessage(), 'V databázi chybí product_revisions.details_json.')
                ? $exception->getMessage()
                : ($site['debug'] ? $exception->getMessage() : 'Produkt se nepodařilo uložit. Zkontroluj údaje.'));
        foreach ($productForm as $field => $value) {
            if (!is_string($value) && $field !== 'published' && !is_array($value)) {
                $productForm[$field] = '';
            }
        }
        foreach (['option_name', 'option_values', 'spec_name', 'spec_value',
            'section_type', 'section_heading', 'section_body'] as $field) {
            if (!is_array($productForm[$field])) {
                $productForm[$field] = [];
            }
            $productForm[$field] = array_map(static fn ($value): string => is_string($value) ? $value : '',
                array_values($productForm[$field]));
        }
        $productForm['product_key'] = is_string($key) ? $key : '';
        $productForm['revision_number'] = is_string($revision) ? $revision : '';
        if (is_string($key) && preg_match('/^[a-f0-9]{32}$/D', $key) === 1 &&
            is_string($language) && in_array($language, $site['languages'], true)) {
            $currentProduct = $productRepository->current($key, $language);
            $productHistory = $productRepository->history($key, $language);
        }
    }
} else {
    $key = $_GET['key'] ?? '';
    $language = $_GET['language'] ?? $site['default_language'];
    if (!is_string($key) || !is_string($language) || !in_array($language, $site['languages'], true) ||
        ($key !== '' && preg_match('/^[a-f0-9]{32}$/D', $key) !== 1)) {
        throw new InvalidArgumentException('Neplatný produkt nebo jazyk.');
    }
    if ($key !== '') {
        $currentProduct = $productRepository->current($key, $language);
        if ($currentProduct === null) {
            throw new InvalidArgumentException('Produkt v tomto jazyce neexistuje.');
        }
        $productHistory = $productRepository->history($key, $language);
        $productForm = $currentProduct;
        $productForm['category_path'] = CategoryPath::fromProduct($productForm);
        if (isset($_GET['restore'])) {
            $number = filter_var($_GET['restore'], FILTER_VALIDATE_INT);
            $old = $number === false ? null : $productRepository->revision($key, $language, (int) $number);
            if ($old === null) {
                throw new InvalidArgumentException('Požadovaná revize neexistuje.');
            }
            $productForm = array_merge($old, [
                'product_key' => $key,
                'revision_number' => $currentProduct['revision_number'],
            ]);
            $productForm['category_path'] = CategoryPath::fromProduct($productForm);
            $productNotice = 'Zobrazuje se starší verze. Uložením vznikne nová revize.';
        }
    } else {
        $productForm = ['language' => $language, 'category_path' => 'batohy',
            'stock_status' => 'in_stock', 'published' => false];
    }
}

if ($method !== 'POST' || $productError === '') {
    $details = ProductDetails::decode($productForm['details_json'] ?? null, $productForm['sizes'] ?? '');
    $productForm['gallery'] = implode("\n", $details['gallery']);
    $productForm['option_name'] = array_column($details['options'], 'name');
    $productForm['option_values'] = array_map(static fn (array $group): string => implode("\n", $group['values']), $details['options']);
    $productForm['spec_name'] = array_column($details['specifications'], 'name');
    $productForm['spec_value'] = array_column($details['specifications'], 'value');
    $productForm['section_type'] = array_column($details['sections'], 'type');
    $productForm['section_heading'] = array_column($details['sections'], 'heading');
    $productForm['section_body'] = array_column($details['sections'], 'body');
}

if (isset($_GET['saved'])) {
    $productNotice = 'Produkt byl uložen jako nová revize.';
}
if ($method !== 'POST' && !$productRepository->detailsColumnExists()) {
    $productSchemaReady = false;
    $productError = 'V databázi chybí product_revisions.details_json. V phpMyAdmin vyber databázi '
        . 'z config/database.php a spusť: ALTER TABLE product_revisions '
        . 'ADD COLUMN details_json LONGTEXT NULL AFTER description; Potom stránku obnov.';
}
if (!$categories->installed()) {
    $productSchemaReady = false;
    $productError = 'Chybí tabulka katalogu kategorií. Znovu importuj aktuální database/schema.sql do databáze z config/database.php.';
}
$categoryOptions = [];
$appendCategories = static function (array $nodes, int $depth) use (&$appendCategories, &$categoryOptions): void {
    foreach ($nodes as $node) {
        $categoryOptions[] = ['path' => $node['path'], 'title' => str_repeat('— ', $depth) . $node['title']];
        $appendCategories($node['children'], $depth + 1);
    }
};
$appendCategories($categories->tree(is_string($language) && in_array($language, $site['languages'], true)
    ? $language : $site['default_language']), 0);
$productRows = $productRepository->currentProducts();
