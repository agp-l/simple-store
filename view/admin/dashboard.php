<?php
$number = static fn (int $value): string => number_format($value, 0, ',', ' ');
$money = static fn (int $value): string => $number($value) . ' Kč';
?>
<section class="panel-panel dashboard-heading">
  <p class="panel-eyebrow">Dashboard</p><h1>Přehled obchodu</h1>
  <p>Kolik máme zboží a kolik by stálo mít od všeho jeden kus.</p>
  <form method="get" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=dashboard') ?>" class="dashboard-filter">
    <label>Do výpočtů zahrnout<select name="scope"><option value="published" <?= $dashboardScope === 'published' ? 'selected' : '' ?>>Zveřejněné produkty</option><option value="all" <?= $dashboardScope === 'all' ? 'selected' : '' ?>>Všechny produkty včetně konceptů</option></select></label>
    <button type="submit" class="panel-button">Zobrazit přehled</button>
  </form>
</section>
<?php if ($dashboard === null): ?>
  <section class="panel-panel"><p role="alert"><?= $escape($dashboardError) ?></p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=database') ?>">Stav databáze</a></section>
<?php else:
  $groups = $dashboard['groups']; $total = $groups['total']; $own = $groups['own'];
  $labels = ['own'=>'Prodáváme my', 'assisted'=>'Externí dodavatelé', 'total'=>'Dohromady'];
?>
<div class="dashboard-cards">
  <section class="panel-panel"><h2>Produktů ve výběru</h2><strong class="dashboard-value"><?= $number($total['products']) ?></strong><p>Celý katalog — zveřejněné: <?= $number($dashboard['published']) ?>, koncepty: <?= $number($dashboard['drafts']) ?>.</p></section>
  <section class="panel-panel"><h2>Prodáváme my</h2><strong class="dashboard-value"><?= $number($own['products']) ?></strong><p>Počet produktů dostupných skladem u nás: <?= $number($own['in_stock']) ?>.</p></section>
  <section class="panel-panel"><h2>Externí dodavatelé</h2><strong class="dashboard-value"><?= $number($groups['assisted']['products']) ?></strong><p>Počet produktů vedených jako skladem u dodavatele: <?= $number($groups['assisted']['in_stock']) ?>.</p></section>
  <section class="panel-panel"><h2>Volných kusů u nás</h2><strong class="dashboard-value"><?= $number($own['quantity']) ?></strong><p>Součet vlastního skladu a aktivních variant, po odečtení rezervací.</p></section>
</div>
<?php if ($total['empty_variants'] > 0): ?><p class="panel-notice" role="status">Počet produktů se zapnutými variantami, ale bez aktivní varianty: <?= $number($total['empty_variants']) ?>. Jejich ceny nejsou v součtech zahrnuté.</p><?php endif; ?>
<?php if ($dashboard['invalid_prices'] > 0): ?><p class="panel-notice" role="status">U <?= $number($dashboard['invalid_prices']) ?> produktů nelze přečíst dodavatelské ceny variant. Přehled u nich používá společnou cenu, pokud je vyplněná.</p><?php endif; ?>

<?php foreach (['product'=>'Jeden kus od každého produktu', 'unit'=>'Jeden kus od každé varianty'] as $prefix => $heading): ?>
<section class="panel-panel dashboard-section">
  <h2><?= $heading ?></h2>
  <p><?= $prefix === 'product' ? 'U produktu s variantami počítáme nejlevnější aktivní variantu. Dodavatelský sloupec vybírá nejnižší známou dodavatelskou cenu; prodejní sloupec nejnižší prodejní cenu. Mohou patřit různým variantám.' : 'Každá aktivní velikost, barva nebo jiná kombinace se počítá zvlášť. Produkt bez skladu variant se počítá jako jedna položka.' ?></p>
  <div class="dashboard-table-wrap" tabindex="0" role="region" aria-label="<?= $heading ?>">
    <table class="dashboard-table"><thead><tr><th scope="col">Způsob prodeje</th><th scope="col">Položek</th><th scope="col">Známé dodavatelské ceny</th><th scope="col">Bez dodavatelské ceny</th><th scope="col">Prodejní hodnota</th></tr></thead><tbody>
    <?php foreach ($groups as $key => $group): ?><tr class="<?= $key === 'total' ? 'dashboard-total' : '' ?>"><th scope="row"><?= $labels[$key] ?></th><td><?= $number($group[$prefix === 'product' ? 'products' : 'units']) ?></td><td><?= $money($group[$prefix . '_cost']) ?><?php if ($group[$prefix . '_missing'] > 0): ?><small>Neúplný součet</small><?php endif; ?></td><td><?= $number($group[$prefix . '_missing']) ?></td><td><?= $money($group[$prefix . '_retail']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </div>
</section>
<?php endforeach; ?>

<div class="dashboard-cards dashboard-cards--two">
  <section class="panel-panel"><h2>Doplnění na jeden kus od každé varianty</h2><strong class="dashboard-value"><?= $money($total['need_cost']) ?></strong><p>Známé dodavatelské ceny pro <?= $number($total['need_units']) ?> položek, které nemáme ve vlastním volném skladu. Z toho <?= $number($total['need_missing']) ?> položek nemá dodavatelskou cenu a v částce chybí.</p><p>Externí sortiment se počítá celý. U vlastního zboží doplňujeme jen položky s nulovým počtem kusů.</p></section>
  <section class="panel-panel"><h2>Hodnota našeho volného skladu</h2><strong class="dashboard-value"><?= $money($own['stock_retail']) ?></strong><p>V aktuálních prodejních cenách, násobeno počtem kusů.</p><p>Podle známých dodavatelských cen: <strong><?= $money($own['stock_cost']) ?></strong>. Bez dodavatelské ceny: <?= $number($own['stock_missing']) ?> kusů. Jde o dnešní ocenění, nikoli historické pořizovací náklady.</p></section>
</div>
<section class="panel-panel dashboard-section">
  <h2>Dostupnost sortimentu</h2>
  <div class="dashboard-table-wrap" tabindex="0" role="region" aria-label="Dostupnost sortimentu"><table class="dashboard-table"><thead><tr><th scope="col">Způsob prodeje</th><th scope="col">Produktů skladem</th><th scope="col">Na objednávku</th><th scope="col">Nedostupných</th><th scope="col">Dostupných variant / položek</th></tr></thead><tbody>
  <?php foreach ($groups as $key => $group): ?><tr class="<?= $key === 'total' ? 'dashboard-total' : '' ?>"><th scope="row"><?= $labels[$key] ?></th><?php foreach (['in_stock','on_order','unavailable','available_units'] as $metric): ?><td><?= $number($group[$metric]) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  </tbody></table></div>
  <p>Produkt je skladem, pokud má alespoň jednu dostupnou aktivní variantu. Dostupnost dodavatelů odpovídá poslednímu uloženému stavu, nikoli živému ověření.</p>
</section>
<section class="panel-panel dashboard-section">
  <h2>Jak částky číst</h2>
  <p>Dodavatelské ceny jsou odhadem částky na pořízení zboží. Chybějící ceny nenahrazujeme prodejními cenami a nepočítáme je jako zboží zdarma. Pokud některé ceny chybí, zobrazený součet není celý potřebný kapitál. Prodejní hodnota ukazuje, za kolik je zboží nabízené zákazníkům.</p>
  <p>Výpočty používají uložené ceny v Kč bez přepočtu DPH a nezahrnují dopravu, množstevní slevy ani jiné náklady. Zahrnují i dočasně nedostupný sortiment. U variant má přednost jejich dodavatelská cena, jinak společná cena produktu.</p>
  <p>Historické revize a smazané produkty se nepočítají. Překlady stejného produktu počítáme jednou; upřednostňujeme zveřejněnou verzi v hlavním jazyce obchodu. Výběry velikostí bez zapnutého skladu variant zůstávají jedním produktem.</p>
  <div class="dashboard-links"><a href="<?= $escape($basePath . $site['default_language'] . '?manage=1#produkty') ?>">Spravovat produkty</a><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=supplier-prices') ?>">Ceny dodavatelů</a><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=orders') ?>">Objednávky</a></div>
</section>
<?php endif; ?>
