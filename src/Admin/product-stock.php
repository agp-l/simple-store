<?php
declare(strict_types=1);

use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductStockRepository;

// admin.php has verified the administrator and CSRF token.
$key = $_POST['key'] ?? null;
$language = $_POST['language'] ?? null;
$expected = $_POST['expected'] ?? null;
$quantity = $_POST['quantity'] ?? null;
if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
    !is_string($language) || !in_array($language, $site['languages'], true) ||
    !is_string($expected) || !ctype_digit($expected) ||
    !is_string($quantity) || !ctype_digit($quantity) ||
    (int) $expected > 1000000 || (int) $quantity > 1000000) {
    throw new InvalidArgumentException('Zadej platný počet kusů skladem.');
}
$stock = new ProductStockRepository($db);
if (!$stock->installed()) {
    throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
}
$product = (new ProductRepository($db, $site['languages']))->current($key, $language);
if ($product === null) throw new InvalidArgumentException('Produkt už neexistuje.');
$stock->setAvailable($key, (int) $expected, (int) $quantity);
header('Location: ' . $basePath . $language . '/produkt/' . rawurlencode($product['slug']) . '?edit=1&stock_saved=1', true, 303);
exit;
