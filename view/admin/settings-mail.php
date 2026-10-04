<?php
declare(strict_types=1);
$mailForm = $mailConfiguration['settings'];
$mailTemplates = $mailConfiguration['templates'];
$mailSettingsUrl = $adminUrl . '?section=settings&tab=mail';
?>
<?php if (!$mailSettingsReady): ?><p class="panel-notice">Nejdřív <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>. Původní potvrzení objednávky a faktury nadále používají odesílatele z údajů OSVČ.</p><?php endif; ?>
<?php if (($_GET['test'] ?? '') === 'sent'): ?><p class="panel-notice" role="status">Poštovní server test přijal. Doručení ověř v cílové schránce.</p><?php elseif (($_GET['test'] ?? '') === 'failed'): ?><p class="panel-error" role="alert">Poštovní server test nepřijal. Zpráva je uložená ve frontě k opakování.</p><?php endif; ?>
<form class="panel-form panel-settings-form" method="post" action="<?= $escape($mailSettingsUrl) ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save-mail-settings">
  <section class="panel-panel panel-settings-block">
    <h2>Odesílání zpráv</h2>
    <p class="panel-help">Vyplň SMTP údaje své schránky, aby se zprávy posílaly jejím serverem. Bez SMTP se použije PHP mail() hostingu. Chybné doručení lze zopakovat v <a href="<?= $escape($adminUrl . '?section=accounting&tab=mail') ?>">e-mailové frontě</a>.</p>
    <label class="panel-check"><input type="checkbox" name="automatic_enabled" value="1" <?= $mailForm['automatic_enabled'] ? 'checked' : '' ?>> Automaticky připravovat zprávy po událostech</label>
    <div class="panel-fields-two"><label>E-mail odesílatele<input type="email" name="from_email" value="<?= $escape($mailForm['from_email']) ?>" maxlength="254" placeholder="objednavky@dobrodruzi.cz"></label>
    <label>Jméno odesílatele<input name="from_name" value="<?= $escape($mailForm['from_name']) ?>" maxlength="100" required></label></div>
    <label>Adresa pro odpovědi (nepovinná)<input type="email" name="reply_to" value="<?= $escape($mailForm['reply_to']) ?>" maxlength="254" placeholder="podpora@dobrodruzi.cz"></label>
    <label>Veřejná HTTPS adresa obchodu<input type="url" name="public_base_url" value="<?= $escape($mailForm['public_base_url']) ?>" maxlength="500" placeholder="https://dobrodruzi.cz"></label>
    <p class="panel-help">Z této adresy se vytvoří soukromý odkaz na objednávku. Vyplň kořen instalace bez /cs. Ukládá se do databáze stejně jako ostatní hodnoty níže.</p>
    <label>E-mail pro obnovu hesla správce<input type="email" name="admin_recovery_email" value="<?= $escape($mailForm['admin_recovery_email']) ?>" maxlength="254" autocomplete="email" placeholder="spravce@dobrodruzi.cz"></label>
    <p class="panel-help">Odkaz pro obnovu administrace se pošle jen na tuto adresu. Záložní obnova na serveru zůstává: <code>php tools/admin.php --reset</code>.</p>
    <h3>SMTP server schránky</h3>
    <div class="panel-fields-two"><label>Server SMTP<input name="smtp_host" value="<?= $escape($mailForm['smtp_host']) ?>" maxlength="253" placeholder="smtp.example.cz" autocomplete="off"></label>
    <label>Port<input type="number" name="smtp_port" value="<?= (int) $mailForm['smtp_port'] ?>" min="1" max="65535" required></label></div>
    <div class="panel-fields-two"><label>Zabezpečení<select name="smtp_security"><option value="starttls" <?= $mailForm['smtp_security'] === 'starttls' ? 'selected' : '' ?>>STARTTLS (obvykle 587)</option><option value="tls" <?= $mailForm['smtp_security'] === 'tls' ? 'selected' : '' ?>>TLS od začátku (obvykle 465)</option></select></label>
    <label>Uživatelské jméno schránky<input name="smtp_username" value="<?= $escape($mailForm['smtp_username']) ?>" maxlength="254" autocomplete="off" placeholder="objednavky@dobrodruzi.cz"></label></div>
    <label>Heslo schránky<input type="password" name="smtp_password" maxlength="512" autocomplete="new-password" placeholder="<?= $mailForm['smtp_password_encrypted'] !== '' ? 'Heslo je uloženo; pro změnu napiš nové' : 'Heslo ke schránce' ?>"></label>
    <label class="panel-check"><input type="checkbox" name="smtp_clear_password" value="1"> Smazat uložené SMTP heslo</label>
    <p class="panel-help">Heslo se do stránky nikdy nevrací; v databázi je šifrované. Spolu s databází zálohuj také <code>config/.smtp-key.php</code>. Použij údaje pro odchozí poštu a nastav adresu odesílatele podle své schránky.</p>
  </section>
  <section class="panel-panel panel-settings-block">
    <h2>Obsah zpráv podle stavu</h2>
    <p class="panel-help">Zapni jen události, které chceš odesílat. Značky <code>{order_number}</code>, <code>{customer_name}</code>, <code>{total}</code>, <code>{carrier}</code> se nahradí při vytvoření zprávy. Souhrn objednávky, údaje k platbě a sledování zásilky se vkládají automaticky.</p>
    <?php foreach ($mailTemplates as $code => $template): ?>
      <details class="panel-settings-details" <?= $code === 'order' ? 'open' : '' ?>><summary><?= $escape($template['label']) ?></summary>
        <div class="panel-settings-fields">
          <label class="panel-check"><input type="checkbox" name="templates[<?= $escape($code) ?>][enabled]" value="1" <?= $template['enabled'] ? 'checked' : '' ?>> Posílat při této události</label>
          <label>Předmět<input name="templates[<?= $escape($code) ?>][subject]" value="<?= $escape($template['subject']) ?>" maxlength="190" required></label>
          <label>Úvodní text<textarea name="templates[<?= $escape($code) ?>][message]" rows="3" maxlength="5000" required><?= $escape($template['message']) ?></textarea></label>
          <a class="panel-text-link" href="<?= $escape($mailSettingsUrl . '&preview=' . $code) ?>">Náhled uložené šablony →</a>
        </div>
      </details>
    <?php endforeach; ?>
  </section>
  <div class="panel-settings-save"><button class="panel-button" type="submit" <?= !$mailSettingsReady ? 'disabled' : '' ?>>Uložit nastavení e-mailů</button></div>
</form>
<?php if ($mailPreview !== null): ?>
  <section class="panel-panel panel-settings-block" id="mail-preview"><h2>Náhled: <?= $escape($mailTemplates[$previewCode]['label']) ?></h2>
    <p class="panel-help">Ukázkové údaje; neodesílá se žádný e-mail. Náhled zobrazuje poslední uložený text.</p>
    <iframe title="Náhled e-mailu" sandbox srcdoc="<?= $escape($mailPreview['html']) ?>" style="width:100%;min-height:670px;border:1px solid #dde4da"></iframe>
    <a href="<?= $escape($mailSettingsUrl) ?>">Zavřít náhled</a>
  </section>
<?php endif; ?>
<section class="panel-panel panel-settings-block"><h2>Vyzkoušet doručení</h2>
  <p class="panel-help">Nejdřív ulož adresu odesílatele. Test vytvoří zprávu ve frontě a pokusí se ji odeslat.</p>
  <form class="panel-form" method="post" action="<?= $escape($mailSettingsUrl) ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="mail-test">
    <label>E-mail příjemce testu<input type="email" name="test_recipient" maxlength="254" required></label>
    <button class="panel-button" type="submit" <?= !$mailSettingsReady ? 'disabled' : '' ?>>Odeslat testovací e-mail</button>
  </form>
</section>
