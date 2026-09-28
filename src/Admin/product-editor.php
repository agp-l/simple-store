<?php
declare(strict_types=1);

use SimpleStore\Product\ProductRepository;

// This controller is included only after admin.php has checked the session and CSRF token.
if (!isset($auth) || !$auth->signedIn()) {
    http_response_code(403);
    exit;
}

$screen = 'products';
$productRepository = new ProductRepository($db, $site['languages']);
$productForm = [];
$productHistory = [];
$currentProduct = null;
$productError = '';
$productNotice = '';

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
        'category' => $_POST['category'] ?? '',
        'subcategory' => $_POST['subcategory'] ?? '',
        'price_czk' => $_POST['price_czk'] ?? '',
        'image_path' => $_POST['image_path'] ?? '',
        'sizes' => $_POST['sizes'] ?? '',
        'stock_status' => $_POST['stock_status'] ?? '',
        'published' => isset($_POST['published']),
    ];
    $language = $productForm['language'];
    try {
        foreach ($productForm as $field => $value) {
            if ($field !== 'published' && !is_string($value)) {
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
            : ($site['debug'] ? $exception->getMessage() : 'Produkt se nepodařilo uložit. Zkontroluj údaje.');
        foreach ($productForm as $field => $value) {
            if (!is_string($value) && $field !== 'published') {
                $productForm[$field] = '';
            }
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
            $productNotice = 'Zobrazuje se starší verze. Uložením vznikne nová revize.';
        }
    } else {
        $productForm = ['language' => $language, 'category' => 'batohy',
            'stock_status' => 'in_stock', 'published' => false];
    }
}

if (isset($_GET['saved'])) {
    $productNotice = 'Produkt byl uložen jako nová revize.';
}
$productRows = $productRepository->currentProducts();
