<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryPath;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Media\MediaLibrary;
use SimpleStore\Product\ProductRepository;

// admin.php has already checked the admin session.
$screen = 'media';
$categoryRepository = new CategoryRepository($db);
$productRepository = new ProductRepository($db, $site['languages'], $categoryRepository, $site['revision_limit']);
$mediaTargets = [];
$categoryNames = [];
foreach ($productRepository->currentProducts() as $row) {
    $path = CategoryPath::fromProduct($row);
    if (!isset($categoryNames[$row['language']])) {
        $categoryNames[$row['language']] = array_column(
            $categoryRepository->all($row['language']), 'title', 'path');
    }
    $mediaTargets[] = [
        'group' => 'Produkty · ' . ($categoryNames[$row['language']][$path] ?? $path),
        'label' => $row['name'] . ' (' . $row['language'] . ')',
        'type' => 'product', 'key' => $row['product_key'], 'language' => $row['language'],
        'revision' => (int) $row['revision_number'],
        'editUrl' => $basePath . $row['language'] . '/produkt/' . rawurlencode($row['slug']) . '?edit=1',
    ];
}
foreach ($content->currentDocuments() as $row) {
    $mediaTargets[] = [
        'group' => $row['type'] === 'post' ? 'Články' : 'Stránky',
        'label' => $row['title'] . ' (' . $row['language'] . ')',
        'type' => $row['type'], 'key' => $row['document_key'], 'language' => $row['language'],
        'revision' => (int) $row['revision_number'],
        'editUrl' => $basePath . $row['language'] . ($row['type'] === 'post' ? '/blog' : '') .
            '/' . rawurlencode($row['slug']) . '?edit=1',
    ];
}
usort($mediaTargets, static fn (array $a, array $b): int =>
    strcmp($a['group'], $b['group']) ?: strcmp($a['label'], $b['label']));

$selectedMedia = null;
$requestedType = $_GET['type'] ?? null;
$requestedKey = $_GET['key'] ?? null;
$requestedLanguage = $_GET['language'] ?? null;
foreach ($mediaTargets as $target) {
    if ($target['type'] === $requestedType && $target['key'] === $requestedKey &&
        $target['language'] === $requestedLanguage) {
        $selectedMedia = $target;
        break;
    }
}
$selectedMedia ??= $mediaTargets[0] ?? null;
$mediaFiles = $selectedMedia === null ? [] :
    (new MediaLibrary(__DIR__ . '/../..'))->files($selectedMedia['type'], $selectedMedia['key']);
