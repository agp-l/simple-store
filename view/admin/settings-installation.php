<?php
declare(strict_types=1);
$installation = \SimpleStore\Configuration\Installation::current();
$installationPathMatches = $installation->basePath() === $basePath;
?>
<section class="panel-panel panel-settings-block panel-form">
  <h2><?= \SimpleStore\Rendering\Icon::render('globe') ?> Jedna adresa pro celý obchod</h2>
  <p>Veřejná adresa, e-maily, přihlášení Google, platební návraty, sitemap a automatické kontroly partnerů používají <code>config/installation.json</code>. Aktualizace webu tento místní soubor nepřepisují.</p>
  <p><strong>Aktivní veřejná adresa:</strong> <code><?= $escape($installation->baseUrl()) ?></code></p>
  <p class="<?= $installationPathMatches ? 'panel-notice' : 'panel-error' ?>" role="status">
    <?= $installationPathMatches ? '✓ Podsložka v konfiguraci odpovídá této instalaci.' : 'Podsložka nesouhlasí. Zkontroluj public_base_url a umístění webu; sitemap a absolutní SEO odkazy se při neshodě nevydávají.' ?>
    Konfigurace: <code><?= $escape($installation->basePath()) ?></code> · Web: <code><?= $escape($basePath) ?></code>
  </p>
  <p class="panel-help">Shoda podsložky není ověřením DNS, certifikátu ani nastavení externích služeb. Tato stránka web nepřesouvá.</p>
</section>
<section class="panel-panel panel-settings-block panel-form">
  <h2>Adresy odvozené z konfigurace</h2>
  <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=settings&tab=installation') ?>">
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <input type="hidden" name="action" value="save-installation-address">
    <input type="hidden" name="tab" value="installation">
    <input type="hidden" name="installation_revision" value="<?= $escape($installationRevision ?? \SimpleStore\Configuration\InstallationSettings::revision($installation)) ?>">
    <label>Hlavní adresa obchodu<input type="url" name="public_base_url" value="<?= $escape($installationFormAddress ?? $installation->baseUrl()) ?>" maxlength="1000" required autocomplete="url" spellcheck="false" aria-describedby="installation-address-help"></label>
    <p class="panel-help" id="installation-address-help">Po uložení se všechny adresy níže přepočítají najednou. Zadej například <code>https://dobrodruzi.cz</code> nebo <code>https://dobrodruzi.cz/eshop</code>, bez <code>/cs</code>. Novou adresu ulož až při skutečném přesunu: začne se používat v celém obchodě ihned po uložení.</p>
    <div class="panel-settings-save"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit adresu pro celý obchod</button></div>
  </form>
  <h3>Automaticky vypočítané adresy</h3>
  <p>Tyto adresy se nastavují společně polem výše, jednotlivě se neupravují. Po uložení je můžeš zkopírovat do nastavení Googlu, platební brány nebo Cronu u jejich poskytovatele. API klíče zůstávají uložené.</p>
  <?php foreach ([
      'Google – Authorized redirect URI' => 'account/?google=callback',
      'Comgate – PUSH oznámení' => 'comgate-callback.php',
      'GoPay – oznámení (předává se při založení platby)' => 'gopay-callback.php',
      'BTCPay – webhook' => 'btcpay-callback.php',
      'Mapa webu pro vyhledávače' => 'sitemap.xml',
      'Kontrola partnerů – adresu si plánovač načte sám' => 'supplier-price-cron.php',
      'E-mailová fronta – případný Cron hostingu' => 'mail-cron.php',
      'Fio – případný Cron hostingu' => 'fio-cron.php',
  ] as $label => $route): ?>
    <label><?= $escape($label) ?><input readonly value="<?= $escape($installation->url($route)) ?>"></label>
  <?php endforeach; ?>
  <p class="panel-help">Adresy Cronu jsou bez přístupových klíčů. Zachovej stávající způsob autorizace a klíče nezveřejňuj.</p>
</section>
<section class="panel-panel panel-settings-block panel-form">
  <h2>Přesun na jinou doménu nebo do podsložky</h2>
  <ol>
    <li>Zálohuj soubory i databázi. Připrav cílovou doménu, HTTPS a správnou kořenovou složku hostingu. Pokud je tam jiný web, nejdřív mu připrav jiné umístění.</li>
    <li>Přenes celou instalaci včetně <code>.htaccess</code>, všech místních souborů v <code>config/</code> (také <code>.smtp-key.php</code>), knihoven a <code>images/media/</code>. Při přejmenování složky je ponech uvnitř. Připojení ke stejné databázi se nemění.</li>
    <li>V poli <strong>Hlavní adresa obchodu</strong> ulož novou adresu, nebo v <code>config/installation.json</code> změň <code>public_base_url</code>. Například <code>https://dobrodruzi.cz</code>, <code>https://obchod.dobrodruzi.cz</code> nebo <code>https://dobrodruzi.cz/eshop</code>. Bez jazykové koncovky <code>/cs</code>. Při uložení přes administraci se původní adresa doplní do historie automaticky. Při ruční úpravě souboru ji ponech v <code>previous_base_urls</code>.</li>
    <li>U aktivních služeb uprav adresy níže, povolené domény dopravců a případné úlohy Cron hostingu. Ověř, že FTP účet pro nasazení stále míří do kořene této aplikace; jinak uprav jeho cílovou složku u hostingu.</li>
    <li>Ověř přihlášení, obrázky, košík, testovací platbu, e-maily a kontrolu partnerů. Staré veřejné odkazy přesměruj na odpovídající nové stránky. Rozpracované platby potřebují zachovat doručování oznámení na původní adresy.</li>
  </ol>
  <p>Staré interní absolutní odkazy uvedené v <code>previous_base_urls</code> se při zobrazení převedou na aktuální adresu. Uložené objednávky a jejich právní přílohy zůstanou zachované. Odkazy na partnery ani samostatný web expedic se nepřepisují.</p>
  <p class="panel-help">V podsložce nesmí hlavní web přebírat požadavky pro tuto složku. Pravidla pro roboty patří do <code>/robots.txt</code> v kořeni celé domény; spoj je s pravidly druhého webu. Přihlášení a košík ze staré domény se do nové automaticky nepřenášejí.</p>
</section>
