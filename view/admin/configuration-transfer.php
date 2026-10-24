<?php
use SimpleStore\Admin\ConfigurationBackup;
$backupKind ??= 'email-texts';
$backupError ??= ''; $backupPreview ??= null; $backupJson ??= ''; $backupToken ??= '';
$backupReturn ??= ['section' => 'settings', 'tab' => $backupKind === 'site-texts' ? 'appearance' : 'mail'];
$backupUrl ??= $adminUrl . '?' . http_build_query($backupReturn);
$backupTitle = ['navigation' => 'Záloha menu a kategorií', 'site-texts' => 'Záloha textů webu', 'email-texts' => 'Záloha textů e-mailů'][$backupKind];
?>
<link rel="stylesheet" href="<?= $escape($basePath . 'assets/admin-structure.css?v=' . filemtime(__DIR__ . '/../../assets/admin-structure.css')) ?>">
<details class="structure-backup panel-panel" id="configuration-backup" <?= $backupError !== '' || $backupPreview !== null || isset($_GET['imported']) ? 'open' : '' ?>>
  <summary><?= \SimpleStore\Rendering\Icon::render('download') ?><span><?= $escape($backupTitle) ?></span><small>Export a obnova z JSON</small></summary>
  <div class="structure-backup-body">
    <?php if (($_GET['imported'] ?? '') === '1'): ?><p class="panel-notice" role="status">Záloha byla načtena. Shodné položky zůstaly beze změny, duplicity se nevytvářejí.</p><?php endif; ?>
    <?php if ($backupError !== ''): ?><p class="panel-error" role="alert"><?= $escape($backupError) ?></p><?php endif; ?>
    <p class="structure-note"><?php if ($backupKind === 'navigation'): ?>Všechna menu, vlastní odkazy, strom kategorií a zařazení stránek do automatického menu ve všech jazycích. Obnova nahradí obsah menu uvedených v souboru; kategorie sloučí podle stálých adres. Produkty a jejich zařazení zůstávají zachované. Obsah stránek se zálohuje zvlášť v Importu a exportu.<?php elseif ($backupKind === 'site-texts'): ?>Uložené texty hlavičky, katalogu a patičky ve všech jazycích. Import aktualizuje pouze texty uvedené v souboru.<?php else: ?>Předměty, texty všech šablon a společné popisky. Připojení pošty, hesla, adresy a nastavení automatického odesílání se nepřenášejí. Import žádný e-mail neodešle.<?php endif; ?></p>
    <div class="structure-backup-columns">
      <div><h3>1. Stáhnout zálohu</h3><p>Export obsahuje aktuálně uložené nastavení. Rozepsané změny nejdřív ulož.</p><a class="panel-action" href="<?= \SimpleStore\Navigation\PanelUrl::escape($backupUrl . '&backup=export') ?>"><?= \SimpleStore\Rendering\Icon::render('download') ?> Stáhnout JSON</a></div>
      <form class="panel-form" method="post" enctype="multipart/form-data" action="<?= \SimpleStore\Navigation\PanelUrl::escape($backupUrl . '#configuration-backup') ?>">
        <h3>2. Zkontrolovat soubor</h3>
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="configuration-preview"><input type="hidden" name="backup_kind" value="<?= $escape($backupKind) ?>">
        <?php foreach ($backupReturn as $key => $value): ?><input type="hidden" name="<?= $escape($key) ?>" value="<?= $escape($value) ?>"><?php endforeach; ?>
        <label>Soubor zálohy <input type="file" name="backup_file" accept=".json,application/json"></label>
        <details><summary>Nebo vložit JSON jako text</summary><label>Obsah zálohy<textarea name="backup_json" rows="5" spellcheck="false" maxlength="<?= ConfigurationBackup::LIMIT ?>"><?= $escape($backupPreview === null ? $backupJson : '') ?></textarea></label></details>
        <p class="panel-help">Nejvýše 2 MiB. Vybraný soubor má přednost před vloženým textem. Náhled nic nemění.</p>
        <button class="panel-action" type="submit"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit změny před importem</button>
      </form>
    </div>
    <?php if ($backupPreview !== null): ?>
      <section class="structure-import-preview" aria-labelledby="backup-preview-title"><h3 id="backup-preview-title">3. Přehled změn</h3>
        <div class="structure-metrics"><span><strong><?= $backupPreview['counts']['new'] ?></strong> nových</span><span><strong><?= $backupPreview['counts']['changed'] ?></strong> změněných</span><span><strong><?= $backupPreview['counts']['same'] ?></strong> beze změny</span></div>
        <?php $backupChanged = array_values(array_filter($backupPreview['changes'], static fn (array $row): bool => $row['state'] !== 'same')); ?>
        <?php if ($backupChanged === []): ?><p class="panel-notice">Tato záloha odpovídá současnému nastavení. Není co importovat.</p><?php else: ?>
          <ul class="structure-change-list"><?php foreach (array_slice($backupChanged, 0, 150) as $change): ?><li><span class="structure-badge"><?= $change['state'] === 'new' ? 'Nové' : 'Změna' ?></span><strong><?= $escape($change['label']) ?></strong><small><?= $escape(strtoupper($change['language'])) ?></small><?php if (is_string($change['after'])): ?><span class="structure-change-text"><?= $escape($change['after']) ?></span><?php endif; ?></li><?php endforeach; ?></ul>
          <?php if (count($backupChanged) > 150): ?><p>Dalších <?= count($backupChanged) - 150 ?> změn se uloží stejným importem.</p><?php endif; ?>
          <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($backupUrl . '#configuration-backup') ?>">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="configuration-apply"><input type="hidden" name="backup_kind" value="<?= $escape($backupKind) ?>"><input type="hidden" name="backup_token" value="<?= $escape($backupToken) ?>">
            <?php foreach ($backupReturn as $key => $value): ?><input type="hidden" name="<?= $escape($key) ?>" value="<?= $escape($value) ?>"><?php endforeach; ?>
            <input type="hidden" name="backup_json" value="<?= $escape($backupJson) ?>">
            <p>Import uloží výše uvedené změny společně. Položky shodné se zálohou ponechá beze změny.</p>
            <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('upload') ?> Importovat <?= count($backupChanged) ?> změn</button>
          </form>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
</details>
