<?php
// Store chrome belongs to customer pages; admin actions keep their existing URLs and CSRF.
$adminHeaderEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<a class="skip" href="#obsah">Přejít na obsah</a>
<header class="admin-header" id="nahoru">
  <button class="admin-menu-toggle" type="button" data-admin-menu aria-controls="admin-sidebar" aria-expanded="false" aria-label="Otevřít menu administrace" hidden><?= \SimpleStore\Rendering\Icon::render('list') ?></button>
  <a class="admin-brand" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>"><svg class="admin-brand-mark" width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M3 25 13 6l5 10 4-7 8 16H3Z M10 12l3 5 3-5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg><span><strong>dobrodruzi</strong><small>SPRÁVA OBCHODU</small></span></a>
  <span class="admin-header-caption">Vybavení pro další dobrodružství.</span>
  <div class="admin-header-actions">
    <?php if ($adminCreate !== null): ?>
      <details class="admin-shortcuts">
        <summary aria-label="Rychlé akce"><?= \SimpleStore\Rendering\Icon::render('plus') ?><span>Rychlé akce</span></summary>
        <?php require __DIR__ . '/../manage/site-tools.php'; ?>
      </details>
    <?php endif; ?>
    <details class="admin-store-menu">
      <summary aria-label="Zobrazit e-shop"><?= \SimpleStore\Rendering\Icon::render('external') ?><span>E-shop</span></summary>
      <div class="admin-store-popover">
        <a href="<?= $adminHeaderEscape($basePath . $language) ?>"><?= \SimpleStore\Rendering\Icon::render('arrow-right') ?> Přejít do e-shopu</a>
        <?php if ($panelPreviewControl !== null): ?>
          <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($panelPreviewControl['action']) ?>">
            <input type="hidden" name="csrf" value="<?= $adminHeaderEscape($panelPreviewControl['csrf']) ?>">
            <input type="hidden" name="enabled" value="<?= $panelPreviewControl['active'] ? '0' : '1' ?>">
            <input type="hidden" name="return_to" value="<?= $adminHeaderEscape($panelPreviewControl['return_to']) ?>">
            <button type="submit" name="action" value="visitor-preview"><?= \SimpleStore\Rendering\Icon::render('eye') ?> <?= $panelPreviewControl['active'] ? 'Ukončit náhled' : 'Náhled jako návštěvník' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </details>
  </div>
</header>
<div class="admin-menu-backdrop" data-admin-backdrop hidden></div>
