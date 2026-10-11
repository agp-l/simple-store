<?php if (($selectionTargets ?? []) !== []): ?>
<section class="product-admin-settings">
  <strong><?= \SimpleStore\Rendering\Icon::render('check') ?> Doporučené produkty</strong>
  <?php if (($_GET['selection_saved'] ?? '') === '1'): ?><p role="status">Výběr byl uložen.</p><?php endif; ?>
  <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>" class="selection-add-form">
    <input type="hidden" name="csrf" value="<?= $escape($editToken) ?>">
    <input type="hidden" name="action" value="product-selection">
    <input type="hidden" name="language" value="<?= $escape($language) ?>">
    <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>">
    <label>Přidat do<select name="scope">
    <?php foreach ($selectionTargets as $target): ?><option value="<?= $escape($target['scope']) ?>"><?= $escape($target['label']) ?><?= $target['selected'] ? ' — již vybráno' : '' ?></option><?php endforeach; ?>
    </select></label>
    <button class="inline-small" name="operation" value="add"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Přidat</button>
    <button class="inline-small" name="operation" value="remove">Odebrat z výběru</button>
  </form>
  <?php if (empty($product['published'])): ?><p>Pro přidání produkt nejprve zveřejni.</p><?php endif; ?>
</section>
<?php endif; ?>
