<?php if ($editing): ?>
<details class="product-admin-settings product-admin-recommendations relation-editor" id="product-relations-admin" <?= $relationSearch !== '' || isset($_GET['relation_saved']) || isset($_GET['relation_error']) ? 'open' : '' ?>>
  <summary><?= \SimpleStore\Rendering\Icon::render('package') ?> Související produkty (<?= count($relationAdmin) ?>)</summary>
  <p>Propojení se zobrazuje u obou produktů. Poznámkou vysvětli, jak se doplňují; samotné propojení neznamená ověřenou kompatibilitu.</p>
  <?php if (isset($_GET['relation_saved'])): ?><p role="status">Propojení uloženo.</p><?php endif; ?>
  <?php if (is_string($_GET['relation_error'] ?? null)): ?><p role="alert"><?= $escape($_GET['relation_error']) ?></p><?php endif; ?>
  <?php foreach ($relationAdmin as $linked): ?>
  <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>" class="relation-edit-row">
    <input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="action" value="product-relation">
    <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>">
    <input type="hidden" name="target" value="<?= $escape($linked['product_key']) ?>"><input type="hidden" name="expected" value="<?= $escape(json_encode($linked['note'], JSON_THROW_ON_ERROR)) ?>">
    <a href="<?= $escape($siteRoot . $language . '/produkt/' . $linked['slug'] . '?edit=1') ?>"><?= $escape($linked['name']) ?></a><?php if (!$linked['published']): ?><small>Koncept — zákazník ho neuvidí</small><?php endif; ?>
    <label>Poznámka k propojení<input name="note" maxlength="500" value="<?= $escape($linked['note']) ?>"></label>
    <button class="inline-small" name="operation" value="save"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit</button>
    <button class="inline-small" name="operation" value="remove"><?= \SimpleStore\Rendering\Icon::render('delete') ?> Odebrat propojení</button>
  </form>
  <?php endforeach; ?>
  <form method="get" class="relation-edit-row" action="<?= $escape($siteRoot . $language . '/produkt/' . $product['slug']) ?>#product-relations-admin">
    <input type="hidden" name="edit" value="1"><label>Najít produkt k propojení<input type="search" name="relation_search" maxlength="200" value="<?= $escape($relationSearch) ?>" required></label>
    <button class="inline-small"><?= \SimpleStore\Rendering\Icon::render('search') ?> Hledat</button>
  </form>
  <?php if ($relationSearch !== '' && $relationCandidates === []): ?><p>Žádné další produkty nenalezeny.</p><?php endif; ?>
  <?php if ($relationCandidates !== []): ?>
  <form method="post" action="<?= $escape($siteRoot . 'admin.php') ?>" class="relation-edit-row">
    <input type="hidden" name="csrf" value="<?= $escape($editToken) ?>"><input type="hidden" name="action" value="product-relation">
    <input type="hidden" name="key" value="<?= $escape($product['product_key']) ?>"><input type="hidden" name="language" value="<?= $escape($language) ?>"><input type="hidden" name="expected" value="null">
    <label>Výsledek hledání<select name="target"><?php foreach ($relationCandidates as $candidate): ?><option value="<?= $escape($candidate['product_key']) ?>"><?= $escape($candidate['name']) ?><?= $candidate['published'] ? '' : ' — koncept' ?></option><?php endforeach; ?></select></label>
    <label>Jak se produkty doplňují<input name="note" maxlength="500" placeholder="Např. filtr se našroubuje přímo na závit vaku."></label>
    <button class="inline-small" name="operation" value="save"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Propojit oběma směry</button>
    <?php if ($relationMore): ?><small>Zobrazeno prvních 48 výsledků. Upřesni hledaný název.</small><?php endif; ?>
  </form>
  <?php endif; ?>
</details>
<?php endif; ?>
