<?php
declare(strict_types=1);

// Shared document and store chrome. Each area supplies its own body view and data.
$language ??= 'cs';
$basePath ??= '/';
$siteRoot = htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageTitle ??= 'Dobrodruzi.cz — vybavení na každou cestu';
$pageDescription ??= 'Batohy, stany, spacáky a vybavení na cesty ven.';
$privatePage ??= false;
$panelStylesheet ??= false;
$compactHeader ??= false;
$heroTitle ??= 'Vybavení na každou cestu.';
$heroSubtitle ??= 'Výběr toho nejlepšího ultralehkého vybavení pro nomády a cestovatele.';
$skipTarget ??= 'produkty';
$primaryMenu ??= [];
$utilityMenu ??= [];
$footerMenu ??= [];
$manualPrimaryMenu ??= false;
$manualUtilityMenu ??= false;
$manualFooterMenu ??= false;
$canManageMenu ??= false;
$menuAdminUrl ??= '';
$searchTerm ??= '';
$searchAction ??= $basePath . $language;
$editMode ??= false;
$contentEditMode ??= false;
$page ??= '';
$adminCreate ??= null;
$cartUrl ??= $basePath . $language . '/kosik';
$cartCount ??= 0;
$cartToken ??= '';
?>
<!doctype html>
<html lang="<?= htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<?php require __DIR__ . '/head.php'; ?>
<body class="<?= $panelStylesheet ? 'panel-page' : 'store-page' ?>">
<?php require __DIR__ . '/header.php'; ?>
<?php if (($setupNotice ?? '') !== ''): ?>
  <aside class="wrap setup-notice" role="alert"><strong>Pro dokončení nastavení</strong><pre><?= htmlspecialchars(trim($setupNotice), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre></aside>
<?php endif; ?>
<?php if (($debugError ?? '') !== '' && ($showErrors ?? false)): ?>
  <aside class="wrap" role="alert"><pre class="debug-error"><?= htmlspecialchars($debugError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre></aside>
<?php endif; ?>
<?php require $bodyView; ?>
<?php require __DIR__ . '/footer.php'; ?>
<script defer src="<?= $siteRoot ?>assets/app.js?v=<?= filemtime(__DIR__ . '/../assets/app.js') ?>"></script>
<?php if ($page === 'complete' && !empty($bankPayment['spayd'])): ?><script type="module" src="<?= $siteRoot ?>assets/payment-qr.js?v=<?= filemtime(__DIR__ . '/../assets/payment-qr.js') ?>"></script><?php endif; ?>
<?php if ($page === 'catalog' || $page === 'blog'): ?><script defer src="<?= $siteRoot ?>assets/load-more.js"></script><?php endif; ?>
<?php if ($page === 'product-record'): ?><script defer src="<?= $siteRoot ?>assets/product.js"></script><?php endif; ?>
<?php if ($editMode): ?><script defer src="<?= $siteRoot ?>assets/inline-editor.js?v=<?= filemtime(__DIR__ . '/../assets/inline-editor.js') ?>"></script><?php endif; ?>
<?php if ($contentEditMode): ?><script defer src="<?= $siteRoot ?>assets/content-editor.js?v=<?= filemtime(__DIR__ . '/../assets/content-editor.js') ?>"></script><?php endif; ?>
<?php if ($editMode || $contentEditMode || ($mediaManager ?? false)): ?><script defer src="<?= $siteRoot ?>assets/media-manager.js?v=<?= filemtime(__DIR__ . '/../assets/media-manager.js') ?>"></script><?php endif; ?>
</body>
</html>
