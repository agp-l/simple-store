<?php
declare(strict_types=1);

use SimpleStore\Product\ProductRelationRepository;
use SimpleStore\Product\ProductRepository;

// Shared admin authentication and CSRF validation precede this handler.
$key = $_POST['key'] ?? null; $language = $_POST['language'] ?? null;
$target = $_POST['target'] ?? null; $note = $_POST['note'] ?? ''; $operation = $_POST['operation'] ?? '';
$expected = $_POST['expected'] ?? null;
if (!is_string($key) || !is_string($language) || !is_string($target) || !is_string($note) || !is_string($expected) || !in_array($operation, ['save', 'remove'], true)) throw new InvalidArgumentException('Neplatné propojení.');
$relations = new ProductRelationRepository($db, $site['languages']);
$relations->pair($language, $key, $target, trim($note));
$current = (new ProductRepository($db, $site['languages']))->current($key, $language);
if ($current === null) throw new InvalidArgumentException('Produkt neexistuje.');
$return = $basePath . $language . '/produkt/' . rawurlencode($current['slug']) . '?edit=1';
try {
    $old = json_decode($expected, true, 2, JSON_THROW_ON_ERROR);
    if ($old !== null && !is_string($old)) throw new InvalidArgumentException('Neplatná původní poznámka.');
    $relations->apply($language, $key, [['product_key' => $target, 'note' => trim($note), 'expected' => $old]], $operation === 'remove');
    $return .= '&relation_saved=1';
} catch (InvalidArgumentException | JsonException $error) {
    $return .= '&relation_error=' . rawurlencode($error->getMessage());
}
header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($return . '#product-relations-admin'), true, 303);
exit;
