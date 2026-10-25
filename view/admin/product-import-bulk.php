<section class="panel-panel">
<h2>Hromadný import JSON</h2>
<p>Nahraj export nebo vlož JSON od AI ve formátu <code>simple-store.products.v1</code> nebo stručný přehled <code>simple-store.product-suppliers.v1</code>. Pro přesné odkazy variant použij <code>simple-store.variant-suppliers.v1</code>. Nejdřív se připraví seznam změn bez zápisu produktů. Potom import spustíš; chybné položky se přeskočí.</p>
<p><strong>Stručný JSON dodavatelů:</strong> aktualizuje pouze dodavatelské odkazy. Existující odkazy, které v JSON chybí, zůstanou zachované; prázdný seznam nic nesmaže. ID z exportu umožňuje změnit název i URL odkazu. Nový odkaz přidej bez ID; shodná URL se neduplikuje. Název produktu, jeho adresa a kategorie v přehledu jsou pouze informativní. Použij běžný režim bez přepisu skladu.</p>
<p><strong>Související produkty:</strong> formát <code>simple-store.product-relations.v1</code> propojí dvojice obousměrně a doplní poznámku. Existující jiné vazby ani popisy nepřepíše. Importuj v běžném režimu bez přepisu skladu.</p>
<form class="panel-form" method="post" enctype="multipart/form-data" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=product-import&tab=bulk') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="product-bulk-prepare">
<label>Soubor JSON<input type="file" name="json_file" accept=".json,application/json"></label>
<label>Nebo vlož JSON<textarea name="bulk_json" rows="10" spellcheck="false"><?= $escape($bulkJson) ?></textarea></label>
<label>Jak importovat<select name="import_mode"><option value="update" <?= !$bulkRestore ? 'selected' : '' ?>>Přidat nové a upravit existující — kontrolovat revize</option><option value="restore" <?= $bulkRestore ? 'selected' : '' ?>>Obnovit údaje ze zálohy — přepsat i novější revize</option></select></label>
<p class="panel-help">Běžná aktualizace potřebuje aktuální product_key a revision z exportu. Nový produkt tyto dvě položky nemá. Obnova použije starší obsah zálohy, zachová identitu produktů a obnoví i smazané produkty. Vytvoří novou revizi; ostatní produkty nemaže. Pokud se produkt změní až po přípravě importu, musíš jej připravit znovu.</p>
<label><input type="checkbox" name="include_stock" value="1" <?= $bulkStock ? 'checked' : '' ?>> Přepsat také skladové počty uvedené v stock_quantity i ve variantách</label>
<p class="panel-help">Bez zaškrtnutí se skladové kusy existujících produktů nemění. Změní-li se sklad po přípravě, položka se neuloží. Kategorie i soubory obrázků musí na tomto webu existovat; JSON obsahuje jen cesty k fotografiím. Historie objednávek ani skladové rezervace se neobnovují.</p>
<p class="panel-help">Nejvýše 32 MiB a 10 000 položek v jedné dávce; každý produkt do 1 MiB. Limity hostingu: upload_max_filesize <?= $escape(ini_get('upload_max_filesize')) ?>, post_max_size <?= $escape(ini_get('post_max_size')) ?>.</p>
<button class="panel-button panel-action"><?= \SimpleStore\Rendering\Icon::render('check') ?> Připravit a zkontrolovat<?= $bulkState !== null ? ' novou frontu' : '' ?></button>
</form>
<?php
$importState = $bulkState;
$stepAction = 'product-bulk-step'; $clearAction = 'product-bulk-clear';
$progressUrl = $adminUrl . '?section=product-import&tab=bulk#import-results';
require __DIR__ . '/product-import-progress.php';
?>
</section>
