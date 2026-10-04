<?php
// The page and list use the same revision-checked delete action.
if (!$canManageContent || $adminCsrf === '' ||
    !isset($deletablePost['document_key'], $deletablePost['revision_number'])) return;
?>
<details class="inline-blog-delete"><summary aria-label="Smazat článek <?= $escape($deletablePost['title']) ?>">▣ Smazat</summary>
  <form method="post" action="<?= $escape($basePath . 'admin.php') ?>">
    <input type="hidden" name="csrf" value="<?= $escape($adminCsrf) ?>">
    <input type="hidden" name="action" value="delete-content">
    <input type="hidden" name="type" value="post">
    <input type="hidden" name="key" value="<?= $escape($deletablePost['document_key']) ?>">
    <input type="hidden" name="language" value="<?= $escape($language) ?>">
    <input type="hidden" name="revision" value="<?= (int) $deletablePost['revision_number'] ?>">
    <label><input type="checkbox" name="confirm" value="1" required> Potvrzuji smazání článku a jeho revizí.</label>
    <button type="submit" class="inline-small">Smazat článek</button>
  </form>
</details>
