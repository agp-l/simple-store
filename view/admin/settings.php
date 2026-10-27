<?php
declare(strict_types=1);
$settingsTab ??= 'overview';
$paymentArea ??= $settingsTab === 'payment' ? 'bank' : '';
if ($settingsTab === 'payment' && !in_array($paymentArea, ['bank', 'fio', 'comgate', 'gopay', 'btcpay'], true)) {
    $paymentArea = 'bank';
}
$paymentDraftSecrets ??= [];
$form += ['btc_prices_enabled' => '1', 'bank_transfer_enabled' => '1', 'bank_source' => 'manual', 'fio_enabled' => '0',
    'fio_account_display' => '', 'comgate_enabled' => '0', 'comgate_test' => '1', 'comgate_merchant' => '',
    'comgate_return_base_url' => '', 'gopay_enabled' => '0', 'gopay_test' => '1',
    'gopay_goid' => '', 'gopay_client_id' => '', 'gopay_return_base_url' => '',
    'btcpay_enabled' => '0', 'btcpay_server_url' => '', 'btcpay_store_id' => '',
    'btcpay_return_base_url' => ''];
$comgateSecretConfigured ??= false;
$gopaySecretConfigured ??= false;
$btcpayApiKeyConfigured ??= false;
$btcpayWebhookSecretConfigured ??= false;
?>
<div class="panel-intro"><div><p class="panel-eyebrow">Správa obchodu</p><h1>Nastavení obchodu</h1>
  <p>Každá oblast má vlastní stránku a ukládá se samostatně.</p></div></div>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Nastavení bylo uloženo do databáze.</p><?php endif; ?>
<?php if ($settingsError !== ''): ?><p class="panel-error" role="alert"><?= $escape($settingsError) ?></p><?php endif; ?>
<?php require __DIR__ . '/settings-navigation.php'; ?>
<?php if ($settingsTab === 'overview'): ?>
<div class="panel-settings-overview">
  <?php foreach ([
      ['products', 'Hromadná úprava produktů', 'Přiřazení prodávajících, způsob prodeje, sklad a zveřejnění produktů.'],
      ['appearance', 'Texty webu', 'Hlavička, výpis produktů, blog a patička.'],
      ['delivery', 'Doprava', 'Ceník a dostupnost výdejních míst i doručení na adresu.'],
      ['carriers', 'Dopravci', 'Widgety výdejních míst a údaje pro podávání zásilek.'],
      ['payment', 'Platby', 'Bankovní převod a připojené platební brány.'],
      ['prices', 'Ceny', 'Zobrazení orientační ceny v BTC.'],
      ['mail', 'E-maily', 'SMTP, potvrzení objednávek a texty zpráv.'],
      ['legal', 'Obchodní podmínky', 'Odkaz na publikovanou stránku obchodních podmínek.'],
  ] as [$key, $title, $description]): ?>
    <a class="panel-settings-card" data-admin-setting="<?= $escape($key) ?>" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=' . $key) ?>"><span class="admin-setting-icon" aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render(['products'=>'package', 'appearance'=>'edit', 'delivery'=>'truck', 'carriers'=>'package', 'payment'=>'card', 'prices'=>'card', 'mail'=>'mail', 'legal'=>'document'][$key]) ?></span><span class="admin-setting-arrow" aria-hidden="true"><?= \SimpleStore\Rendering\Icon::render('arrow-right') ?></span><strong><?= $escape($title) ?></strong><span><?= $escape($description) ?></span></a>
  <?php endforeach; ?>
</div>
<?php elseif ($settingsTab === 'products'): ?>
<?php require __DIR__ . '/settings-products.php'; ?>
<?php elseif ($settingsTab === 'mail'): ?>
<?php require __DIR__ . '/settings-mail.php'; ?>
<?php elseif ($settingsTab === 'appearance'): ?>
<?php $backupKind = 'site-texts'; require __DIR__ . '/configuration-transfer.php'; ?>
<form class="panel-form panel-settings-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=appearance') ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
  <input type="hidden" name="action" value="save-site-copy">
  <input type="hidden" name="tab" value="appearance">
  <section class="panel-panel panel-settings-block">
    <h2>Texty společné pro celý web</h2>
    <input type="hidden" name="language" value="<?= $escape($copyLanguage) ?>">
    <?php if (count($site['languages']) > 1): ?>
      <nav class="panel-quick" aria-label="Jazyk textů">
        <?php foreach ($site['languages'] as $code): ?><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=appearance&language=' . $code) ?>" <?= $copyLanguage === $code ? 'aria-current="page"' : '' ?>><?= $escape(strtoupper($code)) ?></a><?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <p class="panel-help">Texty pro jazyk <?= $escape(strtoupper($copyLanguage)) ?>. Každý jazyk má vlastní znění.</p>
    <label>Nadpis v hlavičce<input name="hero_title" value="<?= $escape($siteCopyValues['hero_title']) ?>" maxlength="90" required></label>
    <label>Text pod nadpisem<input name="hero_subtitle" value="<?= $escape($siteCopyValues['hero_subtitle']) ?>" maxlength="200" required></label>
    <h3>Výpis produktů</h3>
    <label>Nadpis výběru na úvodní stránce<input name="catalog_home_title" value="<?= $escape($siteCopyValues['catalog_home_title']) ?>" maxlength="90" required></label>
    <label>Nadpis celého katalogu<input name="catalog_title" value="<?= $escape($siteCopyValues['catalog_title']) ?>" maxlength="90" required></label>
    <label>Text pod výběrem na úvodní stránce<input name="catalog_home_intro" value="<?= $escape($siteCopyValues['catalog_home_intro']) ?>" maxlength="200" required></label>
    <label>Text pod celým katalogem<input name="catalog_all_intro" value="<?= $escape($siteCopyValues['catalog_all_intro']) ?>" maxlength="200" required></label>
    <label>Text v kategorii<input name="catalog_category_intro" value="<?= $escape($siteCopyValues['catalog_category_intro']) ?>" maxlength="200" required></label>
    <fieldset class="panel-settings-block" id="blog-texts">
      <legend><strong><?= \SimpleStore\Rendering\Icon::render('book') ?> Úvod blogu</strong></legend>
      <p class="panel-help">Tyto čtyři texty se zobrazují nad články na stránce Blog. Uloží se společně tlačítkem Uložit texty.</p>
      <label>Krátký nadpis nad titulkem blogu<input name="blog_eyebrow" value="<?= $escape($siteCopyValues['blog_eyebrow']) ?>" maxlength="200" required></label>
      <label>Hlavní nadpis blogu<input name="blog_title" value="<?= $escape($siteCopyValues['blog_title']) ?>" maxlength="90" required></label>
      <label>Úvodní text blogu<input name="blog_intro" value="<?= $escape($siteCopyValues['blog_intro']) ?>" maxlength="200" required></label>
      <label>Krátké motto blogu<input name="blog_note" value="<?= $escape($siteCopyValues['blog_note']) ?>" maxlength="200" required></label>
    </fieldset>
    <h3>Patička</h3>
    <label>Úvodní text patičky<input name="footer_intro" value="<?= $escape($siteCopyValues['footer_intro']) ?>" maxlength="200" required></label>
    <label>Krátký závěr patičky<input name="footer_closing" value="<?= $escape($siteCopyValues['footer_closing']) ?>" maxlength="200" required></label>
  </section>
  <div class="panel-settings-save"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit texty</button></div>
</form>
<?php else: ?>
<?php if ($settingsTab !== 'payment'): ?>
<form class="panel-form panel-settings-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=' . $settingsTab) ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
  <input type="hidden" name="action" value="save-checkout-settings">
  <input type="hidden" name="tab" value="<?= $escape($settingsTab) ?>">
<?php endif; ?>
<?php if ($settingsTab === 'prices'): ?>
  <section class="panel-panel panel-settings-block" id="settings-prices">
  <h2>Zobrazení cen</h2>
  <label class="panel-check"><input type="checkbox" name="btc_prices_enabled" value="1" <?= $form['btc_prices_enabled'] === '1' ? 'checked' : '' ?>> Ukazovat orientační cenu v BTC pod cenou v Kč</label>
  <p class="panel-help">Přepočet používá kurz CoinGecko uložený na 15 minut. Objednávky se dál účtují v Kč; skutečnou částku k platbě bitcoinem určí BTCPay při vytvoření platby. Když kurz není dostupný, zobrazí se jen cena v Kč. Vyžaduje aktualizovanou databázi.</p>
  </section>
<?php endif; ?>
<?php if ($settingsTab === 'delivery'): ?>
  <section class="panel-panel panel-settings-block" id="settings-delivery">
  <h2>Doprava</h2><h3>Výdejní místa a boxy</h3>
  <p class="panel-help">U Zásilkovny a PPL zákazník vybere místo přímo v mapě po nastavení klíče widgetu. Bez klíče otevře veřejnou mapu a adresu opíše. Mapa Balíkovny ani GLS klíč nevyžaduje.</p>
  <?php foreach ($shippingCatalog as $code => $definition): ?>
    <?php if (str_ends_with($code, '_home') && $code === 'gls_home'): ?><h3>Na adresu</h3><?php endif; ?>
    <div class="panel-shipping-row"><strong><?= $escape($definition['label']) ?></strong><label>Cena v Kč<input type="number" name="shipping_price[<?= $escape($code) ?>]" value="<?= $escape($form['shipping_price'][$code]) ?>" min="0" max="100000" required></label><label class="panel-check"><input type="checkbox" name="shipping_enabled[<?= $escape($code) ?>]" value="1" <?= $form['shipping_enabled'][$code] === '1' ? 'checked' : '' ?>> Nabízet</label></div>
  <?php endforeach; ?>
  </section>
<?php endif; ?>
<?php if ($settingsTab === 'carriers'): ?>
  <section class="panel-panel panel-settings-block" id="settings-carriers">
  <h2>Napojení dopravců</h2><h3>Zásilkovna – mapa výdejních míst</h3>
  <label>Veřejný API klíč widgetu<input name="packeta_api_key" value="<?= $escape($form['packeta_api_key'] ?? '') ?>" maxlength="16" pattern="[A-Za-z0-9]{16}" autocomplete="off" placeholder="16 znaků z klientské sekce"></label>
  <p class="panel-help">Klíč pro automatický výběr získáš v klientské sekci Zásilkovny. Je určený pro webový widget; <strong>nevkládej sem API heslo</strong>. Bez klíče zůstane doprava dostupná, pokud je nahoře zapnutá: zákazník otevře veřejnou mapu a název a adresu místa opíše ručně.</p>
  <h3>PPL – mapa výdejních míst</h3>
  <label>API klíč PPL Widget 2.0<input name="ppl_widget_key" value="<?= $escape($form['ppl_widget_key'] ?? '') ?>" maxlength="512" autocomplete="off" placeholder="Klíč z administrace PPL"></label>
  <p class="panel-help">Klíč vytvoř a aktivuj v <a href="https://klient.ppl.cz/widgetadmin" target="_blank" rel="noopener noreferrer">administraci widgetu PPL</a> a povol doménu obchodu. Pro místní zkoušení musí být povolená i doména localhost, pokud ji PPL přijme. Bez klíče zůstane dosavadní ruční vyplnění místa PPL. Klíč je veřejný a načítá se na stránce pokladny.</p>
  <h3>Podávání zásilek</h3>
  <label>Označení odesílatele (hodnota pro API pole eshop)<input name="packeta_sender" value="<?= $escape($form['packeta_sender'] ?? '') ?>" maxlength="64" autocomplete="off" placeholder="Zkopíruj přesné Označení, ne název firmy"></label>
  <p class="panel-help">V <a href="https://client.packeta.com/senders/" target="_blank" rel="noopener noreferrer">klientské sekci Zásilkovny → Odesílatelé</a> zkopíruj hodnotu <strong>Označení</strong> ze čtvrtého sloupce u svého odesílatele. Samotný název firmy nebo e-shopu nemusí být stejný. Pokud odesílatel chybí, nejdřív ho tam vytvoř a ulož; účet musí být schválený.</p>
  <label>API heslo Zásilkovny<input type="password" name="packeta_api_password" value="" maxlength="128" autocomplete="new-password" placeholder="<?= !empty($packetaPasswordConfigured) ? 'Heslo je uloženo; pro změnu zadej nové' : 'Zadej soukromé API heslo' ?>"></label>
  <p class="panel-help">API heslo je jiné než veřejný klíč widgetu. Prázdné pole ponechá uložené heslo beze změny. Heslo se na veřejných stránkách ani v administraci znovu nevypisuje.</p>
  <?php if (!empty($packetaPasswordConfigured)): ?><label class="panel-check"><input type="checkbox" name="packeta_clear_password" value="1"> Odstranit uložené API heslo</label><?php endif; ?>
  </section>
<?php endif; ?>
<?php if ($settingsTab === 'payment'): ?>
  <nav class="panel-settings-nav" aria-label="Způsoby platby">
    <?php foreach (['bank' => 'Bankovní převod', 'fio' => 'Fio ověřování',
        'comgate' => 'Comgate', 'gopay' => 'GoPay', 'btcpay' => 'BTCPay'] as $areaCode => $areaLabel): ?>
      <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=payment&area=' . $areaCode) ?>" <?= $paymentArea === $areaCode ? 'aria-current="page"' : '' ?>><?= $escape($areaLabel) ?></a>
    <?php endforeach; ?>
  </nav>
  <section class="panel-panel panel-settings-block" id="settings-payment">
  <h2>Platby</h2><p class="panel-help">Každou platební část ulož samostatně. Rozpracované údaje jiné brány její uložení neovlivní.</p>
  <?php if ($paymentArea === 'bank'): ?>
  <form class="panel-form panel-settings-form" method="post" novalidate action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=payment#settings-bank') ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save-checkout-settings"><input type="hidden" name="tab" value="payment"><input type="hidden" name="payment_area" value="bank">
  <h3 id="settings-bank">Bankovní převod</h3>
  <?php if ($settingsError !== '' && $paymentArea === 'bank'): ?><p class="panel-error" role="alert"><?= $escape($settingsError) ?></p><?php endif; ?>
  <label class="panel-check"><input type="checkbox" name="bank_transfer_enabled" value="1" <?= $form['bank_transfer_enabled'] === '1' ? 'checked' : '' ?>> Nabízet bankovní převod v pokladně</label>
  <p class="panel-help">Údaje účtu zůstanou uložené i po vypnutí. Stávající objednávky uchovávají účet použitý při jejich vytvoření. Lze nabízet jen BTCPay nebo jinou bránu; při vypnutí všech metod nebude možné dokončit objednávku.</p>
  <label>Účet pro převod a QR platbu<select name="bank_source"><option value="manual" <?= $form['bank_source'] === 'manual' ? 'selected' : '' ?>>Jiná banka / účet bez napojení na API</option><option value="fio" <?= $form['bank_source'] === 'fio' ? 'selected' : '' ?>>Účet Fio banky (/2010)</option></select></label>
  <label>Číslo účtu, na který má zákazník platit<input name="account_display" value="<?= $escape($form['account_display']) ?>" placeholder="číslo/kód banky" autocomplete="off"></label>
  <p class="panel-help">QR kód na potvrzení objednávky i na faktuře se sestaví z tohoto čísla účtu, částky a variabilního symbolu. Funguje pro kteroukoli českou banku bez API. IBAN se z čísla účtu dopočítá automaticky; není třeba ho zadávat. Fio API níže slouží pouze k automatickému ověřování přijatých plateb.</p>
  <label>Jméno příjemce<input name="recipient" value="<?= $escape($form['recipient']) ?>" maxlength="120" autocomplete="off"></label>
  <label>Splatnost v dnech<input type="number" name="payment_due_days" value="<?= $escape($form['payment_due_days']) ?>" min="1" max="60" required></label>
  <div class="panel-settings-save"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit bankovní převod</button></div></form>
  <?php endif; ?>
  <?php if ($paymentArea === 'fio'): ?>
  <form class="panel-form panel-settings-form" method="post" novalidate action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=payment#settings-fio') ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save-checkout-settings"><input type="hidden" name="tab" value="payment"><input type="hidden" name="payment_area" value="fio">
  <details class="panel-settings-details" id="settings-fio"<?= $paymentArea === 'fio' ? ' open' : '' ?>><summary>Fio banka – ověřování bankovních převodů</summary><div class="panel-settings-fields">
  <?php if ($settingsError !== '' && $paymentArea === 'fio'): ?><p class="panel-error" role="alert"><?= $escape($settingsError) ?></p><?php endif; ?>
  <label class="panel-check"><input type="checkbox" name="fio_enabled" value="1" <?= $form['fio_enabled'] === '1' ? 'checked' : '' ?>> Ověřovat přijaté převody přes Fio API</label>
  <label>Účet, ke kterému patří token<input name="fio_account_display" value="<?= $escape($form['fio_account_display']) ?>" maxlength="22" autocomplete="off" placeholder="číslo/2010"></label>
  <label>Token Fio pouze pro sledování účtu<input type="password" name="fio_token" value="<?= $escape($fioDraftToken ?? '') ?>" maxlength="512" autocomplete="new-password" placeholder="<?= !empty($fioTokenConfigured) ? 'Token je uložen; pro změnu zadej nový' : 'Vlož token vytvořený ve Fio internetbankingu' ?>"></label>
  <?php if (!empty($fioTokenConfigured)): ?><label class="panel-check"><input type="checkbox" name="fio_clear_token" value="1" <?= $settingsError !== '' && $paymentArea === 'fio' && ($_POST['fio_clear_token'] ?? null) === '1' ? 'checked' : '' ?>> Odstranit uložený token</label><?php endif; ?>
  <p class="panel-help">Fio kontroluje pouze převody na tento účet. Pro automatické potvrzování nových plateb musí být stejný jako účet pro převod a QR platbu výše. Při platbě na jinou banku zůstává QR kód funkční, přijetí platby ale potvrď ručně. Token se zde nikdy nevypisuje zpět.</p>
  <p class="panel-help">Token má přesně 64 znaků. V internetbankingu Fio otevři Nastavení → API, dokonči jeho autorizaci a po schválení počkej 5 minut. Stačí právo „Sledování účtu“. Používej pro obchod vlastní token: další aplikace se stejným tokenem může vyvolat limit HTTP 409. Pokud Fio vrátí HTTP 500, ověř jeho aktivní stav a platnost. <a href="https://www.fio.cz/docs/cz/API_Bankovnictvi.pdf" target="_blank" rel="noopener noreferrer">Dokumentace Fio ↗</a></p>
  <p class="panel-help">Automaticky se páruje jedna příchozí platba v Kč přesně ve výši objednávky za posledních 89 dní. Rozdělené platby, přeplatky, vratky a storna zkontroluj ve výpisu ručně.</p>
  <?php if (!empty($fioAccountDifferent)): ?><p class="panel-help">Účet Fio se liší od účtu nabízeného zákazníkům. Kontrola Fio proto nebude potvrzovat nové převody na uvedený bankovní účet. Při zkoušení můžeš stále párovat starší objednávky vytvořené pro tento účet Fio.</p><?php endif; ?>
  <div class="panel-settings-save"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit Fio</button></div>
  </div></details></form>
  <?php endif; ?>
  <?php if ($paymentArea === 'comgate'): ?>
  <form class="panel-form panel-settings-form" method="post" novalidate action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=payment#settings-comgate') ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save-checkout-settings"><input type="hidden" name="tab" value="payment"><input type="hidden" name="payment_area" value="comgate">
  <details class="panel-settings-details" id="settings-comgate"<?= $paymentArea === 'comgate' ? ' open' : '' ?>><summary>Comgate – online platba</summary><div class="panel-settings-fields">
  <?php if ($settingsError !== '' && $paymentArea === 'comgate'): ?><p class="panel-error" role="alert"><?= $escape($settingsError) ?></p><?php endif; ?>
  <label class="panel-check"><input type="checkbox" name="comgate_enabled" value="1" <?= $form['comgate_enabled'] === '1' ? 'checked' : '' ?>> Nabízet platbu přes Comgate (po vyplnění přístupových údajů)</label>
  <label class="panel-check"><input type="checkbox" name="comgate_test" value="1" <?= $form['comgate_test'] === '1' ? 'checked' : '' ?>> Testovací režim Comgate</label>
  <p class="panel-help">Testovací režim vyžaduje vlastní identifikátor obchodníka a tajný klíč Comgate. Vypni jej až po ověření testovací platby a schválení produkčního provozu.</p>
  <label>Identifikátor obchodníka (merchant)<input name="comgate_merchant" value="<?= $escape($form['comgate_merchant']) ?>" maxlength="100" autocomplete="off" placeholder="Merchant ID z klientského portálu"></label>
  <label>Veřejná HTTPS adresa obchodu<input type="url" name="comgate_return_base_url" value="<?= $escape($form['comgate_return_base_url']) ?>" maxlength="1000" autocomplete="off" placeholder="<?= $escape('https://obchod.cz' . rtrim($basePath, '/')) ?>"></label>
  <p class="panel-help">Vlož kořenovou adresu této instalace bez posledního lomítka. Comgate po platbě zákazníka vrací na adresy <code>url_paid</code>, <code>url_pending</code> a <code>url_cancelled</code>, které obchod pro každou platbu vygeneruje automaticky z této adresy. Adresa musí být přístupná z internetu přes HTTPS, také během testování.</p>
  <label>Tajný klíč API (secret)<input type="password" name="comgate_secret" value="<?= $escape($paymentDraftSecrets['comgate_secret'] ?? '') ?>" maxlength="256" autocomplete="new-password" placeholder="<?= $comgateSecretConfigured ? 'Klíč je uložen; pro změnu zadej nový' : 'Tajný klíč z klientského portálu' ?>"></label>
  <p class="panel-help">Prázdné pole ponechá uložený klíč beze změny. Klíč se znovu nezobrazuje ani se neposílá zákazníkovi. V Comgate nastav adresu pro PUSH oznámení na <code><?= $escape(($form['comgate_return_base_url'] !== '' ? rtrim($form['comgate_return_base_url'], '/') : 'https://obchod.cz' . rtrim($basePath, '/')) . '/comgate-callback.php') ?></code>. Návratové adresy pro zaplacenou, čekající a zrušenou platbu se předají API automaticky. Pro místní test přijímající oznámení použij veřejnou testovací doménu; Comgate se na localhost nedostane.</p>
  <?php if ($comgateSecretConfigured): ?><label class="panel-check"><input type="checkbox" name="comgate_clear_secret" value="1" <?= $settingsError !== '' && $paymentArea === 'comgate' && ($_POST['comgate_clear_secret'] ?? null) === '1' ? 'checked' : '' ?>> Odstranit uložený tajný klíč</label><?php endif; ?>
  <div class="panel-settings-save"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit Comgate</button></div>
  </div></details></form>
  <?php endif; ?>
  <?php if ($paymentArea === 'gopay'): ?>
  <form class="panel-form panel-settings-form" method="post" novalidate action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=payment#settings-gopay') ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save-checkout-settings"><input type="hidden" name="tab" value="payment"><input type="hidden" name="payment_area" value="gopay">
  <details class="panel-settings-details" id="settings-gopay"<?= $paymentArea === 'gopay' ? ' open' : '' ?>><summary>GoPay – online platba</summary><div class="panel-settings-fields">
  <?php if ($settingsError !== '' && $paymentArea === 'gopay'): ?><p class="panel-error" role="alert"><?= $escape($settingsError) ?></p><?php endif; ?>
  <label class="panel-check"><input type="checkbox" name="gopay_enabled" value="1" <?= $form['gopay_enabled'] === '1' ? 'checked' : '' ?>> Nabízet platbu přes GoPay (po vyplnění přístupových údajů)</label>
  <label class="panel-check"><input type="checkbox" name="gopay_test" value="1" <?= $form['gopay_test'] === '1' ? 'checked' : '' ?>> Testovací prostředí GoPay</label>
  <p class="panel-help">Testovací prostředí vyžaduje testovací GoID, Client ID a Client secret přidělené GoPay. Před ostrým provozem ověř testovací objednávku a potom vyplň produkční údaje a vypni testovací režim.</p>
  <label>GoID obchodníka<input name="gopay_goid" value="<?= $escape($form['gopay_goid']) ?>" maxlength="20" inputmode="numeric" pattern="[0-9]+" autocomplete="off" placeholder="Číselné GoID od GoPay"></label>
  <label>Client ID<input name="gopay_client_id" value="<?= $escape($form['gopay_client_id']) ?>" maxlength="256" autocomplete="off" placeholder="Client ID od GoPay"></label>
  <label>Veřejná HTTPS adresa obchodu<input type="url" name="gopay_return_base_url" value="<?= $escape($form['gopay_return_base_url']) ?>" maxlength="1000" autocomplete="off" placeholder="<?= $escape('https://obchod.cz' . rtrim($basePath, '/')) ?>"></label>
  <p class="panel-help">Vlož kořenovou adresu této instalace bez posledního lomítka. Tuto adresu musí být možné otevřít z internetu přes HTTPS, také při testování.</p>
  <label>Client secret<input type="password" name="gopay_client_secret" value="<?= $escape($paymentDraftSecrets['gopay_client_secret'] ?? '') ?>" maxlength="256" autocomplete="new-password" placeholder="<?= $gopaySecretConfigured ? 'Klíč je uložen; pro změnu zadej nový' : 'Client secret od GoPay' ?>"></label>
  <p class="panel-help">Prázdné pole ponechá uložený klíč beze změny. Tajný klíč se zde znovu nevypisuje. GoPay zavolá URL oznámení <code><?= $escape(($form['gopay_return_base_url'] !== '' ? rtrim($form['gopay_return_base_url'], '/') : 'https://obchod.cz' . rtrim($basePath, '/')) . '/gopay-callback.php') ?></code> a zákazníka vrátí přes <code>gopay-return.php</code>. Místní localhost GoPay nemůže zavolat.</p>
  <?php if ($gopaySecretConfigured): ?><label class="panel-check"><input type="checkbox" name="gopay_clear_secret" value="1" <?= $settingsError !== '' && $paymentArea === 'gopay' && ($_POST['gopay_clear_secret'] ?? null) === '1' ? 'checked' : '' ?>> Odstranit uložený Client secret</label><?php endif; ?>
  <div class="panel-settings-save"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit GoPay</button></div>
  </div></details></form>
  <?php endif; ?>
  <?php if ($paymentArea === 'btcpay'): ?>
  <form class="panel-form panel-settings-form" method="post" novalidate action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=payment#settings-btcpay') ?>">
  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save-checkout-settings"><input type="hidden" name="tab" value="payment"><input type="hidden" name="payment_area" value="btcpay">
  <details class="panel-settings-details" id="settings-btcpay"<?= $paymentArea === 'btcpay' ? ' open' : '' ?>><summary>BTCPay Server – platba bitcoinem</summary><div class="panel-settings-fields">
  <?php if ($settingsError !== '' && $paymentArea === 'btcpay'): ?><p class="panel-error" role="alert"><?= $escape($settingsError) ?></p><?php endif; ?>
  <label class="panel-check"><input type="checkbox" name="btcpay_enabled" value="1" <?= $form['btcpay_enabled'] === '1' ? 'checked' : '' ?>> Nabízet platbu bitcoinem přes BTCPay Server</label>
  <p class="panel-help">V BTCPay vytvoř obchod s nastavenou peněženkou. API klíči uděl jen oprávnění <code>btcpay.store.cancreateinvoice</code> a <code>btcpay.store.canviewinvoices</code> pro tento obchod. Platební metoda se nabídne až po úplném nastavení.</p>
  <label>Adresa instance BTCPay<input type="url" name="btcpay_server_url" value="<?= $escape($form['btcpay_server_url']) ?>" maxlength="1000" autocomplete="off" placeholder="https://platby.obchod.cz"></label>
  <label>ID obchodu (Store ID)<input name="btcpay_store_id" value="<?= $escape($form['btcpay_store_id']) ?>" maxlength="128" autocomplete="off" placeholder="ID obchodu v BTCPay"></label>
  <label>Adresa tohoto obchodu<input type="url" name="btcpay_return_base_url" value="<?= $escape($form['btcpay_return_base_url']) ?>" maxlength="1000" autocomplete="off" placeholder="<?= $escape('https://obchod.cz' . rtrim($basePath, '/')) ?>"></label>
  <p class="panel-help">Běžný provoz vyžaduje veřejné HTTPS. Při testu obou aplikací na stejném počítači můžeš pro obě adresy použít <code>http://localhost</code> (včetně instalačních podsložek). V BTCPay Lite musíš zapnout <code>allow_local_webhooks</code>; bez toho webhook na localhost nebude doručen.</p>
  <label>API klíč BTCPay<input type="password" name="btcpay_api_key" value="<?= $escape($paymentDraftSecrets['btcpay_api_key'] ?? '') ?>" maxlength="512" autocomplete="new-password" placeholder="<?= $btcpayApiKeyConfigured ? 'Klíč je uložen; pro změnu zadej nový' : 'API klíč pro tento BTCPay obchod' ?>"></label>
  <?php if ($btcpayApiKeyConfigured): ?><label class="panel-check"><input type="checkbox" name="btcpay_clear_api_key" value="1" <?= $settingsError !== '' && $paymentArea === 'btcpay' && ($_POST['btcpay_clear_api_key'] ?? null) === '1' ? 'checked' : '' ?>> Odstranit uložený API klíč</label><?php endif; ?>
  <label>Tajný klíč webhooku<input type="password" name="btcpay_webhook_secret" value="<?= $escape($paymentDraftSecrets['btcpay_webhook_secret'] ?? '') ?>" maxlength="512" autocomplete="new-password" placeholder="<?= $btcpayWebhookSecretConfigured ? 'Klíč je uložen; pro změnu zadej nový' : 'Secret z nastavení webhooku v BTCPay' ?>"></label>
  <?php if ($btcpayWebhookSecretConfigured): ?><label class="panel-check"><input type="checkbox" name="btcpay_clear_webhook_secret" value="1" <?= $settingsError !== '' && $paymentArea === 'btcpay' && ($_POST['btcpay_clear_webhook_secret'] ?? null) === '1' ? 'checked' : '' ?>> Odstranit uložený tajný klíč webhooku</label><?php endif; ?>
  <p class="panel-help">V BTCPay v nastavení tohoto obchodu založ webhook pro události faktur. Jeho URL nastav na <code><?= $escape(($form['btcpay_return_base_url'] !== '' ? rtrim($form['btcpay_return_base_url'], '/') : 'https://obchod.cz' . rtrim($basePath, '/')) . '/btcpay-callback.php') ?></code>. Secret webhooku zkopíruj sem. Prázdná pole s klíči ponechají uložené hodnoty beze změny; uložené klíče se znovu nevypisují. Pokud uložení selže, právě zadané klíče zůstanou pouze v tomto formuláři.</p>
  <div class="panel-settings-save"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit BTCPay</button></div>
  </div></details></form>
  <?php endif; ?>
  </section>
<?php endif; ?>
<?php if ($settingsTab === 'legal'): ?>
  <section class="panel-panel panel-settings-block" id="settings-legal">
  <h2>Obchodní podmínky</h2>
  <label>Adresa publikované stránky<input name="terms_url" value="<?= $escape($form['terms_url']) ?>" placeholder="<?= $escape($basePath . 'cs/obchodni-podminky') ?>"></label>
  <p class="panel-help">Můžeš je doplnit později. Až stránku vytvoříš a publikuješ, vlož sem její cestu začínající <?= $escape($basePath) ?>.</p>
  </section>
<?php endif; ?>
<?php if ($settingsTab !== 'payment'): ?>
  <div class="panel-settings-save"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit tuto oblast</button></div>
</form>
<?php endif; ?>
<?php if ($settingsTab === 'payment' && $paymentArea === 'fio' && !empty($settings['fio_bank']['enabled'])): ?>
  <section class="panel-panel panel-settings-block">
    <h2>Pravidelné ověřování Fio na Webglobe</h2>
    <?php if (($fioCronKey ?? '') !== ''): ?>
      <p class="panel-notice" role="status">Nový klíč je uložen. Zkopíruj adresu pro Webglobe Cron teď; po obnovení stránky ji už nezobrazíme.</p>
      <p><code><?= $escape(rtrim($basePath, '/') . '/fio-cron.php?key=' . $fioCronKey) ?></code></p>
    <?php endif; ?>
    <p class="panel-help">V administraci Webglobe vyber hosting této domény → Web → Cron a vlož celou HTTPS adresu včetně klíče; nastav interval 10 minut. Webglobe před uložením kontroluje adresu metodou HEAD. Pokud hlásí, že skript neexistuje, ověř, že je po nasazení soubor fio-cron.php v kořeni webu vedle index.php. Klíč obsahuje jen oprávnění spustit kontrolu; bankovní token v adrese není. Pokud se adresa dostane k někomu dalšímu, vytvoř nový klíč a v plánu vyměň adresu.</p>
    <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=payment&area=fio') ?>">
      <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="fio-cron-rotate"><input type="hidden" name="tab" value="payment">
      <button class="panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('lock') ?> <?= !empty($settings['fio_bank']['cron_key_hash']) ? 'Vyměnit klíč pro Cron' : 'Vytvořit klíč pro Cron' ?></button>
    </form>
  </section>
<?php endif; ?>
<?php endif; ?>
