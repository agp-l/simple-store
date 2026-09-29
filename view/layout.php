<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="<?= htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<?php require __DIR__ . '/head.php'; ?>
<body>
<?php require __DIR__ . '/header.php'; ?>
<?php if ($setupNotice !== ''): ?>
  <aside class="wrap setup-notice" role="alert">
    <strong>Pro dokončení nastavení</strong>
    <pre><?= htmlspecialchars(trim($setupNotice), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
  </aside>
<?php endif; ?>
<?php if ($debugError !== '' && $showErrors): ?>
  <aside class="wrap" role="alert"><pre class="debug-error"><?= htmlspecialchars($debugError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre></aside>
<?php endif; ?>
<?php
// Only known view names reach this switch; no URL can become a file path.
switch ($page) {
    case 'catalog': require __DIR__ . '/body.php'; break;
    case 'product-record': require __DIR__ . '/product-record.php'; break;
    case 'page':
    case 'post': require __DIR__ . '/content-body.php'; break;
    case 'blog': require __DIR__ . '/blog-list.php'; break;
    default: require __DIR__ . '/message.php';
}
?>
<?php require __DIR__ . '/footer.php'; ?>
<?php require __DIR__ . '/cart.php'; ?>
<script defer src="<?= $siteRoot ?>assets/app.js"></script>
<?php if ($page === 'catalog' || $page === 'blog'): ?><script defer src="<?= $siteRoot ?>assets/load-more.js"></script><?php endif; ?>
<?php if ($page === 'product-record'): ?><script defer src="<?= $siteRoot ?>assets/product.js"></script><?php endif; ?>
<?php if ($editMode): ?><script defer src="<?= $siteRoot ?>assets/inline-editor.js"></script><?php endif; ?>
<?php if ($contentEditMode): ?><script defer src="<?= $siteRoot ?>assets/content-editor.js"></script><?php endif; ?>
</body>
</html>
