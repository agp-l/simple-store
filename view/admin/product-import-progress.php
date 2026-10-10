<?php if ($importState !== null): ?>
<div id="import-results" aria-live="polite">
<?php $importPending = count(array_filter($importState['items'], static fn (array $item): bool => in_array($item['status'], ['pending', 'processing'], true))); ?>
<p id="import-progress">Zpracováno <?= count($importState['items']) - $importPending ?> / <?= count($importState['items']) ?> produktů.</p>
<div style="overflow-x:auto"><table class="panel-table"><thead><tr><th>Produkt / složka</th><th>Stav</th><th>Výsledek</th></tr></thead><tbody id="import-result-rows">
<?php foreach ($importState['items'] as $item): ?><tr><td><?= $escape($item['folder']) ?></td><td><?= $escape(['pending' => 'Čeká', 'processing' => 'Zpracovává se', 'success' => 'Uloženo', 'error' => 'Přeskočeno', 'interrupted' => 'Přerušeno – ověř ručně'][$item['status']] ?? $item['status']) ?></td><td><?= $escape($item['message']) ?>
<?php if (isset($item['saved'])): ?> <a href="<?= $escape($basePath . $item['saved']['language'] . '/produkt/' . $item['saved']['slug'] . '?edit=1') ?>">Otevřít produkt</a><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<form method="post" id="import-step-form" data-base-path="<?= $escape($basePath) ?>" action="<?= $escape($progressUrl) ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="<?= $escape($stepAction) ?>">
<button class="panel-button" <?= $importPending === 0 ? 'disabled' : '' ?>>Spustit / pokračovat v importu</button>
<noscript><p>Bez JavaScriptu každé stisknutí zpracuje jeden produkt.</p></noscript>
<p data-import-status role="status"></p></form>
<form method="post" action="<?= $escape($progressUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="<?= $escape($clearAction) ?>"><button>Ukončit a odstranit dočasný balíček</button><p class="panel-help">Již uložené produkty zůstanou. Výsledek si zkontroluj před ukončením; pro opravy nahraj jen vadné položky. Opakování stejného nového produktu odmítne duplicitní adresu. Dočasné balíčky se při dalších nahráních čistí po 24 hodinách.</p></form>
<script defer src="<?= $escape($basePath . 'assets/product-import.js?v=' . filemtime(__DIR__ . '/../../assets/product-import.js')) ?>"></script>
<?php endif; ?>
