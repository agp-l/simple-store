<?php
// The two private areas share one navigation component and visual hierarchy.
$escapePanel = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<aside class="panel-sidebar">
  <div class="panel-sidebar-heading"><span><?= $escapePanel($panelHeading) ?></span><small><?= $escapePanel($panelSubtitle) ?></small></div>
  <nav aria-label="<?= $escapePanel($panelHeading) ?>">
    <?php foreach ($panelLinks as $item): ?>
      <a href="<?= $escapePanel($item['href']) ?>" <?= $panelCurrent === $item['key'] ? 'aria-current="page"' : '' ?>><span aria-hidden="true"><?= $escapePanel($item['icon']) ?></span> <?= $escapePanel($item['label']) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="panel-sidebar-foot"><span class="panel-status-dot"></span> <?= $escapePanel($panelIdentity) ?>
    <form method="post" action="<?= $escapePanel($panelLogoutUrl) ?>"><input type="hidden" name="csrf" value="<?= $escapePanel($csrf) ?>"><button class="panel-quiet" type="submit" name="action" value="logout">Odhlásit se</button></form>
  </div>
</aside>
