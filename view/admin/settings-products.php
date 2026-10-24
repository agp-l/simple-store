<?php declare(strict_types=1); ?>
<section class="panel-panel panel-settings-block"><h2>Prodávající pro asistovaný nákup</h2>
<p>Spravuj společné profily prodávajících a rozpracované kontakty pro výběr u produktů.</p>
<a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners') ?>">Seznam prodávajících a nový prodávající</a></section>
<?php if (is_string($massNotice)): ?><p class="panel-notice" role="status"><?= $escape($massNotice) ?></p><?php endif; ?>
<?php require __DIR__ . '/settings-product-sales.php'; ?>
<section class="panel-panel panel-settings-block">
  <h2><?= \SimpleStore\Rendering\Icon::render('package') ?> Hromadná úprava produktů</h2>
  <p>Změna platí pro všechny produkty včetně konceptů a všech jazyků. Počet produktů: <strong><?= (int) $massCount ?></strong>.</p>
  <p class="panel-help">Počet znamená volné kusy k prodeji. U produktu se skladem variant se zadaný počet i dostupnost nastaví každé nabízené variantě zvlášť. Překlady sdílejí stejný sklad. Po inventuře zadej počet bez kusů rezervovaných pro objednávky. Rezervace se nemění; při stornu objednávky se její rezervované kusy vrátí na sklad.</p>
  <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=products') ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <input type="hidden" name="tab" value="products">
    <input type="hidden" name="action" value="products-mass-preview">
    <label>Volné kusy každého produktu<input type="number" name="quantity" min="0" max="1000000" step="1" placeholder="Ponechat beze změny" value="<?= $escape($massValues['quantity']) ?>"></label>
    <label>Dostupnost<select name="stock_status">
      <?php foreach (['' => 'Ponechat beze změny', 'in_stock' => 'Skladem', 'on_order' => 'Na objednávku', 'out_of_stock' => 'Není skladem'] as $value => $label): ?>
      <option value="<?= $escape($value) ?>" <?= $massValues['stock_status'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
      <?php endforeach; ?>
    </select></label>
    <label>Viditelnost<select name="visibility">
      <?php foreach (['' => 'Ponechat beze změny', 'public' => 'Zveřejnit všechny produkty', 'hidden' => 'Skrýt všechny produkty'] as $value => $label): ?>
      <option value="<?= $escape($value) ?>" <?= $massValues['visibility'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
      <?php endforeach; ?>
    </select></label>
    <p class="panel-help">„Skladem“ vyžaduje alespoň jeden volný kus. „Na objednávku“ umožňuje objednání i při nulovém počtu. Prázdné pole počtu sklad nemění; nula jej vynuluje.</p>
    <button class="panel-action panel-button" type="submit" <?= $massCount === 0 ? 'disabled' : '' ?>><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit náhled změny</button>
  </form>
</section>
<?php if ($massPreview !== null): ?>
<section class="panel-panel panel-settings-block">
  <h2>Potvrzení pro <?= (int) $massPreview['count'] ?> produktů</h2>
  <ul>
    <?php if ($massPreview['choices']['quantity'] !== ''): ?><li>Každému produktu nastavit <strong><?= (int) $massPreview['choices']['quantity'] ?> volných kusů</strong>.</li><?php endif; ?>
    <?php if ($massPreview['choices']['stock_status'] !== ''): ?><li>Dostupnost: <strong><?= $escape(['in_stock' => 'Skladem', 'on_order' => 'Na objednávku', 'out_of_stock' => 'Není skladem'][$massPreview['choices']['stock_status']]) ?></strong>.</li><?php endif; ?>
    <?php if ($massPreview['choices']['visibility'] !== ''): ?><li><strong><?= $massPreview['choices']['visibility'] === 'public' ? 'Zveřejnit všechny produkty včetně konceptů.' : 'Skrýt všechny produkty.' ?></strong></li><?php endif; ?>
  </ul>
  <p>Ostatní údaje, fotografie a dodavatelé zůstanou zachované.</p>
  <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=products') ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <input type="hidden" name="tab" value="products">
    <input type="hidden" name="action" value="products-mass-apply">
    <input type="hidden" name="preview_token" value="<?= $escape($massPreview['token']) ?>">
    <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Potvrdit hromadnou změnu</button>
    <a class="panel-action" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=products') ?>">Zrušit</a>
  </form>
</section>
<?php endif; ?>

<section class="panel-panel panel-settings-block" id="bulk-product-variants">
  <h2><?= \SimpleStore\Rendering\Icon::render('package') ?> Hromadné vytvoření variant</h2>
  <?php if (($settingsError ?? '') !== '' && in_array($_POST['action'] ?? '', ['products-variants-preview', 'products-variants-apply'], true)): ?><p class="panel-error" role="alert"><?= $escape($settingsError) ?></p><?php endif; ?>
  <p>Pro začátek obchodu: vytvoří všechny kombinace uložených voleb, například velikostí a barev, u produktů, které zatím nemají varianty. Každá nová varianta dostane zadaný počet volných kusů.</p>
  <p class="panel-help">Existující varianty se přeskočí. Produkty bez voleb a produkty s rezervovanými kusy se také přeskočí. Zahrnou se i koncepty; zůstanou skryté. Volby se čtou z výchozího jazyka obchodu, případně z prvního dostupného překladu. Cena i fotografie se převezmou z hlavního produktu; konkrétní obrázky můžeš přiřadit později.</p>
  <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=products#bulk-product-variants') ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="products"><input type="hidden" name="action" value="products-variants-preview">
    <label>Volné kusy každé nové varianty<input name="variant_quantity" type="number" min="0" max="1000000" step="1" required value="<?= $escape($variantBulkQuantity) ?>"></label>
    <p class="panel-help">Například 3 velikosti × 2 barvy = 6 variant. Při 10 kusech vznikne sklad 60 kusů. Dosavadní společný počet se při přepnutí vynuluje, nepřičítá se. Nejvýše 1 000 kombinací na produkt.</p>
    <button class="panel-action panel-button" type="submit" <?= $massCount === 0 ? 'disabled' : '' ?>><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit náhled vytvoření variant</button>
  </form>
  <?php if ($variantBulkPreview !== null): ?>
  <h3>Náhled: <?= count($variantBulkPreview['products']) ?> produktů, <?= (int) $variantBulkPreview['variant_count'] ?> nových variant</h3>
  <p>Každá nová varianta: <strong><?= (int) $variantBulkPreview['quantity'] ?> volných kusů</strong>, dostupnost Skladem (při nule ji nelze koupit). Celkem <?= number_format($variantBulkPreview['variant_count'] * $variantBulkPreview['quantity'], 0, ',', ' ') ?> kusů. Viditelnost produktů se nezmění.</p>
  <?php if ($variantBulkPreview['products'] !== []): ?>
  <ul><?php foreach ($variantBulkPreview['products'] as $bulkProduct): ?><li><?= $escape($bulkProduct['name']) ?>: <strong><?= (int) $bulkProduct['variants'] ?> variant</strong>; původní společný sklad <?= (int) $bulkProduct['previous_quantity'] ?> ks → 0 ks.</li><?php endforeach; ?></ul>
  <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=products#bulk-product-variants') ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="products"><input type="hidden" name="action" value="products-variants-apply"><input type="hidden" name="preview_token" value="<?= $escape($variantBulkPreview['token']) ?>">
    <label><input type="checkbox" name="confirm_variants" value="1" required> Ověřil jsem kombinace i počet kusů. Zapnout sklad variant a nahradit dosavadní společné počty podle náhledu.</label>
    <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Vytvořit varianty hromadně</button>
  </form>
  <?php else: ?><p role="status">Není co vytvářet. Důvody přeskočení jsou uvedené níže.</p><?php endif; ?>
  <?php if ($variantBulkPreview['skipped'] !== []): ?><details><summary>Přeskočené produkty (<?= count($variantBulkPreview['skipped']) ?>)</summary><ul><?php foreach ($variantBulkPreview['skipped'] as $bulkProduct): ?><li><strong><?= $escape($bulkProduct['name']) ?></strong>: <?= $escape($bulkProduct['reason']) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  <?php endif; ?>
</section>
