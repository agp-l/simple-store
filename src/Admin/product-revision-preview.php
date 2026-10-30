<?php
declare(strict_types=1);

// Read-only branch of the storefront product route. Never decorate with live stock/sales.
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');
$previewNumber = filter_var($_GET['preview_revision'] ?? null, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]);
if (!$canEdit || !$editRequested || $previewNumber === false) {
    $renderer->render('not-found', array_merge($shared, ['privatePage'=>true]), 404);
    return;
}
$historyRepository = new \SimpleStore\Product\ProductRepository($db, $site['languages']);
$currentProduct = $historyRepository->findCurrentBySlug($route['slug'], $url->getLanguage());
$previewProduct = $currentProduct === null ? null : $historyRepository->revision(
    $currentProduct['product_key'], $url->getLanguage(), $previewNumber);
if ($previewProduct === null) {
    $renderer->render('not-found', array_merge($shared, ['privatePage'=>true]), 404);
    return;
}
$renderer->render('product-record', array_merge($shared, [
    'title'=>'Náhled revize '.$previewNumber.' — '.$previewProduct['name'],
    'description'=>$previewProduct['summary'] ?? '', 'product'=>$previewProduct,
    'revisionPreview'=>true, 'privatePage'=>true,
    'revisionReturnUrl'=>$url->path('produkt/'.rawurlencode($currentProduct['slug'])).'?edit=1&history=1#product-history',
    'canEditProduct'=>false, 'editMode'=>false, 'stockReady'=>false,
    'adminCreate'=>null, 'adminPreview'=>null, 'canManageMenu'=>false,
    'selectedVariant'=>null, 'relatedProducts'=>[], 'categoryTrail'=>[],
    'priceDisplay'=>null, 'deliveryEstimate'=>null,
]));
