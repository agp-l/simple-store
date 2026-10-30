<?php
declare(strict_types=1);
use SimpleStore\Accounting\MailContent;
$mailForm = $mailConfiguration['settings'];
$mailTemplates = $mailConfiguration['templates'];
$mailCopy = MailContent::copy($mailConfiguration);
$mailDefaults = MailContent::defaults();
$mailSettingsUrl = $adminUrl . '?section=settings&tab=mail';
$mailPreviewIsDraft ??= false;
$previewMode ??= 'own';
$mailGroups = MailContent::GROUPS;
$mailGroups['Stavy a řešení reklamací'] = [];
foreach ($mailDefaults as $key => $value) {
    if (str_starts_with($key, 'case_status_') || str_starts_with($key, 'remedy_')) {
        $mailGroups['Stavy a řešení reklamací'][$key] = [$value, $value];
    }
}
?>
<link rel="stylesheet" href="<?= $escape($basePath . 'assets/admin-mail.css?v=' . filemtime(__DIR__ . '/../../assets/admin-mail.css')) ?>">
<script defer src="<?= $escape($basePath . 'assets/admin-mail.js?v=' . filemtime(__DIR__ . '/../../assets/admin-mail.js')) ?>"></script>
<div class="mail-settings">
<?php if (!$mailSettingsReady): ?><p class="panel-notice">Nejdřív <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
<?php foreach (($mailLegalWarnings ?? []) as $warning): ?><p class="panel-error" role="alert"><?= $escape($warning) ?></p><?php endforeach; ?>
<?php if (($_GET['test'] ?? '') === 'sent'): ?><p class="panel-notice" role="status">Poštovní server test přijal. Doručení ověř v cílové schránce.</p><?php elseif (($_GET['test'] ?? '') === 'failed'): ?><p class="panel-error" role="alert">Poštovní server test nepřijal. Zpráva je uložená ve frontě k opakování.</p><?php endif; ?>
<div class="mail-settings-intro">
  <span class="mail-settings-icon" aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render('mail') ?></span>
  <div><h2>E-maily, které mluví za váš obchod</h2><p>Texty, podpis i drobné popisky na jednom místě. Náhled ukáže běžný nákup i dodání od partnera.</p></div>
  <a class="panel-text-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=accounting&tab=mail') ?>">Fronta a doručování <?= \SimpleStore\Rendering\Icon::render('arrow-right') ?></a>
</div>
<?php $backupKind = 'email-texts'; require __DIR__ . '/configuration-transfer.php'; ?>
<form class="panel-form panel-settings-form mail-editor" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($mailSettingsUrl) ?>" data-mail-form data-dirty="<?= $mailPreviewIsDraft ? 'true' : 'false' ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="mail">
  <section class="panel-panel mail-preview-controls">
    <div><h3>Vyzkoušet text a vzhled</h3><p class="panel-help">Náhled používá rozepsané texty a ukázkové údaje. Nic neukládá ani neodesílá.</p></div>
    <div class="mail-preview-choices">
      <label>Šablona<select name="preview_code"><?php foreach (MailContent::definitions() as $code => $definition): ?><option value="<?= $escape($code) ?>" <?= ($previewCode ?? 'order') === $code ? 'selected' : '' ?>><?= $escape($definition['label']) ?></option><?php endforeach; ?></select></label>
      <label>Ukázková objednávka<select name="preview_mode"><option value="own" <?= $previewMode === 'own' ? 'selected' : '' ?>>Zboží našeho obchodu</option><option value="assisted" <?= $previewMode === 'assisted' ? 'selected' : '' ?>>Asistovaný nákup u partnera</option><option value="mixed" <?= $previewMode === 'mixed' ? 'selected' : '' ?>>Kombinovaná objednávka</option></select></label>
      <button class="panel-action panel-button" type="submit" name="action" value="preview-mail" formnovalidate formaction="<?= \SimpleStore\Navigation\PanelUrl::escape($mailSettingsUrl . '#mail-preview') ?>"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit náhled</button>
    </div>
  </section>
  <div class="mail-editor-heading"><div><h2>Šablony zpráv</h2><p>Uprav předmět a úvod. Částky, kontakty a sjednané údaje doplní objednávka.</p></div><label class="mail-search" data-mail-search-wrap hidden>Najít text<input type="search" data-mail-search placeholder="Např. partner, podpis, heslo…"></label></div>
  <p class="panel-help">Automatické stavy lze zapnout jednotlivě. Faktury se při zapnutém automatickém odesílání pošlou po vystavení, jinak je odešleš ručně; obnova hesla na žádost uživatele. Potvrzení reklamace a odpovědi mají vlastní události.</p>
  <div class="mail-template-list">
  <?php foreach ($mailTemplates as $code => $template): $definition = MailContent::definitions()[$code]; $optional = isset(\SimpleStore\Accounting\MailSettingsRepository::EVENTS[$code]); ?>
    <details class="panel-settings-details mail-edit-group" data-mail-group <?= $code === 'order' ? 'open' : '' ?>>
      <summary><span class="mail-template-icon" data-tone="<?= $optional ? 'blue' : (str_contains($code, 'password') ? 'violet' : 'amber') ?>" aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render($code === 'invoice' ? 'document' : 'mail') ?></span><span><?= $escape($template['label']) ?></span><small><?= $optional ? ($template['enabled'] ? 'Zapnuto' : 'Vypnuto') : ($code === 'late_paid' ? 'Podle potvrzení platby' : 'Podle události') ?></small></summary>
      <div class="panel-settings-fields">
        <?php if ($optional): ?><label class="panel-check"><input type="checkbox" name="templates[<?= $escape($code) ?>][enabled]" value="1" <?= $template['enabled'] ? 'checked' : '' ?>> Posílat při této události</label><?php else: ?><p class="panel-help"><?= $code === 'late_paid' ? 'Tento text se použije místo běžného potvrzení platby, pokud byla objednávka zrušena.' : ($code === 'invoice' ? 'Odeslání po vystavení řídí společná volba automatického odesílání. Fakturu lze odeslat i ručně v Dokladech a platbách.' : 'Tato šablona má vlastní způsob odeslání; vypínač běžných objednávkových stavů ji neovládá.') ?></p><?php endif; ?>
        <label>Předmět<input name="templates[<?= $escape($code) ?>][subject]" value="<?= $escape($template['subject']) ?>" maxlength="190" data-mail-default="<?= $escape($definition['subject']) ?>" required></label>
        <label>Úvodní text<textarea name="templates[<?= $escape($code) ?>][message]" rows="5" maxlength="5000" data-mail-default="<?= $escape($definition['message']) ?>" required><?= $escape($template['message']) ?></textarea></label>
        <div class="mail-placeholder-help"><span>Dostupné značky:</span><?php foreach (MailContent::placeholders($code) as $placeholder): ?><code><?= $escape('{' . $placeholder . '}') ?></code><?php endforeach; ?></div>
        <div class="mail-group-actions"><button type="button" class="panel-text-link" data-mail-preview-code="<?= $escape($code) ?>" hidden><?= \SimpleStore\Rendering\Icon::render('eye') ?> Náhled této zprávy</button><button type="button" class="panel-text-link" data-mail-reset hidden>Obnovit výchozí texty</button></div>
      </div>
    </details>
  <?php endforeach; ?>
  </div>
  <div class="mail-editor-heading"><div><h2>Společné texty a popisky</h2><p>Podpis, tlačítka, platby i vysvětlení partnerského dodání. Úpravy platí pro nově vytvořené zprávy.</p></div></div>
  <p class="panel-help">Značky ve složených závorkách zachovej. Obsahují například jméno partnera nebo cenu. Uložené souhlasy zákazníka, údaje prodávajícího a znění sjednaných podmínek se čerpají z původní objednávky.</p>
  <?php foreach ($mailGroups as $group => $fields): ?>
    <details class="panel-settings-details mail-edit-group mail-copy-group" data-mail-group><summary><span><?= $escape($group) ?></span><small><?= count($fields) ?> textů</small></summary>
      <div class="panel-settings-fields"><div class="mail-copy-fields">
      <?php foreach ($fields as $key => [$label, $default]): $long = strlen($default) > 85 || str_contains($default, "\n"); ?>
        <label class="<?= $long ? 'mail-copy-long' : '' ?>"><?= $escape($label) ?>
          <?php if ($long): ?><textarea name="copy[<?= $escape($key) ?>]" rows="3" maxlength="2000" data-mail-default="<?= $escape($default) ?>"><?= $escape($mailCopy[$key]) ?></textarea>
          <?php else: ?><input name="copy[<?= $escape($key) ?>]" value="<?= $escape($mailCopy[$key]) ?>" maxlength="2000" data-mail-default="<?= $escape($default) ?>"><?php endif; ?>
        </label>
      <?php endforeach; ?>
      </div><div class="mail-group-actions"><button type="button" class="panel-text-link" data-mail-reset hidden>Obnovit výchozí texty této skupiny</button></div></div>
    </details>
  <?php endforeach; ?>
  <p class="panel-empty" data-mail-empty hidden>Takový text jsme nenašli. Zkus kratší výraz.</p>
  <details class="panel-settings-details mail-transport"><summary><?= \SimpleStore\Rendering\Icon::render('settings') ?> Odesílatel a doručování <small>SMTP a automatické odesílání</small></summary>
    <div class="panel-settings-fields">
    <h2>Odesílání zpráv</h2>
    <p class="panel-help">Vyplň SMTP údaje své schránky, aby se zprávy posílaly jejím serverem. Bez SMTP se použije PHP mail() hostingu. Chybné doručení lze zopakovat v <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=accounting&tab=mail') ?>">e-mailové frontě</a>.</p>
    <label class="panel-check"><input type="checkbox" name="automatic_enabled" value="1" <?= $mailForm['automatic_enabled'] ? 'checked' : '' ?>> Automaticky připravovat objednávková oznámení a faktury</label>
    <div class="panel-fields-two"><label>E-mail odesílatele<input type="email" name="from_email" value="<?= $escape($mailForm['from_email']) ?>" maxlength="254" placeholder="objednavky@dobrodruzi.cz"></label>
    <label>Jméno odesílatele<input name="from_name" value="<?= $escape($mailForm['from_name']) ?>" maxlength="100" required></label></div>
    <label>Adresa pro odpovědi (nepovinná)<input type="email" name="reply_to" value="<?= $escape($mailForm['reply_to']) ?>" maxlength="254" placeholder="podpora@dobrodruzi.cz"></label>
    <p class="panel-help">Adresa je společná pro celý web. Mění se v <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=installation') ?>">konfiguraci domény</a>.</p>
    <label>Veřejná HTTPS adresa obchodu<input type="url" readonly value="<?= $escape(\SimpleStore\Configuration\Installation::current()->baseUrl()) ?>" maxlength="500" ></label>
    <p class="panel-help">Z této adresy se vytvoří soukromý odkaz na objednávku a odkazy na publikované obchodní podmínky, vrácení zboží a reklamace. Vyplň kořen instalace bez /cs. Ukládá se do databáze stejně jako ostatní hodnoty níže.</p>
    <label>Kontaktní e-mail obchodu (oznámení)<input type="email" name="admin_recovery_email" value="<?= $escape($mailForm['admin_recovery_email']) ?>" maxlength="254" autocomplete="email" placeholder="spravce@dobrodruzi.cz" required></label>
    <p class="panel-help">Sem chodí oznámení obchodu. Přihlašovací e-maily a hesla správců měň v sekci Účty a administrátoři. Původní správce do prvního uložení svého účtu v nové správě používá k přihlášení tuto adresu.</p>
    <h3>SMTP server schránky</h3>
    <div class="panel-fields-two"><label>Server SMTP<input name="smtp_host" value="<?= $escape($mailForm['smtp_host']) ?>" maxlength="253" placeholder="smtp.example.cz" autocomplete="off"></label>
    <label>Port<input type="number" name="smtp_port" value="<?= (int) $mailForm['smtp_port'] ?>" min="1" max="65535" required></label></div>
    <div class="panel-fields-two"><label>Zabezpečení<select name="smtp_security"><option value="starttls" <?= $mailForm['smtp_security'] === 'starttls' ? 'selected' : '' ?>>STARTTLS (obvykle 587)</option><option value="tls" <?= $mailForm['smtp_security'] === 'tls' ? 'selected' : '' ?>>TLS od začátku (obvykle 465)</option></select></label>
    <label>Uživatelské jméno schránky<input name="smtp_username" value="<?= $escape($mailForm['smtp_username']) ?>" maxlength="254" autocomplete="off" placeholder="objednavky@dobrodruzi.cz"></label></div>
    <label>Heslo schránky<input type="text" spellcheck="false" name="smtp_password" value="<?= $escape($smtpPasswordValue ?? '') ?>" maxlength="512" autocomplete="off" placeholder="<?= $mailForm['smtp_password_encrypted'] !== '' ? 'Heslo je uloženo; pro změnu napiš nové' : 'Heslo ke schránce' ?>"></label>
    <label class="panel-check"><input type="checkbox" name="smtp_clear_password" value="1"> Smazat uložené SMTP heslo</label>
    <p class="panel-help">Uložené heslo je zde viditelné pro správce; v databázi zůstává šifrované. Prázdné pole heslo zachová. Pro odstranění pole vyprázdni a zaškrtni smazání. Spolu s databází zálohuj také <code>config/.smtp-key.php</code>. Použij údaje pro odchozí poštu a nastav adresu odesílatele podle své schránky.</p>
    </div>
  </details>
  <div class="mail-save-bar"><div><strong data-mail-state aria-live="polite"><?= $mailPreviewIsDraft ? 'Rozepsané změny nejsou uložené' : 'Uložené nastavení' ?></strong><span>Již vytvořené zprávy ve frontě si ponechají původní obsah.</span></div><button class="panel-action panel-button" type="submit" name="action" value="save-mail-settings" <?= !$mailSettingsReady ? 'disabled' : '' ?>><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit nastavení e-mailů</button></div>
</form>
<?php if ($mailPreview !== null): ?>
  <section class="panel-panel mail-preview-panel" id="mail-preview">
    <div class="mail-editor-heading"><div><h2><?= $escape($mailTemplates[$previewCode]['label']) ?></h2><p><?= $mailPreviewIsDraft ? 'Náhled rozepsaného textu · změny zatím nejsou uložené' : 'Náhled uloženého textu' ?></p></div><div class="mail-device-buttons" aria-label="Šířka náhledu" hidden><button type="button" data-mail-width="desktop" aria-pressed="true">Počítač</button><button type="button" data-mail-width="mobile" aria-pressed="false">Mobil</button></div></div>
    <dl class="mail-preview-subject"><dt>Předmět</dt><dd><?= $escape($mailPreview['subject']) ?></dd></dl>
    <p class="panel-help">Jde o ukázkové údaje.<?php if (in_array($previewCode, ['order', 'paid', 'late_paid'], true)): ?> U skutečného potvrzení se znění podmínek platné při objednání připojí jako samostatný soubor.<?php endif; ?></p>
    <div class="mail-preview-stage"><iframe title="Náhled e-mailu" sandbox srcdoc="<?= $escape($mailPreview['html']) ?>"></iframe></div>
    <details class="panel-settings-details"><summary>Textová verze zprávy</summary><pre class="mail-plain-preview"><?= $escape($mailPreview['text']) ?></pre></details>
  </section>
<?php endif; ?>
<section class="panel-panel panel-settings-block"><h2>Vyzkoušet doručení</h2>
  <p class="panel-help">Nejdřív ulož adresu odesílatele. Test vytvoří zprávu ve frontě a pokusí se ji odeslat.</p>
  <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($mailSettingsUrl) ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="mail-test">
    <label>E-mail příjemce testu<input type="email" name="test_recipient" maxlength="254" required></label>
    <button class="panel-action panel-button" type="submit" <?= !$mailSettingsReady ? 'disabled' : '' ?>><?= \SimpleStore\Rendering\Icon::render('mail') ?> Odeslat testovací e-mail</button>
  </form>
</section>

</div>

