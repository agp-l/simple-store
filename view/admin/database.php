<?php
declare(strict_types=1);
?>
<div class="panel-intro">
  <div><p class="panel-eyebrow">Správa webu</p><h1>Databáze</h1>
    <p>Aktualizace tabulek z aktuálního souboru <code>database/schema.sql</code>.</p></div>
</div>
<?php if ($databaseError !== ''): ?><p class="panel-error" role="alert"><?= $escape($databaseError) ?></p><?php endif; ?>
<?php if (($_GET['updated'] ?? '') === '1'): ?><p class="panel-notice" role="status">Tabulky byly aktualizovány.</p><?php endif; ?>
<?php if (($_GET['updated'] ?? '') === '0'): ?><p class="panel-notice" role="status">Databáze už používá aktuální verzi schématu.</p><?php endif; ?>
<?php if ($databaseStatus !== null): ?>
  <?php $schemaRecord = $databaseStatus['record']; ?>
  <section class="panel-panel">
    <h2>Stav schématu</h2>
    <dl class="panel-order-facts">
      <div><dt>Připojená databáze</dt><dd><strong><?= $escape($databaseStatus['database']) ?></strong></dd></div>
      <div><dt>Soubor</dt><dd><code>database/schema.sql</code> · <?= (int) $databaseStatus['count'] ?> příkazů pro aktualizaci</dd></div>
      <div><dt>Stav</dt><dd><strong><?= $databaseStatus['current'] ? 'Aktuální' : 'Připraveno k aktualizaci' ?></strong></dd></div>
      <?php if ($schemaRecord !== null): ?>
        <div><dt>Poslední běh</dt><dd><?= $escape(match ($schemaRecord['state']) {
            'complete' => 'Dokončeno', 'failed' => 'Nedokončeno',
            'running' => 'Probíhá nebo bylo přerušeno', default => 'Neznámý stav',
        }) ?> · <?= $escape($schemaRecord['started_at']) ?></dd></div>
        <?php if ($schemaRecord['state'] !== 'complete'): ?>
          <div><dt>Postup</dt><dd><?= (int) $schemaRecord['completed_statements'] ?> z <?= (int) $databaseStatus['count'] ?> příkazů. Opakování spustí celý opakovatelný soubor znovu.</dd></div>
        <?php endif; ?>
        <?php if ($schemaRecord['last_error']): ?><div><dt>Poslední chyba</dt><dd><?= $escape($schemaRecord['last_error']) ?></dd></div><?php endif; ?>
      <?php endif; ?>
    </dl>
    <?php if (!$databaseStatus['current']): ?>
      <p class="panel-help">Použije se právě databáze uvedená výše. Příkazy pro vytvoření a přepnutí databáze ze souboru se nespouštějí; stávající obsah tabulek zůstává zachován.</p>
      <form class="panel-form" method="post" action="<?= $escape($adminUrl . '?section=database') ?>">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
        <input type="hidden" name="action" value="schema-apply">
        <button class="panel-button" type="submit">Aktualizovat SQL tabulky</button>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>
