<?php
declare(strict_types=1);

use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductSupplierLinkRepository;

// admin.php has already checked administrator login and the CSRF token.
if (($_POST['operation'] ?? '') === 'inherit-all') {
    $count = (new \SimpleStore\Product\ProductVariantSupplierRepository($db))->inheritAll();
    $_SESSION['variant_links_inherited'] = $count;
    header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($basePath . 'admin/?section=product-import&tab=export'), true, 303);
    exit;
}
$key = $_POST['key'] ?? null;
$language = $_POST['language'] ?? null;
$rawId = $_POST['id'] ?? '0';
$operation = $_POST['operation'] ?? null;
if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
    !is_string($language) || !in_array($language, $site['languages'], true) ||
    !is_string($rawId) || !ctype_digit($rawId) || strlen($rawId) > 18 ||
    !in_array($operation, ['save', 'remove', 'inherit', 'variant-save', 'variant-remove'], true)) {
    throw new InvalidArgumentException('Neplatný požadavek na odkaz dodavatele.');
}
$product = (new ProductRepository($db, $site['languages']))->current($key, $language);
if ($product === null) throw new InvalidArgumentException('Produkt už neexistuje.');
$returnUrl = $basePath . $language . '/produkt/' . rawurlencode($product['slug']) . '?edit=1';
$links = new ProductSupplierLinkRepository($db);
if (!$links->installed()) {
    header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($returnUrl . '&supplier_error=missing#supplier-links'), true, 303);
    exit;
}
try {
    if ($operation === 'inherit') {
        (new \SimpleStore\Product\ProductVariantSupplierRepository($db))->inherit($key);
    } elseif (in_array($operation, ['variant-save', 'variant-remove'], true)) {
        $variant = $_POST['variant_key'] ?? ''; $linkKey = $_POST['link_key'] ?? ''; $partner = $_POST['partner_key'] ?? '';
        $version = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_string($variant) || !is_string($linkKey) || !is_string($partner) || $version === false) throw new InvalidArgumentException('Neplatný odkaz varianty.');
        $variantLinks = new \SimpleStore\Product\ProductVariantSupplierRepository($db);
        if ($operation === 'variant-remove') $variantLinks->remove($key, $variant, $linkKey, $version);
        else {
            $label = $_POST['label'] ?? null; $url = $_POST['url'] ?? null;
            if (!is_string($label) || !is_string($url)) throw new InvalidArgumentException('Vyplň název a adresu partnera.');
            $variantLinks->save($key, $variant, $linkKey, $version, $partner, trim($label), trim($url));
        }
    } elseif (array_key_exists('expected_link', $_POST)) {
        $expected = $_POST['expected_link']; $label = $_POST['label'] ?? ''; $url = $_POST['url'] ?? '';
        if (!is_string($expected) || !is_string($label) || !is_string($url)) throw new InvalidArgumentException('Neplatný partnerský odkaz.');
        $links->changeChecked($key, (int) $rawId, $expected, $operation === 'remove', trim($label), trim($url));
    } elseif ($operation === 'remove') {
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
$variantOperation = $operation === 'inherit' || str_starts_with($operation, 'variant-');
$returnAnchor = $variantOperation ? '#variant-supplier-links' : '#supplier-links';
if ($variantOperation) {
    $variantKey = is_string($_POST['variant_key'] ?? null) ? $_POST['variant_key'] : 'all';
    $returnUrl .= '&supplier_variant=' . rawurlencode($variantKey);
    if (preg_match('/^[a-f0-9]{32}$/D', $variantKey)) $returnAnchor = '#variant-suppliers-' . $variantKey;
}
header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($returnUrl . $returnAnchor), true, 303);
exit;
