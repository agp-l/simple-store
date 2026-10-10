<section class="panel-panel" id="zip-import">
<h2>ZIP s produkty a fotografiemi</h2>
<p>Každá složka obsahuje <code>product.json</code> a <code>images/</code>. Nejdřív nahraj balíček, potom spusť import. Chyba jednoho produktu nezastaví ostatní; vadný produkt se neuloží.</p>
<?php if ($zipError !== ''): ?><p class="panel-error" role="alert"><?= $escape($zipError) ?></p><?php endif; ?>
<form class="panel-form" method="post" enctype="multipart/form-data" action="<?= $escape($adminUrl . '?section=product-import#zip-import') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="product-zip-upload">
<label>Balíček ZIP<input name="package" type="file" accept=".zip,application/zip" required></label>
<p class="panel-help">ZIP do 128 MiB, rozbalená data do 512 MiB, nejvýše 200 produktů a 5 000 souborů. Hosting může mít nižší limit: upload_max_filesize <?= $escape(ini_get('upload_max_filesize')) ?>, post_max_size <?= $escape(ini_get('post_max_size')) ?>. Galerie nemá limit 12 fotografií. Každá fotografie do 12 MiB a 20 Mpx.</p>
<button class="panel-button" <?= class_exists(ZipArchive::class) ? '' : 'disabled' ?>>Nahrát ZIP<?= $zipState !== null ? ' a nahradit dosavadní frontu' : '' ?></button>
<?php if (!class_exists(ZipArchive::class)): ?><p class="panel-error">Pro ZIP import zapni na hostingu PHP rozšíření zip. JSON import zůstává dostupný.</p><?php endif; ?>
</form>
<?php if ($zipState !== null): ?>
<div id="zip-results" aria-live="polite">
<?php $zipPending = count(array_filter($zipState['items'], static fn (array $item): bool => in_array($item['status'], ['pending', 'processing'], true))); ?>
<p id="zip-progress">Zpracováno <?= count($zipState['items']) - $zipPending ?> / <?= count($zipState['items']) ?> produktů.</p>
<div style="overflow-x:auto"><table class="panel-table"><thead><tr><th>Složka</th><th>Stav</th><th>Výsledek</th></tr></thead><tbody id="zip-result-rows">
<?php foreach ($zipState['items'] as $item): ?><tr><td><?= $escape($item['folder']) ?></td><td><?= $escape(['pending' => 'Čeká', 'processing' => 'Zpracovává se', 'success' => 'Uloženo', 'error' => 'Přeskočeno', 'interrupted' => 'Přerušeno – ověř ručně'][$item['status']] ?? $item['status']) ?></td><td><?= $escape($item['message']) ?>
<?php if (isset($item['saved'])): ?> <a href="<?= $escape($basePath . $item['saved']['language'] . '/produkt/' . $item['saved']['slug'] . '?edit=1') ?>">Otevřít produkt</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<form method="post" id="zip-step-form" data-base-path="<?= $escape($basePath) ?>" action="<?= $escape($adminUrl . '?section=product-import#zip-import') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="product-zip-step">
<button class="panel-button" <?= $zipPending === 0 ? 'disabled' : '' ?>>Spustit / pokračovat v importu</button>
<noscript><p>Bez JavaScriptu každé stisknutí zpracuje jeden produkt.</p></noscript>
<p data-zip-status role="status"></p></form>
<form method="post" action="<?= $escape($adminUrl . '?section=product-import#zip-import') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="product-zip-clear"><button>Ukončit a odstranit dočasný balíček</button><p class="panel-help">Již uložené produkty zůstanou. Výsledek si zkontroluj před ukončením; pro opravy nahraj jen vadné složky. Opakování stejného nového produktu odmítne duplicitní adresu. Dočasné balíčky se při dalších nahráních čistí po 24 hodinách.</p></form>
<script defer src="<?= $escape($basePath . 'assets/product-import.js?v=' . filemtime(__DIR__ . '/../../assets/product-import.js')) ?>"></script>
<?php endif; ?>
</section>
