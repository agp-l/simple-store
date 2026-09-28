<?php
declare(strict_types=1);

use SimpleStore\Rendering\PageRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

$renderer = new PageRenderer(dirname(__DIR__) . '/view');
ob_start();
$renderer->render('catalog', ['basePath' => '/shop/', 'language' => 'cs']);
$html = ob_get_clean();

if (!str_contains($html, 'Zobrazeno 0 produktů') ||
    !str_contains($html, 'Zatím tu nejsou zveřejněné produkty') ||
    !str_contains($html, 'href="/shop/cs"') ||
    str_contains($html, 'produkt-topo-terraventure.php') ||
    str_contains($html, 'data-add')) {
    throw new RuntimeException('The empty catalog must not display old sample products or leave the language.');
}

echo "Catalog rendering tests passed.\n";
