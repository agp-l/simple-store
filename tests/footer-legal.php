<?php
declare(strict_types=1);

use SimpleStore\Rendering\PageRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

$renderer = new PageRenderer(dirname(__DIR__) . '/view');
$data = ['basePath' => '/shop/', 'language' => 'cs', 'products' => []];
ob_start();
$renderer->render('catalog', $data);
$empty = ob_get_clean();
if (str_contains($empty, 'aria-label="Odkazy v patičce"') ||
    str_contains($empty, '/shop/cs/obchodni-podminky')) {
    throw new RuntimeException('Unpublished legal pages must not be linked in the footer.');
}

ob_start();
$renderer->render('catalog', $data + ['footerMenu' => [
    ['label' => 'Obchodní podmínky', 'href' => '/shop/cs/obchodni-podminky', 'children' => []],
    ['label' => 'Kontakt', 'href' => '/shop/cs/kontakt', 'children' => []],
], 'footerTitle' => 'Pro zákazníky']);
$published = ob_get_clean();
if (!str_contains($published, 'aria-label="Odkazy v patičce"') ||
    !str_contains($published, '<h2>Pro zákazníky</h2>') ||
    !str_contains($published, 'href="/shop/cs/obchodni-podminky"') ||
    !str_contains($published, 'href="/shop/cs/kontakt"') ||
    str_contains($published, 'Prozkoumat') || str_contains($published, 'Na cestu') ||
    str_contains($published, '/shop/cs/reklamacni-rad')) {
    throw new RuntimeException('The footer must link only to published CMS pages.');
}

ob_start();
$renderer->render('catalog', $data + ['priceDisplay' => new \SimpleStore\Pricing\BitcoinPriceDisplay([
    'rate' => 2000000.0, 'updated_at' => '2026-10-03 07:20:20',
])]);
$bitcoinFooter = ob_get_clean();
if (str_contains($bitcoinFooter, 'BTC přepočet je orientační') ||
    str_contains($bitcoinFooter, 'Data poskytuje') ||
    !str_contains($bitcoinFooter, 'Na další cestu připraveni.')) {
    throw new RuntimeException('The public footer must not show the exchange rate notice.');
}

echo "Footer legal links passed.\n";
