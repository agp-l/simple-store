<?php
// Shared by article cards and the article editor; never rendered for visitors.
$placementKey = $placementPost['document_key'] ?? '';
if (preg_match('/^[a-f0-9]{32}$/D', $placementKey) === 1):
$pinned = in_array($placementKey, $blogPlacement['pinned'], true);
$featured = $blogPlacement['featured'] === $placementKey;
$isPublished = !array_key_exists('published', $placementPost) || !empty($placementPost['published']);
?>
<form class="blog-placement-controls" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath.'admin/') ?>" aria-label="Umístění článku <?= $escape($placementPost['title']) ?>">
  <input type="hidden" name="action" value="blog-placement">
  <input type="hidden" name="csrf" value="<?= $escape($placementToken) ?>">
  <input type="hidden" name="language" value="<?= $escape($language) ?>">
  <input type="hidden" name="key" value="<?= $escape($placementKey) ?>">
  <input type="hidden" name="expected" value="<?= $escape(\SimpleStore\Content\BlogPlacement::fingerprint($blogPlacement)) ?>">
  <input type="hidden" name="return_to" value="<?= $escape($placementReturn) ?>">
  <strong><?= $featured ? 'Hlavní článek · první místo' : ($pinned ? 'Připnutý článek' : 'Umístění v blogu') ?></strong>
  <div>
    <button class="inline-small" name="operation" value="<?= $pinned ? 'unpin' : 'pin' ?>" aria-pressed="<?= $pinned ? 'true' : 'false' ?>" <?= !$pinned && !$isPublished ? 'disabled' : '' ?>><?= \SimpleStore\Rendering\Icon::render('pushpin') ?> <?= $pinned ? 'Odepnout' : 'Připnout' ?></button>
    <button class="inline-small" name="operation" value="<?= $featured ? 'unfeature' : 'feature' ?>" aria-pressed="<?= $featured ? 'true' : 'false' ?>" <?= !$featured && !$isPublished ? 'disabled' : '' ?>><?= \SimpleStore\Rendering\Icon::render('star') ?> <?= $featured ? 'Zrušit první místo' : 'Zobrazovat jako první' ?></button>
  </div>
  <?php if (!$isPublished): ?><small>Připnutí se návštěvníkům projeví až po zveřejnění článku.</small><?php endif; ?>
</form>
<?php endif; ?>
