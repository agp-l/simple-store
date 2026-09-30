<?php
declare(strict_types=1);
?>
<div class="panel-intro"><div><p class="panel-eyebrow">Pokladna</p><h1>Nastavení obchodu</h1>
  <p>Uprav dopravu, bankovní převod a stránku obchodních podmínek. Změny se použijí pro nové objednávky.</p></div></div>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Nastavení bylo uloženo.</p><?php endif; ?>
<?php if ($settingsError !== ''): ?><p class="panel-error" role="alert"><?= $escape($settingsError) ?></p><?php endif; ?>
<form class="panel-panel panel-form" method="post" action="<?= $escape($adminUrl . '?section=settings') ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
  <input type="hidden" name="action" value="save-checkout-settings">
  <h2>Výdejní místa a boxy</h2>
  <p class="panel-help">U Zásilkovny, GLS a Balíkovny zákazník vybere místo přímo v mapě; u PPL po nastavení klíče widgetu. Mapa Balíkovny ani GLS nevyžaduje klíč.</p>
  <?php foreach ($shippingCatalog as $code => $definition): ?>
    <?php if (str_ends_with($code, '_home') && $code === 'gls_home'): ?><h2>Na adresu</h2><?php endif; ?>
    <div class="panel-shipping-row"><strong><?= $escape($definition['label']) ?></strong><label>Cena v Kč<input type="number" name="shipping_price[<?= $escape($code) ?>]" value="<?= $escape($form['shipping_price'][$code]) ?>" min="0" max="100000" required></label><label class="panel-check"><input type="checkbox" name="shipping_enabled[<?= $escape($code) ?>]" value="1" <?= $form['shipping_enabled'][$code] === '1' ? 'checked' : '' ?>> Nabízet</label></div>
  <?php endforeach; ?>
  <h3>Zásilkovna – mapa výdejních míst</h3>
  <label>Veřejný API klíč widgetu<input name="packeta_api_key" value="<?= $escape($form['packeta_api_key'] ?? '') ?>" maxlength="16" pattern="[A-Za-z0-9]{16}" autocomplete="off" placeholder="16 znaků z klientské sekce"></label>
  <p class="panel-help">Klíč pro mapu získáš v klientské sekci Zásilkovny. Je určený pro webový widget; <strong>nevkládej sem API heslo</strong>. Bez klíče se doprava Zásilkovnou v pokladně nenabídne, i když je nahoře zapnutá.</p>
  <h3>PPL – mapa výdejních míst</h3>
  <label>API klíč PPL Widget 2.0<input name="ppl_widget_key" value="<?= $escape($form['ppl_widget_key'] ?? '') ?>" maxlength="512" autocomplete="off" placeholder="Klíč z administrace PPL"></label>
  <p class="panel-help">Klíč vytvoř a aktivuj v <a href="https://klient.ppl.cz/widgetadmin" target="_blank" rel="noopener noreferrer">administraci widgetu PPL</a> a povol doménu obchodu. Pro místní zkoušení musí být povolená i doména localhost, pokud ji PPL přijme. Bez klíče zůstane dosavadní ruční vyplnění místa PPL. Klíč je veřejný a načítá se na stránce pokladny.</p>
  <h3>Podávání zásilek</h3>
  <label>Označení odesílatele (hodnota pro API pole eshop)<input name="packeta_sender" value="<?= $escape($form['packeta_sender'] ?? '') ?>" maxlength="64" autocomplete="off" placeholder="Zkopíruj přesné Označení, ne název firmy"></label>
  <p class="panel-help">V <a href="https://client.packeta.com/senders/" target="_blank" rel="noopener noreferrer">klientské sekci Zásilkovny → Odesílatelé</a> zkopíruj hodnotu <strong>Označení</strong> ze čtvrtého sloupce u svého odesílatele. Samotný název firmy nebo e-shopu nemusí být stejný. Pokud odesílatel chybí, nejdřív ho tam vytvoř a ulož; účet musí být schválený.</p>
  <label>API heslo Zásilkovny<input type="password" name="packeta_api_password" value="" maxlength="128" autocomplete="new-password" placeholder="<?= !empty($packetaPasswordConfigured) ? 'Heslo je uloženo; pro změnu zadej nové' : 'Zadej soukromé API heslo' ?>"></label>
  <p class="panel-help">API heslo je jiné než veřejný klíč widgetu. Prázdné pole ponechá uložené heslo beze změny. Heslo se na veřejných stránkách ani v administraci znovu nevypisuje.</p>
  <?php if (!empty($packetaPasswordConfigured)): ?><label class="panel-check"><input type="checkbox" name="packeta_clear_password" value="1"> Odstranit uložené API heslo</label><?php endif; ?>
  <h2>Bankovní převod</h2>
  <label>Číslo účtu<input name="account_display" value="<?= $escape($form['account_display']) ?>" placeholder="číslo/kód banky" autocomplete="off"></label>
  <label>IBAN (nepovinný, z čísla účtu se dopočítá)<input name="iban" value="<?= $escape($form['iban']) ?>" autocomplete="off"></label>
  <label>Jméno příjemce<input name="recipient" value="<?= $escape($form['recipient']) ?>" maxlength="120" autocomplete="off"></label>
  <label>Splatnost v dnech<input type="number" name="payment_due_days" value="<?= $escape($form['payment_due_days']) ?>" min="1" max="60" required></label>
  <h2>Obchodní podmínky</h2>
  <label>Adresa publikované stránky<input name="terms_url" value="<?= $escape($form['terms_url']) ?>" placeholder="<?= $escape($basePath . 'cs/obchodni-podminky') ?>"></label>
  <p class="panel-help">Můžeš je doplnit později. Až stránku vytvoříš a publikuješ, vlož sem její cestu začínající <?= $escape($basePath) ?>.</p>
  <h2>Místní vývoj</h2>
  <label class="panel-check"><input type="checkbox" name="local_test_checkout" value="1" <?= $form['local_test_checkout'] === '1' ? 'checked' : '' ?>> Povolit testovací objednávky na localhostu, pokud chybí bankovní účet</label>
  <p class="panel-help">Testovací objednávka nemá platební údaje ani QR kód. Mimo localhost je tento režim vypnutý.</p>
  <button class="panel-button" type="submit">Uložit nastavení</button>
</form>
