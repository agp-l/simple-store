<?php
use SimpleStore\Product\PurchasePartnerTransfer as PartnerTransfer;
$partnerBackupError = $partnerBackupError ?? '';
$partnerBackupPlan = $partnerBackupPlan ?? null;
$partnerBackupJson = $partnerBackupJson ?? '';
$partnerBackupUrl = $partnerBackupUrl ?? ($adminUrl . '?section=purchase-partners');
$partnerFieldLabels = ['name' => 'Firma', 'ico' => 'IČO', 'address' => 'Adresa', 'email' => 'E-mail', 'website_url' => 'Web', 'terms_url' => 'Obchodní podmínky', 'confirmed' => 'Nákup jménem zákazníka ověřen'];
?>
<section class="panel-panel partner-backup" id="partner-backup" aria-labelledby="partner-backup-title">
  <h2 id="partner-backup-title"><?= \SimpleStore\Rendering\Icon::render('download') ?> Import a export prodávajících</h2>
  <p>Záloha celého seznamu v JSON. Import doplní nové profily a aktualizuje nalezené podle stálého identifikátoru nebo jednoznačného IČO. Ostatní profily nesmaže; přiřazení produktů a přijaté objednávky zachová.</p>
  <?php if (($_GET['imported'] ?? '') === '1' && $partnerBackupError === ''): ?><p class="panel-notice" role="status">Import prodávajících byl dokončen. Stejný soubor můžeš použít opakovaně bez vytváření duplicit.</p><?php endif; ?>
  <?php if ($partnerBackupError !== ''): ?><p class="panel-error" role="alert"><?= $escape($partnerBackupError) ?></p><?php endif; ?>
  <div class="partner-backup-grid">
    <div class="partner-backup-card"><h3>1. Stáhnout zálohu</h3><p>Všechny uložené profily, včetně webů a rozpracovaných údajů. Hledání nahoře export neomezuje.</p><a class="panel-action" href="<?= \SimpleStore\Navigation\PanelUrl::escape($partnerBackupUrl . '&backup=export') ?>"><?= \SimpleStore\Rendering\Icon::render('download') ?> Exportovat prodávající do JSON</a><p class="panel-help">Záloha obsahuje i stav ověření nákupu jménem zákazníka. U nových firem ho potvrď až po skutečném ověření.</p></div>
    <form class="panel-form partner-backup-card" method="post" enctype="multipart/form-data" action="<?= \SimpleStore\Navigation\PanelUrl::escape($partnerBackupUrl . '#partner-backup') ?>">
      <h3>2. Vybrat JSON</h3><input type="hidden" name="action" value="purchase-partner-preview"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
      <label>Soubor prodávajících<input type="file" name="backup_file" accept=".json,application/json"></label>
      <details <?= $partnerBackupJson !== '' && $partnerBackupPlan === null ? 'open' : '' ?>><summary>Nebo vložit JSON jako text</summary><label>Obsah JSON<textarea name="backup_json" rows="7" maxlength="<?= PartnerTransfer::LIMIT ?>" spellcheck="false"><?= $escape($partnerBackupPlan === null ? $partnerBackupJson : '') ?></textarea></label></details>
      <p class="panel-help">Nejvýše 2 MiB a 1 000 profilů. Soubor má přednost před textem. Náhled zatím nic nemění.</p>
      <button class="panel-action" type="submit"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit náhled importu</button>
    </form>
  </div>
  <?php if ($partnerBackupPlan !== null): ?>
    <section class="partner-import-preview" aria-labelledby="partner-preview-title"><h3 id="partner-preview-title">3. Zkontrolovat a importovat</h3>
      <div class="partner-import-counts" role="status"><span><strong><?= $partnerBackupPlan['counts']['new'] ?></strong> nových</span><span><strong><?= $partnerBackupPlan['counts']['changed'] ?></strong> změněných</span><span><strong><?= $partnerBackupPlan['counts']['same'] ?></strong> beze změny</span></div>
      <?php if ($partnerBackupPlan['counts']['new'] + $partnerBackupPlan['counts']['changed'] === 0): ?><p class="panel-notice">Všechny profily už odpovídají souboru. Není co importovat.</p><?php else: ?>
      <p>U změněných profilů se uvedené údaje projeví u všech přiřazených produktů. Starší objednávky si ponechají původní údaje.</p>
      <ul class="partner-change-list"><?php foreach (array_slice($partnerBackupPlan['changes'], 0, 150) as $change): ?>
        <li><div><span class="partner-state <?= $change['state'] === 'same' ? 'is-ready' : 'is-draft' ?>"><?= ['new'=>'Nový','changed'=>'Změna','same'=>'Beze změny'][$change['state']] ?></span> <strong><?= $escape($change['after']['name'] ?: 'Rozpracovaný profil') ?></strong> <span>IČO <?= $escape($change['after']['ico'] ?: 'nevyplněno') ?></span></div>
        <?php if ($change['state'] !== 'same'): ?><details><summary>Zobrazit importované údaje<?= $change['state'] === 'changed' ? ' a původní hodnoty' : '' ?></summary><dl><?php foreach ($partnerFieldLabels as $field => $label): if ($change['state'] === 'changed' && $change['before'][$field] === $change['after'][$field]) continue; ?>
          <dt><?= $escape($label) ?></dt><dd><?php if ($change['state'] === 'changed'): ?><span>Původně: <?= $escape($field === 'confirmed' ? ($change['before'][$field] ? 'Ano' : 'Ne') : ($change['before'][$field] ?: '—')) ?></span><br><?php endif; ?><strong><?= $escape($field === 'confirmed' ? ($change['after'][$field] ? 'Ano' : 'Ne') : ($change['after'][$field] ?: '—')) ?></strong></dd>
        <?php endforeach; ?></dl></details><?php endif; ?></li>
      <?php endforeach; ?></ul>
      <?php if (count($partnerBackupPlan['changes']) > 150): ?><p>Náhled zobrazuje prvních 150 profilů. Import zahrne celý zkontrolovaný soubor.</p><?php endif; ?>
      <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($partnerBackupUrl . '#partner-backup') ?>"><input type="hidden" name="action" value="purchase-partner-import"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="backup_token" value="<?= $escape($partnerBackupToken) ?>"><input type="hidden" name="backup_json" value="<?= $escape($partnerBackupJson) ?>"><button class="panel-button panel-action" type="submit"><?= \SimpleStore\Rendering\Icon::render('upload') ?> Importovat profily (<?= $partnerBackupPlan['counts']['new'] + $partnerBackupPlan['counts']['changed'] ?>)</button></form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</section>
