<?php declare(strict_types=1); ?>
<section class="panel-panel panel-settings-block" id="bulk-product-sales">
  <h2><?= \SimpleStore\Rendering\Icon::render('package') ?> Hromadné přepnutí způsobu prodeje</h2>
  <?php if (!$saleBulkReady): ?>
    <p><a href="<?= $escape($adminUrl . '?section=database') ?>">Nejprve aktualizuj databázi.</a></p>
  <?php else: ?>
    <p>Prodáváme my: <strong><?= (int) $saleBulkCounts['own'] ?></strong> · Asistovaný nákup: <strong><?= (int) $saleBulkCounts['assisted'] ?></strong></p>
    <p>Přepne všechny současné produkty včetně konceptů, překladů a jejich variant. Prodávající a nákupní ceny zůstanou uložené i při přepnutí zpět.</p>
    <form class="panel-form" method="post" action="<?= $escape($adminUrl . '?section=settings&tab=products#bulk-product-sales') ?>">
      <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="products"><input type="hidden" name="action" value="products-sale-preview">
      <label>Způsob prodeje všech produktů<select name="sale_mode" required>
        <option value="">Vybrat způsob prodeje…</option>
        <option value="assisted" <?= $saleBulkMode === 'assisted' ? 'selected' : '' ?>>Asistovaný nákup</option>
        <option value="own" <?= $saleBulkMode === 'own' ? 'selected' : '' ?>>Prodáváme my</option>
      </select></label>
      <p class="panel-help">Ceny pro zákazníky, sklad, viditelnost a existující objednávky se nezmění. Nově vytvořené produkty mají vlastní nastavení způsobu prodeje.</p>
      <button class="panel-action panel-button" type="submit" <?= $massCount === 0 ? 'disabled' : '' ?>><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit náhled přepnutí</button>
    </form>
    <?php if ($saleBulkPreview !== null): ?>
      <h3>Nastavit všem: <?= $saleBulkPreview['mode'] === 'assisted' ? 'Asistovaný nákup' : 'Prodáváme my' ?></h3>
      <p>Celkem <strong><?= (int) $saleBulkPreview['total'] ?></strong> produktů. Změní se <strong><?= (int) $saleBulkPreview['changed'] ?></strong>, zvolený režim už má <?= (int) $saleBulkPreview['unchanged'] ?>.</p>
      <?php if ($saleBulkPreview['issues'] !== []): ?>
        <p class="panel-notice">U <?= count($saleBulkPreview['issues']) ?> produktů chybí platné údaje pro asistovaný nákup. Přepnutí je zahrne také; dokud údaje nedoplníš, zákazník je přes asistovaný nákup neobjedná.</p>
        <details><summary>Produkty k doplnění (<?= count($saleBulkPreview['issues']) ?>)</summary>
          <ul><?php foreach ($saleBulkPreview['issues'] as $saleIssue): ?><li>
            <a href="<?= $escape($basePath . $saleIssue['language'] . '/produkt/' . rawurlencode($saleIssue['slug']) . '?edit=1#product-sale') ?>" target="_blank" rel="noopener"><?= $escape($saleIssue['name']) ?> ↗</a> — <?= $escape(implode(' ', $saleIssue['reasons'])) ?>
          </li><?php endforeach; ?></ul>
        </details>
      <?php elseif ($saleBulkPreview['mode'] === 'assisted'): ?><p>Partnerské údaje a ceny jsou u všech nabízených variant připravené. Dostupnost nadále určuje sklad produktu.</p><?php endif; ?>
      <?php if ($saleBulkPreview['changed'] > 0): ?>
        <form method="post" action="<?= $escape($adminUrl . '?section=settings&tab=products#bulk-product-sales') ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="products"><input type="hidden" name="action" value="products-sale-apply"><input type="hidden" name="preview_token" value="<?= $escape($saleBulkPreview['token']) ?>">
          <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Přepnout všechny produkty: <?= $saleBulkPreview['mode'] === 'assisted' ? 'Asistovaný nákup' : 'Prodáváme my' ?></button>
          <a class="panel-action" href="<?= $escape($adminUrl . '?section=settings&tab=products#bulk-product-sales') ?>">Zrušit</a>
        </form>
      <?php else: ?><p role="status">Všechny produkty už používají vybraný způsob prodeje.</p><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</section>
