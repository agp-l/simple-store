<?php
// Store chrome belongs to customer pages; admin actions keep their existing URLs and CSRF.
$adminHeaderEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<a class="skip" href="#obsah">Přejít na obsah</a>
<header class="admin-header" id="nahoru">
  <button class="admin-menu-toggle" type="button" data-admin-menu aria-controls="admin-sidebar" aria-expanded="false" aria-label="Otevřít menu administrace" hidden><?= \SimpleStore\Rendering\Icon::render('list') ?></button>
  <a class="admin-brand" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>"><strong>dobrodruzi</strong><span>Administrace</span></a>
  <div class="admin-header-actions">
    <?php if ($adminCreate !== null): ?>
      <details class="admin-shortcuts">
        <summary aria-label="Rychlé akce"><?= \SimpleStore\Rendering\Icon::render('plus') ?><span>Rychlé akce</span></summary>
        <?php require __DIR__ . '/../manage/site-tools.php'; ?>
      </details>
    <?php endif; ?>
    <a class="admin-store-link" href="<?= $adminHeaderEscape($basePath . $language) ?>" aria-label="Přejít do e-shopu"><?= \SimpleStore\Rendering\Icon::render('arrow-right') ?><span>Přejít do e-shopu</span></a>
  </div>
</header>
<div class="admin-menu-backdrop" data-admin-backdrop hidden></div>
