<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#252927">
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
  <?php if (in_array($page ?? '', ['cart', 'shipping', 'payment', 'review', 'complete'], true)): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/checkout.css?v=<?= filemtime(__DIR__ . '/../assets/checkout.css') ?>"><?php endif; ?>
  <?php if ($editMode || $contentEditMode || ($mediaManager ?? false)): ?><link rel="stylesheet" href="<?= $siteRoot ?>assets/media.css?v=<?= filemtime(__DIR__ . '/../assets/media.css') ?>"><?php endif; ?>
  <?php if ($panelStylesheet): ?>
    <link rel="stylesheet" href="<?= $siteRoot ?>assets/panel.css?v=<?= filemtime(__DIR__ . '/../assets/panel.css') ?>">
    <link rel="stylesheet" href="<?= $siteRoot ?>assets/panel-orders.css?v=<?= filemtime(__DIR__ . '/../assets/panel-orders.css') ?>">
  <?php endif; ?>
</head>
