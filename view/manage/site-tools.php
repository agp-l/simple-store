<?php
// Render only with the administrator's authenticated creation context.
$toolsEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$toolsLanguage = (string) $adminCreate['language'];
$toolsRoot = $basePath . $toolsLanguage;
?>
<nav class="site-admin-bar" aria-label="Správa webu">
  <div class="wrap site-admin-inner">
    <strong><?= \SimpleStore\Rendering\Icon::render('settings') ?> Správa webu</strong>
    <div class="site-admin-group" role="group" aria-label="Vytvořit obsah">
      <?php foreach (['product' => 'Produkt', 'page' => 'Stránka', 'post' => 'Článek'] as $toolsType => $toolsLabel): ?>
        <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . 'admin/') ?>">
          <input type="hidden" name="csrf" value="<?= $toolsEscape($adminCreate['csrf']) ?>">
          <input type="hidden" name="language" value="<?= $toolsEscape($toolsLanguage) ?>">
          <?php if ($toolsType !== 'product'): ?><input type="hidden" name="type" value="<?= $toolsType ?>"><?php endif; ?>
          <button type="submit" name="action" value="<?= $toolsType === 'product' ? 'create-product' : 'create-content' ?>"><?= \SimpleStore\Rendering\Icon::render('plus') ?> <?= $toolsLabel ?></button>
        </form>
      <?php endforeach; ?>
    </div>
    <div class="site-admin-group" role="group" aria-label="Spravovat obsah">
      <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($toolsRoot . '?manage=1') ?>"><?= \SimpleStore\Rendering\Icon::render('package') ?> Produkty a koncepty</a>
      <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($toolsRoot . '?homepage_edit=1#homepage-editor') ?>" <?= ($homepageEditing ?? false) ? 'aria-current="page"' : '' ?>><?= \SimpleStore\Rendering\Icon::render('check') ?> Výběr na úvodní stránku</a>
      <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . 'admin/?section=contents') ?>"><?= \SimpleStore\Rendering\Icon::render('document') ?> Stránky</a>
      <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($toolsRoot . '/blog?manage=1') ?>"><?= \SimpleStore\Rendering\Icon::render('book') ?> Blog</a>
      <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . 'admin/') ?>"><?= \SimpleStore\Rendering\Icon::render('settings') ?> Další obsah a nastavení</a>
    </div>
    <?php if ($adminPreview !== null): ?>
      <form class="site-admin-preview" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . 'admin/') ?>">
        <input type="hidden" name="csrf" value="<?= $toolsEscape($adminPreview['csrf']) ?>">
        <input type="hidden" name="enabled" value="1">
        <input type="hidden" name="return_to" value="<?= $toolsEscape($adminPreview['return_to']) ?>">
        <button type="submit" name="action" value="visitor-preview"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit jako návštěvník</button>
      </form>
    <?php endif; ?>
  </div>
</nav>
