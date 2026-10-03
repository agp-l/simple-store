<?php
declare(strict_types=1);

use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductSupplierLinkRepository;

// admin.php has already checked administrator login and the CSRF token.
$key = $_POST['key'] ?? null;
$language = $_POST['language'] ?? null;
$rawId = $_POST['id'] ?? '0';
$operation = $_POST['operation'] ?? null;
if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
    !is_string($language) || !in_array($language, $site['languages'], true) ||
    !is_string($rawId) || !ctype_digit($rawId) || strlen($rawId) > 18 ||
    !in_array($operation, ['save', 'remove'], true)) {
    throw new InvalidArgumentException('Neplatný požadavek na odkaz dodavatele.');
}
$product = (new ProductRepository($db, $site['languages']))->current($key, $language);
if ($product === null) throw new InvalidArgumentException('Produkt už neexistuje.');
$returnUrl = $basePath . $language . '/produkt/' . rawurlencode($product['slug']) . '?edit=1';
$links = new ProductSupplierLinkRepository($db);
if (!$links->installed()) {
    header('Location: ' . $returnUrl . '&supplier_error=missing#supplier-links', true, 303);
    exit;
}
try {
    if ($operation === 'remove') {
        $links->remove($key, (int) $rawId);
    } else {
        $label = $_POST['label'] ?? null;
        $url = $_POST['url'] ?? null;
        if (!is_string($label) || !is_string($url)) {
            throw new InvalidArgumentException('Vyplň název dodavatele a webovou adresu.');
        }
        $links->save($key, (int) $rawId, trim($label), trim($url));
    }
    $returnUrl .= '&supplier_saved=1';
} catch (InvalidArgumentException $exception) {
    $returnUrl .= '&supplier_error=' . rawurlencode($exception->getMessage());
}
header('Location: ' . $returnUrl . '#supplier-links', true, 303);
exit;
