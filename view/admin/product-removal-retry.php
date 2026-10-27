<form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="delete-product"><input type="hidden" name="phase" value="cleanup"><input type="hidden" name="key" value="<?= $escape($r['product_key']) ?>">
  <button class="panel-button" type="submit">Dokončit úklid fotografií</button>
</form>
