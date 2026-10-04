<?php
declare(strict_types=1);

use SimpleStore\Admin\StorefrontReturnUrl;
use SimpleStore\Rendering\PageRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

$returnUrl = new StorefrontReturnUrl('/shop/', ['cs'], 'cs');
foreach ([
    ['/shop/cs/produkt/bota?edit=1', '/shop/cs/produkt/bota?edit=1'],
    ['/shop/cs?manage=1&visibility=draft&offset=12', '/shop/cs?manage=1&visibility=draft&offset=12'],
    ['/shop/cs/blog?manage=1&draft_offset=24', '/shop/cs/blog?manage=1&draft_offset=24'],
    ['/shop/cs?search=boty&unknown=1', '/shop/cs?search=boty'],
    ['https://other.example/shop/cs', '/shop/cs'],
    ['//other.example/shop/cs', '/shop/cs'],
    ['/shop/admin.php?section=orders', '/shop/cs'],
    ['/shop/cs/%0d%0aLocation:evil', '/shop/cs'],
    ['/elsewhere/cs', '/shop/cs'],
] as [$input, $expected]) {
    if ($returnUrl->fromRequest($input) !== $expected) {
        throw new RuntimeException('Preview redirect escaped the storefront or lost its page: ' . $input);
    }
}

$renderer = new PageRenderer(dirname(__DIR__) . '/view');
$preview = ['active' => true, 'csrf' => 'preview-token', 'return_to' => '/shop/cs?manage=1'];
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'adminPreview' => $preview]);
$visitorHtml = ob_get_clean();
if (!str_contains($visitorHtml, 'class="visitor-preview-toggle"') ||
    !str_contains($visitorHtml, 'name="enabled" value="0"') ||
    !str_contains($visitorHtml, 'name="csrf" value="preview-token"') ||
    str_contains($visitorHtml, 'site-admin-bar') || str_contains($visitorHtml, 'product-admin-nav') ||
    str_contains($visitorHtml, 'inline-editor-config')) {
    throw new RuntimeException('Visitor preview must render public catalog controls with only a return button.');
}

ob_start();
$renderer->render('blog', ['basePath' => '/shop/', 'language' => 'cs',
    'adminPreview' => $preview, 'draftPosts' => [['title' => 'Soukromý koncept', 'slug' => 'koncept']]]);
$blogHtml = ob_get_clean();
if (str_contains($blogHtml, 'Soukromý koncept') || str_contains($blogHtml, 'Nový článek') ||
    !str_contains($blogHtml, 'Ukončit náhled')) {
    throw new RuntimeException('Visitor preview must not display draft posts or blog administration.');
}

ob_start();
$renderer->render('cart', ['basePath' => '/shop/', 'language' => 'cs',
    'adminPreview' => $preview]);
$cartHtml = ob_get_clean();
if (!str_contains($cartHtml, 'class="visitor-preview-toggle"') ||
    str_contains($cartHtml, 'site-admin-bar')) {
    throw new RuntimeException('Checkout must retain the preview return control without admin chrome.');
}

ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs',
    'canManageCatalog' => true, 'adminCreate' => ['csrf' => 'preview-token', 'language' => 'cs'],
    'adminPreview' => ['active' => false, 'csrf' => 'preview-token', 'return_to' => '/shop/cs']]);
$adminHtml = ob_get_clean();
if (!str_contains($adminHtml, 'site-admin-bar') ||
    !str_contains($adminHtml, 'Zobrazit jako návštěvník') ||
    str_contains($adminHtml, 'visitor-preview-toggle')) {
    throw new RuntimeException('Administrator must be able to enter visitor preview in one step.');
}

echo "Administrator visitor preview passed.\n";
