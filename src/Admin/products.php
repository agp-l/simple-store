<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Product\ProductInlineEditor;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductStockRepository;

// This controller is reached only after admin.php has checked the session and CSRF token.
if ($method !== 'POST') {
    header('Location: ' . $basePath . $site['default_language'] . '?manage=1#produkty', true, 303);
    exit;
}
$categories = new CategoryRepository($db);
$stock = new ProductStockRepository($db);
$repository = new ProductRepository($db, $site['languages'], $categories, $site['revision_limit'], $stock);
$productSchemaReady = $categories->installed() && $repository->detailsColumnExists() && $stock->installed();
$language = $_POST['language'] ?? null;
if (!$productSchemaReady || !is_string($language) || !in_array($language, $site['languages'], true)) {
    throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky v sekci Databáze a vyber platný jazyk.');
}
$category = $categories->find($language, 'batohy') ?? $categories->children($language)[0] ?? null;
if ($category === null) {
    throw new InvalidArgumentException('Nejdříve založte alespoň jednu kategorii v tomto jazyce.');
}
$starter = ProductInlineEditor::starter($language, $category['path']);
$repository->saveRevision($starter);
header('Location: ' . $basePath . $language . '/produkt/' . $starter['slug'] . '?edit=1', true, 303);
exit;
