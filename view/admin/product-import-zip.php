<section class="panel-panel" id="zip-import">
<h2>ZIP s produkty a fotografiemi</h2>
<p>Každá složka obsahuje <code>product.json</code> a <code>images/</code>. Nejdřív nahraj balíček, potom spusť import. Chyba jednoho produktu nezastaví ostatní; vadný produkt se neuloží.</p>
<?php if ($zipError !== ''): ?><p class="panel-error" role="alert"><?= $escape($zipError) ?></p><?php endif; ?>
<form class="panel-form" method="post" enctype="multipart/form-data" action="<?= $escape($adminUrl . '?section=product-import&tab=zip#zip-import') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="product-zip-upload">
<label>Balíček ZIP<input name="package" type="file" accept=".zip,application/zip" required></label>
<p class="panel-help">ZIP do 128 MiB, rozbalená data do 512 MiB, nejvýše 200 produktů a 5 000 souborů. Hosting může mít nižší limit: upload_max_filesize <?= $escape(ini_get('upload_max_filesize')) ?>, post_max_size <?= $escape(ini_get('post_max_size')) ?>. Galerie nemá limit 12 fotografií. Každá fotografie do 12 MiB a 20 Mpx.</p>
<button class="panel-button" <?= class_exists(ZipArchive::class) ? '' : 'disabled' ?>>Nahrát ZIP<?= $zipState !== null ? ' a nahradit dosavadní frontu' : '' ?></button>
<?php if (!class_exists(ZipArchive::class)): ?><p class="panel-error">Pro ZIP import zapni na hostingu PHP rozšíření zip. JSON import zůstává dostupný.</p><?php endif; ?>
</form>
<?php
$importState = $zipState;
$stepAction = 'product-zip-step'; $clearAction = 'product-zip-clear';
$progressUrl = $adminUrl . '?section=product-import&tab=zip#zip-import';
require __DIR__ . '/product-import-progress.php';
?>
</section>
