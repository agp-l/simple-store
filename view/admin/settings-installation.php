<?php
declare(strict_types=1);
$installation = \SimpleStore\Configuration\Installation::current();
$installationPathMatches = $installation->basePath() === $basePath;
?>
<section class="panel-panel panel-settings-block panel-form">
  <h2><?= \SimpleStore\Rendering\Icon::render('globe') ?> Jedna adresa pro celý obchod</h2>
  <p>Veřejná adresa, e-maily, přihlášení Google, platební návraty, sitemap a automatické kontroly partnerů používají <code>config/installation.json</code>. Aktualizace webu tento místní soubor nepřepisují.</p>
  <label>Aktuální veřejná adresa<input readonly value="<?= $escape($installation->baseUrl()) ?>"></label>
  <p class="<?= $installationPathMatches ? 'panel-notice' : 'panel-error' ?>" role="status">
    <?= $installationPathMatches ? '✓ Podsložka v konfiguraci odpovídá této instalaci.' : 'Podsložka nesouhlasí. Zkontroluj public_base_url a umístění webu; sitemap a absolutní SEO odkazy se při neshodě nevydávají.' ?>
    Konfigurace: <code><?= $escape($installation->basePath()) ?></code> · Web: <code><?= $escape($basePath) ?></code>
  </p>
  <p class="panel-help">Shoda podsložky není ověřením DNS, certifikátu ani nastavení externích služeb. Tato stránka web nepřesouvá.</p>
</section>
<section class="panel-panel panel-settings-block panel-form">
  <h2>Přesun na jinou doménu nebo do podsložky</h2>
  <ol>
    <li>Zálohuj soubory i databázi. Připrav cílovou doménu, HTTPS a správnou kořenovou složku hostingu. Pokud je tam jiný web, nejdřív mu připrav jiné umístění.</li>
    <li>Přenes celou instalaci včetně <code>.htaccess</code>, všech místních souborů v <code>config/</code> (také <code>.smtp-key.php</code>), knihoven a <code>images/media/</code>. Při přejmenování složky je ponech uvnitř. Připojení ke stejné databázi se nemění.</li>
    <li>V <code>config/installation.json</code> změň pouze <code>public_base_url</code>. Například <code>https://dobrodruzi.cz</code>, <code>https://obchod.dobrodruzi.cz</code> nebo <code>https://dobrodruzi.cz/eshop</code>. Bez jazykové koncovky <code>/cs</code>. Dosavadní adresy ponech v <code>previous_base_urls</code>.</li>
    <li>U aktivních služeb uprav adresy níže, povolené domény dopravců a případné úlohy Cron hostingu. Ověř, že FTP účet pro nasazení stále míří do kořene této aplikace; jinak uprav jeho cílovou složku u hostingu.</li>
    <li>Ověř přihlášení, obrázky, košík, testovací platbu, e-maily a kontrolu partnerů. Staré veřejné odkazy přesměruj na odpovídající nové stránky. Rozpracované platby potřebují zachovat doručování oznámení na původní adresy.</li>
  </ol>
  <p>Staré interní absolutní odkazy uvedené v <code>previous_base_urls</code> se při zobrazení převedou na aktuální adresu. Uložené objednávky a jejich právní přílohy zůstanou zachované. Odkazy na partnery ani samostatný web expedic se nepřepisují.</p>
  <p class="panel-help">V podsložce nesmí hlavní web přebírat požadavky pro tuto složku. Pravidla pro roboty patří do <code>/robots.txt</code> v kořeni celé domény; spoj je s pravidly druhého webu. Přihlášení a košík ze staré domény se do nové automaticky nepřenášejí.</p>
</section>
<section class="panel-panel panel-settings-block panel-form">
  <h2>Adresy odvozené z konfigurace</h2>
  <p>Po změně domény zde zkopíruješ aktuální adresy do služeb, které používáš. API klíče zůstávají uložené.</p>
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
