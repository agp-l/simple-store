<?php
declare(strict_types=1);

use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductSalesRepository;

// Administrator authentication and CSRF are enforced by admin.php.
$key = $_POST['key'] ?? null;
$language = $_POST['language'] ?? null;
if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
    !is_string($language) || !in_array($language, $site['languages'], true)) {
    throw new InvalidArgumentException('Neplatný produkt.');
}
$product = (new ProductRepository($db, $site['languages']))->current($key, $language);
if ($product === null) throw new InvalidArgumentException('Produkt už neexistuje.');
$returnUrl = $basePath . $language . '/produkt/' . rawurlencode($product['slug']) . '?edit=1';
try {
    (new ProductSalesRepository($db))->save($key, $language, $_POST);
    $returnUrl .= '&sale_saved=1';
} catch (InvalidArgumentException $error) {
    $returnUrl .= '&sale_error=' . rawurlencode($error->getMessage());
}
header('Location: ' . $returnUrl . '#product-sale', true, 303);
exit;
