<div class="panel-intro"><div><p class="panel-eyebrow">Katalog / import</p><h1>Import produktu z AI</h1>
<p>Vlož jednu JSON zprávu, zkontroluj údaje a ulož. Bez klíče vznikne nový koncept; s klíčem a revizí upravíš existující produkt.</p></div></div>
<?php if ($importError !== ''): ?><p class="panel-error" role="alert"><?= $escape($importError) ?></p><?php endif; ?>
<section class="panel-panel">
<form class="panel-form" method="post" action="<?= $escape($adminUrl . '?section=product-import') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
<label for="product-json">JSON od AI (nejvýše 128 KiB)</label>
<textarea id="product-json" name="json" rows="18" spellcheck="false" required><?= $escape($importJson) ?></textarea>
<p class="panel-help">Při chybě text zůstane ve formuláři. Neuvedená pole při úpravě zůstanou zachovaná. Seznamy gallery, options, specifications a sections se nahrazují celé; [] je vyprázdní.</p>
<button class="panel-button" name="action" value="product-import-preview">Zkontrolovat a zobrazit náhled</button>
<?php if ($importPreview !== null): ?>
<h2><?= $escape($importPreview['name']) ?></h2>
<dl><dt>Cena</dt><dd><?= $escape($importPreview['price_czk']) ?> Kč</dd>
<dt>Kategorie</dt><dd><?= $escape($importPreview['category_path']) ?></dd>
<dt>Viditelnost</dt><dd><?= $importPreview['published'] ? 'Veřejný produkt' : 'Koncept' ?></dd>
<dt>Adresa</dt><dd><?= $escape($importPreview['slug']) ?></dd>
<dt>Perex</dt><dd><?= $escape($importPreview['summary']) ?></dd>
<dt>Popis</dt><dd><?= nl2br($escape($importPreview['description'])) ?></dd></dl>
<details><summary>Všechny výsledné údaje</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $escape(json_encode($importPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
<p>Uložení vytvoří novou revizi. Skladové kusy a soukromé dodavatelské odkazy se nemění; nastavíš je v detailu produktu.</p>
<button class="panel-button" name="action" value="product-import-save"><?= isset($importData['product_key']) ? 'Uložit úpravy produktu' : 'Vytvořit produkt' ?></button>
<?php endif; ?>
</form></section>
<section class="panel-panel"><h2>Podklady pro AI</h2>
<p>Zkopíruj zadání níže a přidej název nebo adresu zdrojového produktu. Úpravu existujícího produktu začni tlačítkem „JSON pro AI“ v jeho detailu.</p>
<textarea rows="9" readonly aria-label="Zadání pro AI">Vrať pouze JSON ve formátu simple-store.product.v1. Nevymýšlej parametry ani skladovou zásobu. Vytvoř vlastní český popis podle ověřeného zdroje. Perex summary má nejvýše 180 znaků. Cena price_czk je celé číslo v Kč. Při aktualizaci zachovej product_key, revision a language z exportu. Neuvedená pole se nemění. Kolekce se nahrazují celé.
{"format":"simple-store.product.v1","language":"<?= $escape($language) ?>","product":{"name":"Název","category_path":"CESTA_ZE_SEZNAMU","price_czk":100,"brand":"Značka","summary":"Krátký perex","description":"Popis","published":false,"stock_status":"out_of_stock","gallery":[],"options":[{"name":"Barva","values":["Černá"]}],"specifications":[{"name":"Hmotnost","value":"100 g"}],"sections":[{"type":"text","heading":"Vlastnosti","body":"Vlastní text"}]}}
Volitelné: slug (adresa), image_path (HTTPS obrázek nebo lokální cesta images/…), gallery (seznam cest). Bez image_path se použije neutrální náhled. Typy sections: text, list (položky oddělené \n), table (řádky Název | Hodnota), image (body obsahuje cestu obrázku). Stock_status: out_of_stock, in_stock, on_order. Obrázky jako URL se nestahují na disk.</textarea>
<h3>Kategorie v jazyce <?= $escape($language) ?></h3>
<ul><?php foreach ($importCategories as $row): ?><?php if ($categories->find($language, $row['path']) !== null): ?><li><?= $escape($row['title']) ?>: <code><?= $escape($row['path']) ?></code></li><?php endif; ?><?php endforeach; ?></ul>
<p><a href="<?= $escape($adminUrl . '?section=product-import&example=flextail') ?>">Načíst ukázku Flextail ZERO Power 10000</a> — koncept s referenční cenou z Gramino. Před zveřejněním ověř vlastní cenu, kategorii a dostupnost.</p>
<p>Fotografie můžeš po importu nahrát přes správu fotografií. HTTPS odkazy zůstávají externí; dostupnost pak závisí na cizím serveru.</p>
</section>
