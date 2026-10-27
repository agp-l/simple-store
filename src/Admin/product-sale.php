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
if (($_POST['create_partner'] ?? '') === '1') $_POST['partner_id'] = '0';
// PHP-only fallback for the same bulk inheritance action available in the editor.
if (($_POST['inherit_variant_prices'] ?? '') === '1') $_POST['variant_price'] = [];
try {
    (new ProductSalesRepository($db))->save($key, $language, $_POST);
    unset($_SESSION['product_sale_errors'][$key . ':' . $language]);
    $returnUrl .= '&sale_saved=1';
} catch (Throwable $error) {
    if (!$error instanceof InvalidArgumentException) error_log((string) $error);
    $form = [];
    foreach (['mode', 'version', 'supplier_price_czk', 'partner_id',
        'partner_name', 'partner_ico', 'partner_address', 'partner_email', 'partner_website_url', 'partner_terms_url', 'partner_confirmed'] as $field) {
        $form[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }
    $form['variant_price'] = is_array($_POST['variant_price'] ?? null)
        ? array_filter($_POST['variant_price'], 'is_string') : [];
    foreach (['variant_pricing','variant_pricing_expected'] as $field)
        if (isset($_POST[$field]) && is_string($_POST[$field])) $form[$field] = $_POST[$field];
    $_SESSION['product_sale_errors'][$key . ':' . $language] = ['form' => $form,
        'message' => $error instanceof InvalidArgumentException ? $error->getMessage()
            : 'Uložení se nezdařilo. Údaje zůstaly ve formuláři; zkus jej znovu uložit.'];
}
header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($returnUrl . '#product-sale'), true, 303);
exit;
