<?php
declare(strict_types=1);

// Both private areas use the same store shell and panel assets.
$language = $site['default_language'];
$pageTitle = $panelTitle;
$pageDescription = $panelDescription;
$privatePage = true;
$panelStylesheet = true;
$compactHeader = true;
$heroTitle = $panelHeroTitle;
$heroSubtitle = $panelHeroSubtitle;
$skipTarget = 'obsah';
$primaryMenu = $chrome['primaryMenu'] ?? [];
$utilityMenu = $chrome['utilityMenu'] ?? [];
$footerMenu = $chrome['footerMenu'] ?? [];
$footerTitle = $chrome['footerTitle'] ?? 'Informace';
$manualPrimaryMenu = $chrome['manualPrimaryMenu'] ?? false;
$manualUtilityMenu = $chrome['manualUtilityMenu'] ?? false;
$manualFooterMenu = $chrome['manualFooterMenu'] ?? false;
$searchAction = $basePath . $language;
require __DIR__ . '/../shell.php';
