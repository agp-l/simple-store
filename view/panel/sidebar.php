<?php
// Customer navigation retains the storefront panel layout.
$escapePanel = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<aside class="panel-sidebar">
  <div class="panel-sidebar-heading"><span><?= $escapePanel($panelHeading) ?></span><small><?= $escapePanel($panelSubtitle) ?></small></div>
  <nav aria-label="<?= $escapePanel($panelHeading) ?>">
    <?php foreach ($panelLinks as $item): ?>
      <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($item['href']) ?>" <?= $panelCurrent === $item['key'] ? 'aria-current="page"' : '' ?>><span aria-hidden="true"><?= isset($item['icon_name']) ? \SimpleStore\Rendering\Icon::render($item['icon_name']) : $escapePanel($item['icon']) ?></span> <?= $escapePanel($item['label']) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="panel-sidebar-foot"><span class="panel-status-dot"></span> <?= $escapePanel($panelIdentity) ?>
    <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($panelLogoutUrl) ?>"><input type="hidden" name="csrf" value="<?= $escapePanel($csrf) ?>"><button class="panel-quiet" type="submit" name="action" value="logout"><?= \SimpleStore\Rendering\Icon::render('logout') ?> Odhlásit se</button></form>
  </div>
</aside>
