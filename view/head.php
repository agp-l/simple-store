<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#252927">
  <link rel="icon" type="image/svg+xml" href="<?= $siteRoot ?>assets/favicon.svg">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <?php if ($editMode || $contentEditMode || $privatePage): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $siteRoot ?>style.css?v=<?= filemtime(__DIR__ . '/../style.css') ?>">
  <link rel="stylesheet" href="<?= $siteRoot ?>assets/product-detail.css?v=<?= filemtime(__DIR__ . '/../assets/product-detail.css') ?>">
  <link rel="stylesheet" href="<?= $siteRoot ?>assets/inline-edit.css?v=<?= filemtime(__DIR__ . '/../assets/inline-edit.css') ?>">
  <link rel="stylesheet" href="<?= $siteRoot ?>assets/content.css?v=<?= filemtime(__DIR__ . '/../assets/content.css') ?>">
  <?php if (($page ?? '') === 'product-record'): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/product-relations.css?v=<?= filemtime(__DIR__ . '/../assets/product-relations.css') ?>"><?php endif; ?>
  <?php if (($page ?? '') === 'product-record'): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/product-variants.css?v=<?= filemtime(__DIR__ . '/../assets/product-variants.css') ?>"><?php endif; ?>
  <?php if (($page ?? '') === 'catalog'): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/catalog-stories.css?v=<?= filemtime(__DIR__ . '/../assets/catalog-stories.css') ?>"><?php endif; ?>
  <?php if (in_array($page ?? '', ['cart', 'shipping', 'payment', 'review', 'complete'], true)): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/checkout.css?v=<?= filemtime(__DIR__ . '/../assets/checkout.css') ?>"><?php endif; ?>
  <?php if ($editMode || $contentEditMode || ($mediaManager ?? false)): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/media.css?v=<?= filemtime(__DIR__ . '/../assets/media.css') ?>"><?php endif; ?>
  <?php if ($panelStylesheet): ?>
    <link rel="stylesheet" href="<?= $siteRoot ?>assets/panel.css?v=<?= filemtime(__DIR__ . '/../assets/panel.css') ?>">
    <link rel="stylesheet" href="<?= $siteRoot ?>assets/panel-orders.css?v=<?= filemtime(__DIR__ . '/../assets/panel-orders.css') ?>">
    <link rel="stylesheet" href="<?= $siteRoot ?>assets/panel-actions.css?v=<?= filemtime(__DIR__ . '/../assets/panel-actions.css') ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= $siteRoot ?>assets/editor-panel.css?v=<?= filemtime(__DIR__ . '/../assets/editor-panel.css') ?>">
  <?php if (($page ?? '') === 'product-record' && $editMode): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/product-sale.css?v=<?= filemtime(__DIR__ . '/../assets/product-sale.css') ?>"><?php endif; ?>
  <?php if ($productCardEffects ?? false): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/product-card-effects.css?v=<?= filemtime(__DIR__ . '/../assets/product-card-effects.css') ?>"><?php endif; ?>
  <?php if (in_array($page ?? '', ['catalog', 'product-record'], true)): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/product-selection.css?v=<?= filemtime(__DIR__ . '/../assets/product-selection.css') ?>"><?php endif; ?>
  <link rel="stylesheet" href="<?= $siteRoot ?>assets/prices.css?v=<?= filemtime(__DIR__ . '/../assets/prices.css') ?>">
  <?php if ($adminWorkspace ?? false): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/admin-layout.css?v=<?= filemtime(__DIR__ . '/../assets/admin-layout.css') ?>"><?php endif; ?>
</head>
