<?php declare(strict_types=1); ?>
<link rel="stylesheet" href="<?= $escape($basePath . 'assets/product-partner-assignment.css?v=' . filemtime(__DIR__ . '/../../assets/product-partner-assignment.css')) ?>">
<section class="panel-panel panel-settings-block"><h2>Prodávající pro asistovaný nákup</h2>
<p>Spravuj společné profily prodávajících a rozpracované kontakty pro výběr u produktů.</p>
<div class="partner-assignment-links"><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=purchase-partners') ?>">Seznam prodávajících a nový prodávající</a>
<a href="<?= $escape($basePath . $site['default_language'] . '?manage=1#produkty') ?>">Produkty a filtr podle partnera →</a>
<a href="<?= $escape($basePath . $site['default_language'] . '?homepage_edit=1#homepage-editor') ?>">Výběr a pořadí vybavení na úvodu →</a>
<a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=supplier-prices') ?>">Ceny a dostupnost u partnerů →</a></div>
<p class="panel-help">Zde měníš nastavení současných produktů. Automatické kontroly partnerských cen a odkazy variant spravuj v sekci Ceny dodavatelů. Každý nástroj níže má vlastní potvrzení a nemění nastavení ostatních nástrojů.</p>
<nav class="partner-assignment-links" aria-label="Nástroje produktů"><a href="#product-partner-assignment">Přiřazení partnerů</a><a href="#bulk-product-sales">Způsob prodeje</a><a href="#bulk-product-stock">Počty a viditelnost</a><a href="#bulk-product-variants">Vytvoření variant</a></nav></section>
<?php if (is_string($massNotice)): ?><p class="panel-notice" role="status"><?= $escape($massNotice) ?></p><?php endif; ?>
<?php require __DIR__ . '/settings-product-partners.php'; ?>
<?php require __DIR__ . '/settings-product-sales.php'; ?>
<section class="panel-panel panel-settings-block" id="bulk-product-stock">
  <h2><?= \SimpleStore\Rendering\Icon::render('package') ?> Hromadná úprava produktů</h2>
  <p>Změna platí pro všechny produkty včetně konceptů a všech jazyků. Počet produktů: <strong><?= (int) $massCount ?></strong>.</p>
  <p class="panel-help">Počet znamená volné kusy k prodeji. U produktu se skladem variant se zadaný počet i dostupnost nastaví každé nabízené variantě zvlášť. Překlady sdílejí stejný sklad. Po inventuře zadej počet bez kusů rezervovaných pro objednávky. Rezervace se nemění; při stornu objednávky se její rezervované kusy vrátí na sklad.</p>
  <p class="panel-help">V režimu „Prodáváme my“ jde o vlastní sklad. U asistovaného nákupu jde o ručně nastavený limit nabídky; tento nástroj neověřuje, kolik kusů skutečně má partner. Znění dostupnosti „Skladem u partnera“ upravíš v <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=appearance#partner-stock-text') ?>">Textech webu → Dostupnost u partnera</a>.</p>
  <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=products#bulk-product-stock') ?>">
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
  <h2>Náhled hromadné změny</h2>
  <p>Celkem <?= (int) $massPreview['count'] ?> produktů. Skutečně se změní <strong><?= (int) $massPreview['changed'] ?></strong>; shodné hodnoty se znovu nezapisují.</p>
  <ul>
    <?php if ($massPreview['choices']['quantity'] !== ''): ?><li>Každému jednoduchému produktu a každé nabízené variantě nastavit <strong><?= (int) $massPreview['choices']['quantity'] ?> volných kusů</strong>.</li><?php endif; ?>
    <?php if ($massPreview['choices']['stock_status'] !== ''): ?><li>Dostupnost: <strong><?= $escape(['in_stock' => 'Skladem', 'on_order' => 'Na objednávku', 'out_of_stock' => 'Není skladem'][$massPreview['choices']['stock_status']]) ?></strong>.</li><?php endif; ?>
    <?php if ($massPreview['choices']['visibility'] !== ''): ?><li><strong><?= $massPreview['choices']['visibility'] === 'public' ? 'Zveřejnit všechny produkty včetně konceptů.' : 'Skrýt všechny produkty.' ?></strong></li><?php endif; ?>
  </ul>
  <p>Ostatní údaje, fotografie a dodavatelé zůstanou zachované.</p>
  <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=products') ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <input type="hidden" name="tab" value="products">
    <input type="hidden" name="action" value="products-mass-apply">
    <input type="hidden" name="preview_token" value="<?= $escape($massPreview['token']) ?>">
    <?php if ($massPreview['changed'] > 0): ?><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Potvrdit hromadnou změnu</button><?php else: ?><p role="status">Není co měnit.</p><?php endif; ?>
    <a class="panel-action" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=products&cancel_preview=mass#bulk-product-stock') ?>">Zrušit náhled</a>
  </form>
</section>
<?php endif; ?>

<section class="panel-panel panel-settings-block" id="bulk-product-variants">
  <h2><?= \SimpleStore\Rendering\Icon::render('package') ?> Hromadné vytvoření variant</h2>
  <p>Pro začátek obchodu: vytvoří všechny kombinace uložených voleb, například velikostí a barev, u produktů, které zatím nemají varianty. Každá nová varianta dostane zadaný počet volných kusů.</p>
  <p class="panel-help">Existující varianty se přeskočí. Produkty bez voleb a produkty s rezervovanými kusy se také přeskočí. Zahrnou se i koncepty; zůstanou skryté. Volby se čtou z výchozího jazyka obchodu, případně z prvního dostupného překladu. Cena i fotografie se převezmou z hlavního produktu; konkrétní obrázky můžeš přiřadit později.</p>
  <p class="panel-help">Nové varianty převezmou společné partnerské odkazy jako neověřené výchozí adresy. Přesnou barvu a velikost potom přiřaď v editoru produktu nebo ověř v Cenách dodavatelů. Zadané počty u asistovaného nákupu jsou ruční limit nabídky, nikoli ověřený sklad partnera.</p>
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
  <?php if ($variantBulkPreview['skipped'] !== []): ?><details><summary>Přeskočené produkty (<?= count($variantBulkPreview['skipped']) ?>)</summary><ul><?php foreach ($variantBulkPreview['skipped'] as $bulkProduct): ?><li><a target="_blank" rel="noopener" href="<?= $escape($basePath . $bulkProduct['language'] . '/produkt/' . rawurlencode($bulkProduct['slug']) . '?edit=1#product-variants') ?>"><?= $escape($bulkProduct['name']) ?></a>: <?= $escape($bulkProduct['reason']) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  <p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=products&cancel_preview=variants#bulk-product-variants') ?>">Zrušit náhled vytvoření variant</a></p>
  <?php endif; ?>
</section>
