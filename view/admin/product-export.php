<section class="panel-panel">
<h2>Vybrat produkty k exportu</h2>
<form method="get" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>" class="panel-form product-transfer-filters">
<input type="hidden" name="section" value="product-import"><input type="hidden" name="tab" value="export">
<label>Jazyk<select name="export_language"><option value="">Všechny jazyky</option><?php foreach ($site['languages'] as $lang): ?><option value="<?= $escape($lang) ?>" <?= $exportLanguage === $lang ? 'selected' : '' ?>><?= $escape($lang) ?></option><?php endforeach; ?></select></label>
<label>Kategorie včetně podkategorií<select name="export_category"><option value="">Všechny kategorie</option><?php
$exportCategories = [];
foreach ($site['languages'] as $lang) foreach ($categories->allForAdmin($lang) as $category) $exportCategories[$category['path']] = $category['path'] . ' — ' . $category['title'];
foreach ($exportCategories as $path => $label): ?><option value="<?= $escape($path) ?>" <?= $exportCategory === $path ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
<label>Název nebo adresa<input name="export_search" value="<?= $escape($exportSearch) ?>" maxlength="200" type="search"></label>
<button class="panel-action panel-button"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit produkty</button>
<a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=product-import&tab=export') ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('filter') ?> Zrušit filtry</a>
</form>
<p>Ve výběru je <?= count($exportRows) ?> položek včetně konceptů. Každý překlad je samostatný řádek.</p>
<form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=product-import&tab=export') ?>" id="product-export-form" class="panel-form">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="product-bulk-export">
<input type="hidden" name="export_language" value="<?= $escape($exportLanguage) ?>"><input type="hidden" name="export_category" value="<?= $escape($exportCategory) ?>"><input type="hidden" name="export_search" value="<?= $escape($exportSearch) ?>"><input type="hidden" name="selected_json" value="[]">
<div class="product-transfer-actions">
<label>Formát<select name="export_format"><option value="json">Úplný JSON — záloha a hromadné úpravy</option><option value="names">Přehled pro AI a dodavatelé — stručný JSON</option><option value="relations">Související produkty — propojení a poznámky (JSON)</option></select></label>
<button class="panel-button panel-action" name="export_scope" value="selected" data-export-selected disabled><?= \SimpleStore\Rendering\Icon::render('download') ?> Exportovat zaškrtnuté (<span data-selection-count>0</span>)</button>
<button class="panel-action panel-button" name="export_scope" value="filtered" <?= $exportRows === [] ? 'disabled' : '' ?>><?= \SimpleStore\Rendering\Icon::render('download') ?> Exportovat vše podle filtru (<?= count($exportRows) ?>)</button>
</div>
<p class="panel-help">Pro všechny produkty nech filtry prázdné. JSON obsahuje aktuální texty, ceny, varianty, bloky, dodavatele, sklad a cesty k fotografiím. Neobsahuje soubory fotografií, historii revizí ani nastavení kategorií. Pro úplnou zálohu webu zálohuj také databázi a složku images/.</p>
<noscript><p>Bez JavaScriptu použij export všeho podle filtru. Jednotlivý výběr vyžaduje JavaScript.</p></noscript>
<div class="product-transfer-table"><table class="panel-table"><thead><tr><th><input type="checkbox" data-select-all aria-label="Vybrat všechny zobrazené produkty"></th><th>Produkt</th><th>Kategorie</th><th>Jazyk / stav</th><th>Cena / sklad</th></tr></thead><tbody>
<?php foreach ($exportRows as $row): ?><tr>
<td><input type="checkbox" data-product-choice value="<?= $escape(\SimpleStore\Product\ProductBulkTransfer::identity($row)) ?>" aria-label="<?= $escape('Vybrat ' . $row['name']) ?>"></td>
<td><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . $row['language'] . '/produkt/' . $row['slug'] . '?edit=1') ?>"><?= $escape($row['name']) ?></a><small><?= $escape($row['slug']) ?> · revize <?= (int) $row['revision_number'] ?></small></td>
<td><?= $escape(\SimpleStore\Category\CategoryPath::fromProduct($row)) ?></td>
<td><?= $escape($row['language']) ?> · <?= (int) $row['published'] ? 'Veřejný' : 'Koncept' ?></td>
<td><?= (int) $row['price_czk'] ?> Kč<small><?= (int) $row['stock_quantity'] ?> ks</small></td>
</tr><?php endforeach; ?>
<?php if ($exportRows === []): ?><tr><td colspan="5">Tomuto filtru neodpovídají žádné produkty.</td></tr><?php endif; ?>
</tbody></table></div></form>
<script defer src="<?= $escape($basePath . 'assets/product-export.js?v=' . filemtime(__DIR__ . '/../../assets/product-export.js')) ?>"></script>
</section>
