<div class="panel-intro"><div><p class="panel-eyebrow">Katalog / import</p><h1>Import produktu z AI</h1>
<p>Vlož jednu JSON zprávu, zkontroluj údaje a ulož. Bez klíče vznikne nový koncept; s klíčem a revizí upravíš existující produkt.</p></div></div>
<?php if ($importError !== ''): ?><p class="panel-error" role="alert"><?= $escape($importError) ?></p><?php endif; ?>
<?php require __DIR__ . "/product-import-zip.php"; ?>
<section class="panel-panel">
<h2>Jeden produkt jako JSON</h2>
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
<p>Uložení vytvoří novou revizi. Skladové kusy se nemění. Dodavatelské odkazy se změní pouze při uvedení suppliers v JSON.</p>
<button class="panel-button" name="action" value="product-import-save"><?= isset($importData['product_key']) ? 'Uložit úpravy produktu' : 'Vytvořit produkt' ?></button>
<?php endif; ?>
</form></section>
<section class="panel-panel"><h2>Podklady pro AI</h2>
<p>Zkopíruj celé zadání níže a doplň seznam produktů. Pro úpravu použij JSON pro AI z detailu produktu.</p>
<textarea rows="22" readonly aria-label="Zadání pro AI"><?= $escape(file_get_contents(__DIR__ . '/../../src/Product/import-prompt.txt')) ?></textarea>
<h3>Platné kategorie (<?= $escape($language) ?>)</h3>
<p>AI předej i tento seznam. Cesty si nesmí vymýšlet.</p>
<textarea rows="8" readonly aria-label="Kategorie pro AI"><?php foreach ($importCategories as $row): ?><?php if ($categories->find($language, $row['path']) !== null): ?><?= $escape($row['path'] . ' — ' . $row['title']) . "\n" ?><?php endif; ?><?php endforeach; ?></textarea>
<p><a href="<?= $escape($adminUrl . '?section=product-import&example=flextail') ?>">Načíst JSON ukázku Flextail ZERO Power 10000</a> — koncept s referenční cenou z Gramino, bez fotografie. Odkaz na zdroj se uloží soukromě do dodavatelů.</p>
<p>Nové externí adresy fotografií nejsou povolené. Použij ZIP s obrázky nebo po JSON importu fotografie nahraj v detailu. Dříve uložené externí fotografie zůstávají čitelné, ale pro novou revizi je nahraď lokálními soubory.</p>
</section>
