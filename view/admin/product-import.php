<div class="panel-intro"><div><p class="panel-eyebrow">Katalog / data produktů</p><h1>Import a export produktů</h1>
<p>Vyber produkty pro zálohu nebo AI. Upravená data nahraj zpět jako jeden JSON; nové fotografie lze přiložit v ZIP.</p>
<p><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=content-import') ?>"><?= \SimpleStore\Rendering\Icon::render('document') ?> Import a export stránek a článků</a></p></div></div>
<nav class="panel-order-tabs product-transfer-tabs" aria-label="Import a export">
<?php foreach (['export' => 'Export a seznam pro AI', 'families'=>'Sjednotit produkty', 'bulk' => 'Hromadný JSON', 'single' => 'Jeden produkt', 'zip' => 'ZIP s fotografiemi', 'help' => 'Zadání pro AI'] as $tab => $label): ?>
<a <?= $importTab === $tab ? 'aria-current="page"' : '' ?> href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=product-import&tab=' . $tab . '&language=' . $language) ?>"><span class="admin-tab-icon" aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render(['export' => 'download','families'=>'package', 'bulk' => 'list', 'single' => 'plus', 'zip' => 'package', 'help' => 'edit'][$tab]) ?></span><span><?= $escape($label) ?></span></a>
<?php endforeach; ?></nav>
<?php if ($importError !== ''): ?><p class="panel-error" role="alert"><?= $escape($importError) ?></p><?php endif; ?>
<?php if ($transferError !== ''): ?><p class="panel-error" role="alert"><?= $escape($transferError) ?></p><?php endif; ?>
<?php if ($importTab === 'export') require __DIR__ . '/product-export.php'; ?>
<?php if ($importTab === 'families') require __DIR__ . '/product-families.php'; ?>
<?php if ($importTab === 'bulk') require __DIR__ . '/product-import-bulk.php'; ?>
<?php if ($importTab === 'zip') require __DIR__ . '/product-import-zip.php'; ?>
<link rel="stylesheet" href="<?= \SimpleStore\Navigation\PanelUrl::escape($basePath . 'assets/product-transfer.css?v=' . filemtime(__DIR__ . '/../../assets/product-transfer.css')) ?>">
<?php if ($importTab === 'single'): ?><section class="panel-panel">
<h2>Jeden produkt jako JSON</h2>
<form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=product-import') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
<label for="product-json">JSON od AI (nejvýše 1 MiB)</label>
<textarea id="product-json" name="json" rows="18" spellcheck="false" required><?= $escape($importJson) ?></textarea>
<p class="panel-help">Při chybě text zůstane ve formuláři. Neuvedená pole při úpravě zůstanou zachovaná. Seznamy gallery, options, specifications a sections se nahrazují celé; [] je vyprázdní.</p>
<button class="panel-action panel-button" name="action" value="product-import-preview"><?= \SimpleStore\Rendering\Icon::render('refresh') ?> Zkontrolovat a zobrazit náhled</button>
<?php if ($importPreview !== null): ?>
<h2><?= $escape($importPreview['name']) ?></h2>
<dl><dt>Cena</dt><dd><?= $escape($importPreview['price_czk']) ?> Kč</dd>
<dt>Kategorie</dt><dd><?= $escape($importPreview['category_path']) ?></dd>
<dt>Viditelnost</dt><dd><?= $importPreview['published'] ? 'Veřejný produkt' : 'Koncept' ?></dd>
<dt>Adresa</dt><dd><?= $escape($importPreview['slug']) ?></dd>
<dt>Perex</dt><dd><?= $escape($importPreview['summary']) ?></dd>
<dt>Popis</dt><dd><?= nl2br($escape($importPreview['description'])) ?></dd></dl>
<details><summary>Všechny výsledné údaje</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $escape(json_encode($importPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
<p>Uložení vytvoří novou revizi. Uvedené variants mění i jejich sklad; bez variants se sklad nemění. product.suppliers nahrazuje společné odkazy. suppliers uvnitř jednotlivých variant odkazy doplňuje nebo aktualizuje; vynechané odkazy zachová. Odkazy se ukládají společně s produktem, jejich cena a dostupnost se ověřují zvlášť.</p>
<button class="panel-button panel-action" name="action" value="product-import-save"><?= \SimpleStore\Rendering\Icon::render('save') ?> <?= isset($importData['product_key']) ? 'Uložit úpravy produktu' : 'Vytvořit produkt' ?></button>
<?php endif; ?>
</form></section>
<?php endif; ?>
<?php if ($importTab === 'help'):
$categoryPrompt = '';
foreach ($importCategories as $row) if ($categories->findForAdmin($language, $row['path']) !== null) $categoryPrompt .= $row['path'] . ' — ' . $row['title'] . "\n";
$completePrompt = \SimpleStore\Product\ProductImportPromptRepository::instructions($aiPrompt, $categoryPrompt);
?><section class="panel-panel product-prompt-panel"><h2>Zadání pro AI</h2>
<p>Jeden JSON může založit produkt včetně barev, velikostí, fotografií a více partnerských odkazů pro každou variantu. Pro úpravu existujícího produktu předej AI také jeho čerstvý <strong>úplný JSON export</strong>.</p>
<div class="product-prompt-current">
<h3>1. Zkopírovat aktuální zadání</h3>
<p>Obsahuje aktuální pravidla importu, vaše uložené pokyny a platné kategorie. Technická pravidla se aktualizují s webem.</p>
<label for="ai-product-instructions">Kompletní zadání pro AI</label>
<textarea id="ai-product-instructions" rows="14" readonly spellcheck="false"><?= $escape($completePrompt) ?></textarea>
<div class="product-transfer-actions"><button type="button" class="panel-button panel-action" data-copy-product-prompt><?= \SimpleStore\Rendering\Icon::render('document') ?> Zkopírovat celé zadání</button><span role="status" data-copy-product-status></span></div>
<p class="panel-help">Doplň konkrétní produkty nebo adresy partnerů. Pro varianty používej suppliers přímo u každé kombinace; stručný přehled společných odkazů nestačí.</p>
</div>
<p><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=product-import&example=variants') ?>"><?= \SimpleStore\Rendering\Icon::render('package') ?> Otevřít ukázkový JSON: dvě varianty, dva partneři</a> — fiktivní data k nahrazení skutečnými údaji.</p>
<h3>2. Vlastní pokyny pro psaní produktů</h3>
<p>Vlastní text zůstává uložený i po aktualizaci webu. Uprav například styl popisu; platný technický formát se vždy přidá do kompletního zadání výše.</p>
<?php if ($promptError !== ''): ?><p class="panel-error" role="alert"><?= $escape($promptError) ?></p><?php endif; ?>
<?php if (($_GET['prompt_saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Zadání pro AI bylo uloženo.</p><?php endif; ?>
<form class="panel-form product-prompt-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=product-import&tab=help&language=' . $language) ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="product-prompt-save">
<label for="ai-product-prompt">Uložené vlastní zadání</label>
<textarea id="ai-product-prompt" name="ai_prompt" rows="10" spellcheck="false" required aria-describedby="ai-product-prompt-help"><?= $escape($aiPrompt) ?></textarea>
<p class="panel-help" id="ai-product-prompt-help">Po úpravě nejprve ulož. Pak kopíruj kompletní zadání nahoře, aby AI dostala i aktuální pravidla a kategorie. Nejvýše 128 KiB vlastního textu.</p>
<div class="panel-form-actions"><button class="panel-button panel-action"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit zadání</button></div>
</form>
<script defer src="<?= $escape($basePath . 'assets/product-prompt.js?v=' . filemtime(__DIR__ . '/../../assets/product-prompt.js')) ?>"></script>
</section>
<section class="panel-panel product-prompt-panel"><h2>Platné kategorie (<?= $escape($language) ?>)</h2>
<p>AI předej i tento seznam. Cesty si nesmí vymýšlet; seznam se vytváří z aktuálních kategorií obchodu.</p>
<div class="panel-form"><label for="ai-product-categories">Kategorie pro AI</label>
<textarea id="ai-product-categories" rows="8" readonly><?php foreach ($importCategories as $row): ?><?php if ($categories->findForAdmin($language, $row['path']) !== null): ?><?= $escape($row['path'] . ' — ' . $row['title']) . "\n" ?><?php endif; ?><?php endforeach; ?></textarea></div>
<p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=product-import&example=flextail') ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('refresh') ?> Načíst JSON ukázku Flextail ZERO Power 10000</a> — koncept s referenční cenou z Gramino, bez fotografie. Odkaz na zdroj se uloží soukromě do dodavatelů.</p>
<p>Nové externí adresy fotografií nejsou povolené. Použij ZIP s obrázky nebo po JSON importu fotografie nahraj v detailu. Dříve uložené externí fotografie zůstávají čitelné, ale pro novou revizi je nahraď lokálními soubory.</p>
</section>
<?php endif; ?>
