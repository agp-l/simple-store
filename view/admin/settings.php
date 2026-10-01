<?php
declare(strict_types=1);
$form += ['btc_prices_enabled' => '1', 'comgate_enabled' => '0', 'comgate_test' => '1', 'comgate_merchant' => '',
    'comgate_return_base_url' => '', 'gopay_enabled' => '0', 'gopay_test' => '1',
    'gopay_goid' => '', 'gopay_client_id' => '', 'gopay_return_base_url' => '',
    'btcpay_enabled' => '0', 'btcpay_server_url' => '', 'btcpay_store_id' => '',
    'btcpay_return_base_url' => ''];
$comgateSecretConfigured ??= false;
$gopaySecretConfigured ??= false;
$btcpayApiKeyConfigured ??= false;
$btcpayWebhookSecretConfigured ??= false;
?>
<div class="panel-intro"><div><p class="panel-eyebrow">Pokladna</p><h1>Nastavení obchodu</h1>
  <p>Uprav dopravu, platby a stránku obchodních podmínek. Změny se použijí pro nové objednávky.</p></div></div>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Nastavení bylo uloženo.</p><?php endif; ?>
<?php if ($settingsError !== ''): ?><p class="panel-error" role="alert"><?= $escape($settingsError) ?></p><?php endif; ?>
<form class="panel-panel panel-form" method="post" action="<?= $escape($adminUrl . '?section=settings') ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
  <input type="hidden" name="action" value="save-checkout-settings">
  <h2>Zobrazení cen</h2>
  <label class="panel-check"><input type="checkbox" name="btc_prices_enabled" value="1" <?= $form['btc_prices_enabled'] === '1' ? 'checked' : '' ?>> Ukazovat orientační cenu v BTC vedle ceny v Kč</label>
  <p class="panel-help">Přepočet používá kurz CoinGecko uložený na 15 minut. Objednávky se dál účtují v Kč; skutečnou částku k platbě bitcoinem určí BTCPay při vytvoření platby. Když kurz není dostupný, zobrazí se jen cena v Kč. Vyžaduje aktualizovanou databázi.</p>
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
  <h2>Comgate – online platba</h2>
  <label class="panel-check"><input type="checkbox" name="comgate_enabled" value="1" <?= $form['comgate_enabled'] === '1' ? 'checked' : '' ?>> Nabízet platbu přes Comgate (po vyplnění přístupových údajů)</label>
  <label class="panel-check"><input type="checkbox" name="comgate_test" value="1" <?= $form['comgate_test'] === '1' ? 'checked' : '' ?>> Testovací režim Comgate</label>
  <p class="panel-help">Testovací režim vyžaduje vlastní identifikátor obchodníka a tajný klíč Comgate. Vypni jej až po ověření testovací platby a schválení produkčního provozu.</p>
  <label>Identifikátor obchodníka (merchant)<input name="comgate_merchant" value="<?= $escape($form['comgate_merchant']) ?>" maxlength="100" autocomplete="off" placeholder="Merchant ID z klientského portálu"></label>
  <label>Veřejná HTTPS adresa obchodu<input type="url" name="comgate_return_base_url" value="<?= $escape($form['comgate_return_base_url']) ?>" maxlength="1000" autocomplete="off" placeholder="<?= $escape('https://obchod.cz' . rtrim($basePath, '/')) ?>"></label>
  <p class="panel-help">Vlož kořenovou adresu této instalace bez posledního lomítka. Comgate po platbě zákazníka vrací na adresy <code>url_paid</code>, <code>url_pending</code> a <code>url_cancelled</code>, které obchod pro každou platbu vygeneruje automaticky z této adresy. Adresa musí být přístupná z internetu přes HTTPS, také během testování.</p>
  <label>Tajný klíč API (secret)<input type="password" name="comgate_secret" value="" maxlength="256" autocomplete="new-password" placeholder="<?= $comgateSecretConfigured ? 'Klíč je uložen; pro změnu zadej nový' : 'Tajný klíč z klientského portálu' ?>"></label>
  <p class="panel-help">Prázdné pole ponechá uložený klíč beze změny. Klíč se znovu nezobrazuje ani se neposílá zákazníkovi. V Comgate nastav adresu pro PUSH oznámení na <code><?= $escape(($form['comgate_return_base_url'] !== '' ? rtrim($form['comgate_return_base_url'], '/') : 'https://obchod.cz' . rtrim($basePath, '/')) . '/comgate-callback.php') ?></code>. Návratové adresy pro zaplacenou, čekající a zrušenou platbu se předají API automaticky. Pro místní test přijímající oznámení použij veřejnou testovací doménu; Comgate se na localhost nedostane.</p>
  <?php if ($comgateSecretConfigured): ?><label class="panel-check"><input type="checkbox" name="comgate_clear_secret" value="1"> Odstranit uložený tajný klíč</label><?php endif; ?>
  <h2>GoPay – online platba</h2>
  <label class="panel-check"><input type="checkbox" name="gopay_enabled" value="1" <?= $form['gopay_enabled'] === '1' ? 'checked' : '' ?>> Nabízet platbu přes GoPay (po vyplnění přístupových údajů)</label>
  <label class="panel-check"><input type="checkbox" name="gopay_test" value="1" <?= $form['gopay_test'] === '1' ? 'checked' : '' ?>> Testovací prostředí GoPay</label>
  <p class="panel-help">Testovací prostředí vyžaduje testovací GoID, Client ID a Client secret přidělené GoPay. Před ostrým provozem ověř testovací objednávku a potom vyplň produkční údaje a vypni testovací režim.</p>
  <label>GoID obchodníka<input name="gopay_goid" value="<?= $escape($form['gopay_goid']) ?>" maxlength="20" inputmode="numeric" pattern="[0-9]+" autocomplete="off" placeholder="Číselné GoID od GoPay"></label>
  <label>Client ID<input name="gopay_client_id" value="<?= $escape($form['gopay_client_id']) ?>" maxlength="256" autocomplete="off" placeholder="Client ID od GoPay"></label>
  <label>Veřejná HTTPS adresa obchodu<input type="url" name="gopay_return_base_url" value="<?= $escape($form['gopay_return_base_url']) ?>" maxlength="1000" autocomplete="off" placeholder="<?= $escape('https://obchod.cz' . rtrim($basePath, '/')) ?>"></label>
  <p class="panel-help">Vlož kořenovou adresu této instalace bez posledního lomítka. Tuto adresu musí být možné otevřít z internetu přes HTTPS, také při testování.</p>
  <label>Client secret<input type="password" name="gopay_client_secret" value="" maxlength="256" autocomplete="new-password" placeholder="<?= $gopaySecretConfigured ? 'Klíč je uložen; pro změnu zadej nový' : 'Client secret od GoPay' ?>"></label>
  <p class="panel-help">Prázdné pole ponechá uložený klíč beze změny. Tajný klíč se zde znovu nevypisuje. GoPay zavolá URL oznámení <code><?= $escape(($form['gopay_return_base_url'] !== '' ? rtrim($form['gopay_return_base_url'], '/') : 'https://obchod.cz' . rtrim($basePath, '/')) . '/gopay-callback.php') ?></code> a zákazníka vrátí přes <code>gopay-return.php</code>. Místní localhost GoPay nemůže zavolat.</p>
  <?php if ($gopaySecretConfigured): ?><label class="panel-check"><input type="checkbox" name="gopay_clear_secret" value="1"> Odstranit uložený Client secret</label><?php endif; ?>
  <h2>BTCPay Server – platba bitcoinem</h2>
  <label class="panel-check"><input type="checkbox" name="btcpay_enabled" value="1" <?= $form['btcpay_enabled'] === '1' ? 'checked' : '' ?>> Nabízet platbu bitcoinem přes BTCPay Server</label>
  <p class="panel-help">V BTCPay vytvoř obchod s nastavenou peněženkou. API klíči uděl jen oprávnění <code>btcpay.store.cancreateinvoice</code> a <code>btcpay.store.canviewinvoices</code> pro tento obchod. Platební metoda se nabídne až po úplném nastavení.</p>
  <label>HTTPS adresa instance BTCPay<input type="url" name="btcpay_server_url" value="<?= $escape($form['btcpay_server_url']) ?>" maxlength="1000" autocomplete="off" placeholder="https://platby.obchod.cz"></label>
  <label>ID obchodu (Store ID)<input name="btcpay_store_id" value="<?= $escape($form['btcpay_store_id']) ?>" maxlength="128" autocomplete="off" placeholder="ID obchodu v BTCPay"></label>
  <label>Veřejná HTTPS adresa tohoto obchodu<input type="url" name="btcpay_return_base_url" value="<?= $escape($form['btcpay_return_base_url']) ?>" maxlength="1000" autocomplete="off" placeholder="<?= $escape('https://obchod.cz' . rtrim($basePath, '/')) ?>"></label>
  <p class="panel-help">Vlož kořenovou adresu této instalace, ze které BTCPay zavolá webhook. Pro místní test z localhostu potřebuješ veřejně dostupnou testovací adresu.</p>
  <label>API klíč BTCPay<input type="password" name="btcpay_api_key" value="" maxlength="512" autocomplete="new-password" placeholder="<?= $btcpayApiKeyConfigured ? 'Klíč je uložen; pro změnu zadej nový' : 'API klíč pro tento BTCPay obchod' ?>"></label>
  <?php if ($btcpayApiKeyConfigured): ?><label class="panel-check"><input type="checkbox" name="btcpay_clear_api_key" value="1"> Odstranit uložený API klíč</label><?php endif; ?>
  <label>Tajný klíč webhooku<input type="password" name="btcpay_webhook_secret" value="" maxlength="512" autocomplete="new-password" placeholder="<?= $btcpayWebhookSecretConfigured ? 'Klíč je uložen; pro změnu zadej nový' : 'Secret z nastavení webhooku v BTCPay' ?>"></label>
  <?php if ($btcpayWebhookSecretConfigured): ?><label class="panel-check"><input type="checkbox" name="btcpay_clear_webhook_secret" value="1"> Odstranit uložený tajný klíč webhooku</label><?php endif; ?>
  <p class="panel-help">V BTCPay v nastavení tohoto obchodu založ webhook pro události faktur. Jeho URL nastav na <code><?= $escape(($form['btcpay_return_base_url'] !== '' ? rtrim($form['btcpay_return_base_url'], '/') : 'https://obchod.cz' . rtrim($basePath, '/')) . '/btcpay-callback.php') ?></code>. Secret webhooku zkopíruj sem. Prázdná pole s klíči ponechají uložené hodnoty beze změny; klíče se na stránce znovu nevypisují.</p>
  <h2>Obchodní podmínky</h2>
  <label>Adresa publikované stránky<input name="terms_url" value="<?= $escape($form['terms_url']) ?>" placeholder="<?= $escape($basePath . 'cs/obchodni-podminky') ?>"></label>
  <p class="panel-help">Můžeš je doplnit později. Až stránku vytvoříš a publikuješ, vlož sem její cestu začínající <?= $escape($basePath) ?>.</p>
  <h2>Místní vývoj</h2>
  <label class="panel-check"><input type="checkbox" name="local_test_checkout" value="1" <?= $form['local_test_checkout'] === '1' ? 'checked' : '' ?>> Povolit testovací objednávky na localhostu, pokud chybí bankovní účet</label>
  <p class="panel-help">Testovací objednávka nemá platební údaje ani QR kód. Mimo localhost je tento režim vypnutý.</p>
  <button class="panel-button" type="submit">Uložit nastavení</button>
</form>
