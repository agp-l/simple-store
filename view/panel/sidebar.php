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
  <?php if (($panelPreviewControl ?? null) !== null): ?>
    <form class="panel-sidebar-preview" method="post" action="<?= $escapePanel($panelPreviewControl['action']) ?>">
      <input type="hidden" name="csrf" value="<?= $escapePanel($panelPreviewControl['csrf']) ?>">
      <input type="hidden" name="enabled" value="<?= $panelPreviewControl['active'] ? '0' : '1' ?>">
      <input type="hidden" name="return_to" value="<?= $escapePanel($panelPreviewControl['return_to']) ?>">
      <button class="panel-quiet" type="submit" name="action" value="visitor-preview"><?= $panelPreviewControl['active'] ? 'Ukončit náhled' : 'Zobrazit web jako návštěvník' ?></button>
    </form>
  <?php endif; ?>
  <div class="panel-sidebar-foot"><span class="panel-status-dot"></span> <?= $escapePanel($panelIdentity) ?>
    <form method="post" action="<?= $escapePanel($panelLogoutUrl) ?>"><input type="hidden" name="csrf" value="<?= $escapePanel($csrf) ?>"><button class="panel-quiet" type="submit" name="action" value="logout">Odhlásit se</button></form>
  </div>
</aside>
