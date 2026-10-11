<?php declare(strict_types=1); ?>
<?php if (is_string($massNotice)): ?><p class="panel-notice" role="status"><?= $escape($massNotice) ?></p><?php endif; ?>
<section class="panel-panel panel-settings-block">
  <h2><?= \SimpleStore\Rendering\Icon::render('package') ?> Hromadná úprava produktů</h2>
  <p>Změna platí pro všechny produkty včetně konceptů a všech jazyků. Počet produktů: <strong><?= (int) $massCount ?></strong>.</p>
  <p class="panel-help">Počet znamená volné kusy k prodeji. U produktu se skladem variant se zadaný počet i dostupnost nastaví každé nabízené variantě zvlášť. Překlady sdílejí stejný sklad. Po inventuře zadej počet bez kusů rezervovaných pro objednávky. Rezervace se nemění; při stornu objednávky se její rezervované kusy vrátí na sklad.</p>
  <form class="panel-form" method="post" action="<?= $escape($adminUrl . '?section=settings&tab=products') ?>">
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
  <form method="post" action="<?= $escape($adminUrl . '?section=settings&tab=products') ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <input type="hidden" name="tab" value="products">
    <input type="hidden" name="action" value="products-mass-apply">
    <input type="hidden" name="preview_token" value="<?= $escape($massPreview['token']) ?>">
    <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Potvrdit hromadnou změnu</button>
    <a class="panel-action" href="<?= $escape($adminUrl . '?section=settings&tab=products') ?>">Zrušit</a>
  </form>
</section>
<?php endif; ?>
