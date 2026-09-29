<?php
declare(strict_types=1);

// Keep all document chrome in view/shell.php; this view chooses only admin data.
$language = $site['default_language'];
$pageTitle = 'Administrace · dobrodruzi';
$pageDescription = 'Správa obchodu Dobrodruzi';
$privatePage = true;
$panelStylesheet = true;
$compactHeader = true;
$heroTitle = in_array($screen, ['editor', 'products', 'categories', 'menus'], true)
    ? 'Správa obchodu' : 'Vstup do administrace';
$heroSubtitle = 'Obsah, produkty a navigace na jednom místě.';
$skipTarget = 'obsah';
$primaryMenu = $chrome['primaryMenu'] ?? [];
$utilityMenu = $chrome['utilityMenu'] ?? [];
$footerMenu = $chrome['footerMenu'] ?? [];
$manualPrimaryMenu = $chrome['manualPrimaryMenu'] ?? false;
$manualUtilityMenu = $chrome['manualUtilityMenu'] ?? false;
$manualFooterMenu = $chrome['manualFooterMenu'] ?? false;
$searchAction = $basePath . $language;
$bodyView = __DIR__ . '/panel.php';
require __DIR__ . '/../shell.php';
