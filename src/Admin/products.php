<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Product\ProductInlineEditor;
use SimpleStore\Product\ProductRepository;

// This controller is reached only after admin.php has checked the session and CSRF token.
$screen = 'products';
$categories = new CategoryRepository($db);
$repository = new ProductRepository($db, $site['languages'], $categories);
$productError = '';
$productSchemaReady = $categories->installed() && $repository->detailsColumnExists();
if ($method === 'POST') {
    $language = $_POST['language'] ?? null;
    if (!$productSchemaReady || !is_string($language) || !in_array($language, $site['languages'], true)) {
        throw new InvalidArgumentException('Nejprve importujte database/schema.sql a vyberte platný jazyk.');
    }
    $category = $categories->find($language, 'batohy') ?? $categories->children($language)[0] ?? null;
    if ($category === null) {
        throw new InvalidArgumentException('Nejdříve založte alespoň jednu kategorii v tomto jazyce.');
    }
    $starter = ProductInlineEditor::starter($language, $category['path']);
    $repository->saveRevision($starter);
    header('Location: ' . $basePath . $language . '/produkt/' . $starter['slug'] . '?edit=1', true, 303);
    exit;
}
$productRows = $repository->currentProducts();
