<?php declare(strict_types=1); ?>
<section class="panel-panel">
<h2>Export a bezpečný import nastavení</h2>
<p>JSON obsahuje dopravce, ceny dopravy, platby a brány, Fio, Google, SMTP, texty webu i e-mailů, údaje provozovatele, importní prompt a nastavení aktualizací dodavatelů. Obsahuje také skutečné API klíče a hesla — ulož jej soukromě.</p>
<p>Doména, souborová konfigurace, menu, kategorie, partneři a číselné řady jsou přiložené jako <strong>reference_only</strong>. Hromadný import je automaticky nemění: doménu změň v Doména a přesun, menu či partnery přes jejich vlastní import. Záloha nastavení nenahrazuje úplnou zálohu databáze: neobsahuje objednávky, zákazníky, produkty, fotografie ani přihlašovací údaje k databázi.</p>
<p>Cron klíče uložené pouze jako otisk nelze zpětně zobrazit; záloha zachovává jejich otisky. Google a SMTP hesla se při importu znovu zašifrují klíčem cílové instalace.</p>
<?php if ($archiveError!==''): ?><p class="panel-error" role="alert"><?= $escape($archiveError) ?></p><?php endif; ?>
<?php if ($archiveNotice!==''): ?><p class="panel-notice" role="status"><?= $escape($archiveNotice) ?></p><?php endif; ?>
<form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=settings&tab=export') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="export">
<button class="panel-button" name="action" value="settings-export-download">Stáhnout všechna nastavení a klíče (JSON)</button>
</form>
</section>
<section class="panel-panel"><h2>Import JSON</h2>
<p>Nahrání souboru pouze připraví náhled. Prázdná a vynechaná pole nikdy nemažou současné hodnoty. Výchozí režim zachová i všechny již vyplněné hodnoty. Změny proběhnou společně v jedné transakci; pokud mezitím někdo nastavení upraví, import se odmítne.</p>
<form class="panel-form" method="post" enctype="multipart/form-data" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=settings&tab=export') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="export">
<label>Soubor zálohy<input type="file" name="settings_file" accept=".json,application/json" required></label>
<label>Způsob importu<select name="mode"><option value="fill">Pouze doplnit chybějící hodnoty (bez přepsání)</option><option value="overwrite">Přepsat dodané neprázdné hodnoty podle zálohy</option></select></label>
<button class="panel-button" name="action" value="settings-import-preview">Zobrazit náhled změn</button></form>
</section>
<?php if ($archivePlan!==null && $archivePending!==null): ?>
<section class="panel-panel"><h2>Náhled: <?= count($archivePlan['changes']) ?> změněných záznamů</h2>
<p>Režim: <strong><?= $archivePending['overwrite']?'Přepsání dodaných neprázdných hodnot':'Pouze doplnění chybějících hodnot' ?></strong>. Náhled platí 30 minut.</p>
<?php foreach ($archivePlan['changes'] as $change): ?><details><summary><?= $escape($change['section'].' / '.$change['key']) ?></summary><h3>Současné hodnoty</h3><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $escape(\SimpleStore\Admin\SettingsArchive::json($change['before'])) ?></pre><h3>Po importu</h3><pre style="white-space:pre-wrap;overflow-wrap:anywhere"><?= $escape(\SimpleStore\Admin\SettingsArchive::json($change['after'])) ?></pre></details><?php endforeach; ?>
<form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=settings&tab=export') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="export"><input type="hidden" name="preview_token" value="<?= $escape($archivePending['token']) ?>">
<button class="panel-button" name="action" value="settings-import-backup" formnovalidate>1. Stáhnout zálohu současného stavu před importem</button>
<label class="panel-check"><input type="checkbox" name="confirm" value="1" required> Zálohu mám uloženou a potvrzuji zobrazené změny.</label>
<button class="panel-button" name="action" value="settings-import-apply" <?= $archivePlan['changes']===[]?'disabled':'' ?>>2. Použít zkontrolované změny</button>
<button name="action" value="settings-import-cancel" formnovalidate>Zrušit náhled</button>
</form></section>
<?php endif; ?>
