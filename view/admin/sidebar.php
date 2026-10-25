<?php
// Administration owns its navigation; the customer account keeps panel/sidebar.php.
$adminLinks = array_column($panelLinks, null, 'key');
$adminGroups = [
    'Prodej' => ['orders', 'products', 'purchase-partners', 'supplier-prices', 'returns', 'accounting'],
    'Obsah a katalog' => ['editor', 'categories', 'menus', 'media', 'product-import'],
    'Správa' => ['settings', 'users', 'database'],
];
?>
<aside class="panel-sidebar" id="admin-sidebar" aria-label="Menu administrace" tabindex="-1">
  <button class="admin-menu-close" type="button" data-admin-close hidden>Zavřít menu <span aria-hidden="true">×</span></button>
  <nav class="admin-navigation" aria-label="Správa webu">
    <?php foreach ($adminGroups as $group => $keys): ?>
      <div class="admin-nav-group">
        <p class="admin-nav-label"><?= $escape($group) ?></p>
        <?php foreach ($keys as $key): $item = $adminLinks[$key]; ?>
          <a data-admin-link="<?= $escape($key) ?>" href="<?= \SimpleStore\Navigation\PanelUrl::escape($item['href']) ?>" <?= $panelCurrent === $key ? 'aria-current="page"' : '' ?>><span aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render($item['icon_name']) ?></span><?= $escape($item['label']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>
  <div class="admin-account">
    <span class="admin-account-avatar" aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render('users') ?></span>
    <span><strong>Správce obchodu</strong><small><i class="panel-status-dot" aria-hidden="true"></i> Přihlášeno</small></span>
    <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>">
      <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
      <button type="submit" name="action" value="logout" aria-label="Odhlásit se" title="Odhlásit se"><?= \SimpleStore\Rendering\Icon::render('logout') ?></button>
    </form>
  </div>
</aside>
