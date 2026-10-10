<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Product\ProductImport;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductStockRepository;

// Authentication and CSRF validation are performed by admin.php before routing here.
$screen = 'product-import';
$importError = '';
$importPreview = null;
$importJson = is_string($_POST['json'] ?? null) ? $_POST['json'] : '';
$language = $_GET['language'] ?? $site['default_language'];
if (!is_string($language) || !in_array($language, $site['languages'], true)) $language = $site['default_language'];
$categories = new CategoryRepository($db);
$repository = new ProductRepository($db, $site['languages'], $categories, $site['revision_limit'], new ProductStockRepository($db));
try {
    if ($method === 'POST') {
        $importData = ProductImport::decode($importJson);
        $language = $importData['language'];
        if (!in_array($language, $site['languages'], true)) throw new InvalidArgumentException('Neplatný jazyk produktu.');
        $key = $importData['product_key'] ?? null;
        $current = $key === null ? null : $repository->current($key, $language);
        $importPreview = ProductImport::fields($importData, $current);
        if ($categories->find($language, $importPreview['category_path']) === null) {
            throw new InvalidArgumentException('category_path neodpovídá aktivní kategorii. Použij cestu ze seznamu níže.');
        }
        if ($action === 'product-import-save') {
            $repository->saveRevision($importPreview, $key, $importData['revision'] ?? null);
            header('Location: ' . $basePath . $language . '/produkt/' . $importPreview['slug'] . '?edit=1', true, 303);
            exit;
        }
    } elseif (is_string($_GET['key'] ?? null)) {
        $key = $_GET['key'];
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) throw new InvalidArgumentException('Neplatný klíč produktu.');
        $current = $repository->current($key, $language);
        if ($current === null) throw new InvalidArgumentException('Produkt neexistuje.');
        $importJson = ProductImport::export($current);
    } elseif (($_GET['example'] ?? '') === 'flextail') {
        $importJson = (string) file_get_contents(__DIR__ . '/../Product/examples/flextail.json');
    }
} catch (InvalidArgumentException | RuntimeException $exception) {
    $importError = $exception->getMessage();
    $importPreview = null;
}
if (!in_array($language, $site['languages'], true)) $language = $site['default_language'];
$importCategories = $categories->allForAdmin($language);
