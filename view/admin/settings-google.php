<?php declare(strict_types=1); ?>
<section class="panel-panel"><h2>Přihlášení zákazníků přes Google</h2>
<p>Zákazník se může zaregistrovat, přihlásit a ve svém účtu Google připojit nebo odpojit. Přihlášení heslem zůstává dostupné. Tato volba se netýká administrátorů.</p>
<?php if (!$googleSettingsReady): ?><p class="panel-error">Nejdřív aktualizuj tabulky v sekci Databáze.</p><?php else: ?>
<form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=settings&tab=accounts') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save-google-login"><input type="hidden" name="tab" value="accounts">
<fieldset><legend>Zapnutí přihlášení Google</legend>
<label class="panel-check" for="google-login-enabled"><input id="google-login-enabled" type="checkbox" name="enabled" value="1" <?= $googleSettings['enabled']?'checked':'' ?> aria-describedby="google-enabled-help"><strong>Zapnout přihlášení přes Google</strong></label>
<p id="google-enabled-help">Zaškrtni a ulož, aby zákazníci viděli tlačítko Google. Přepínač umožňuje přihlášení dočasně vypnout bez smazání přístupových údajů.</p>
<p><strong>Uložený stav: <?= $googleSettings['enabled'] ? 'Zapnuto' : 'Vypnuto' ?></strong></p>
</fieldset>
<label>Client ID<input name="client_id" maxlength="200" value="<?= $escape($googleSettings['client_id']) ?>" autocomplete="off"></label>
<label for="google-client-secret">Client secret</label>
<input id="google-client-secret" type="text" name="client_secret" maxlength="512" autocomplete="off" spellcheck="false" autocapitalize="none" value="<?= $escape($googleClientSecret ?? '') ?>">
<button class="panel-button" type="button" data-google-secret-toggle aria-controls="google-client-secret" aria-pressed="false" hidden>Skrýt klíč</button>
<p><?= $googleSettings['client_secret_encrypted']!==''?'Tajný klíč je uložený. Prázdné pole jej zachová.':'Tajný klíč zatím není uložený.' ?> Uložený klíč se načte do pole. V databázi zůstává šifrovaný a neexportuje se v zálohách textů.</p>
<label>Autorizovaná návratová adresa<input type="url" readonly maxlength="1000" value="<?= $escape($googleSettings['redirect_uri']) ?>"></label>
<p class="panel-help">Adresa vzniká ze společné konfigurace webu. Po přesunu ji také aktualizuj v Google Cloud v Authorized redirect URIs.</p>
<button class="panel-button" type="submit">Uložit přihlášení Google</button></form>
<?php endif; ?>
<h3>Jednorázové nastavení v Google Cloud — krok za krokem</h3>
<p>Nastavení provedeš nejprve u Googlu a potom v tomto formuláři. Názvy tlačítek níže odpovídají anglické konzoli.</p>
<ol>
<li><strong>Vytvoř nebo vyber projekt.</strong> Otevři <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">Google Cloud Console</a>. Nahoře klikni na výběr projektu → <strong>New project</strong>, zadej například „Dobrodruzi e-shop“ a projekt vyber. Pokud už klienta máš, použij jeho stávající projekt.</li>
<li><strong>Nastav aplikaci.</strong> Vyhledej <strong>Google Auth Platform</strong> a případně klikni na <strong>Get started</strong>. Vyplň název aplikace, <strong>User support email</strong> a kontaktní e-mail. Publikum zvol <strong>External</strong>, aby se mohli přihlašovat zákazníci mimo tvoji organizaci.</li>
<li><strong>Doplň Branding.</strong> Pro dobrodruzi.cz vyplň:
<ul><li><strong>App name:</strong> Dobrodruzi.cz</li>
<li><strong>Application home page:</strong> <code>https://dobrodruzi.cz/</code></li>
<li><strong>Application privacy policy link:</strong> <code>https://dobrodruzi.cz/cs/ochrana-osobnich-udaju</code></li>
<li><strong>Application terms of service link:</strong> <code>https://dobrodruzi.cz/cs/obchodni-podminky</code></li>
<li><strong>Authorized domains:</strong> <code>dobrodruzi.cz</code> — bez HTTPS a lomítka.</li></ul>
Na jiné doméně použij skutečné adresy svého obchodu. Klikni na <strong>Save</strong>. Pokud Google vyžádá ověření domény, dokonči jej podle pokynů konzole.</li>
<li><strong>Vytvoř klienta.</strong> Otevři <strong>Clients → Create client</strong>. Vyber <strong>Web application</strong> a název například „Dobrodruzi web“. <strong>Authorized JavaScript origins</strong> může zůstat prázdné. Do <strong>Authorized redirect URIs → Add URI</strong> vlož přesně hodnotu pole „Autorizovaná návratová adresa“ výše. Pro hlavní doménu je to <code>https://dobrodruzi.cz/account/?google=callback</code>. Zachovej lomítko před otazníkem, nepřidávej <code>/cs</code> ani <code>www</code>. Volbu <strong>Use this client for an AI-powered agent</strong> nech vypnutou. Klikni na <strong>Create</strong> nebo <strong>Save</strong>.</li>
<li><strong>Přenes údaje do e-shopu.</strong> Zkopíruj celý <strong>Client ID</strong> a <strong>Client secret</strong> do polí výše. Google ukáže nový secret při vytvoření; bezpečně si jej ulož. V e-shopu výslovně zaškrtni <strong>Zapnout přihlášení přes Google</strong> a klikni na <strong>Uložit přihlášení Google</strong>. Samotné nastavení v Google Cloud tlačítko v e-shopu nezapne.</li>
<li><strong>Vyber základní údaje.</strong> Vlevo otevři <strong>Data Access → Add or remove scopes</strong>. Vyber <code>openid</code>, <code>https://www.googleapis.com/auth/userinfo.email</code> a <code>https://www.googleapis.com/auth/userinfo.profile</code>. Pokud nejsou v seznamu, vlož je do <strong>Manually add scopes</strong>. Potvrď <strong>Update</strong> a případně <strong>Save</strong>. Tyto údaje stačí pro totožnost, e-mail a základní profil; Gmail ani Disk nepřidávej.</li>
<li><strong>Zapni veřejný provoz.</strong> Otevři <strong>Audience</strong>. <strong>User type</strong> má být <strong>External</strong>. Pokud je pod <strong>Publishing status</strong> stav <strong>Testing</strong>, klikni na <strong>Publish app</strong> a potvrď. Pokud už vidíš <strong>In production</strong>, je tento krok hotový. Pro samotné <code>openid email profile</code> Google připouští přihlášení i bez seznamu testovacích uživatelů v režimu Testing.</li>
<li><strong>Zkontroluj značku.</strong> Pokud v <strong>Branding</strong> vidíš „Your branding is not being shown to users“, klikni na <strong>View issues</strong>. Oprav konkrétní uvedené nedostatky a požádej o ověření znovu. Produkční stav a ověření názvu či loga jsou samostatné kroky. Tato zpráva sama neskrývá tlačítko v e-shopu.</li>
<li><strong>Vyzkoušej přihlášení.</strong> V anonymním okně otevři zákaznický účet <code>/account/</code>, klikni na přihlášení přes Google a vyber účet. Návratovou adresu s <code>?google=callback</code> neotvírej ručně: čeká na odpověď Googlu. Pro první registraci použij e-mail, který ještě v e-shopu nemá účet. Existující účet nejprve přihlas heslem a Google připoj v nastavení účtu. Odpojení vyzkoušej až po nastavení hesla.</li>
</ol>
<h3>Když se přihlášení nezobrazuje nebo nefunguje</h3>
<ul><li><strong>Chybí tlačítko:</strong> zkontroluj zapnutí, uložené Client ID a Client secret, případně hlášení o aktualizaci databáze. Jde o zákaznické přihlášení, nikoli vstup do administrace.</li>
<li><strong>redirect_uri_mismatch:</strong> adresa v Google Cloud se musí znak po znaku shodovat s návratovou adresou výše. Po přesunu domény změň také nastavení klienta u Googlu.</li>
<li><strong>Chyba po návratu z Googlu:</strong> ověř, že Client ID a secret patří témuž klientovi a secret je u Googlu Enabled. Změny u Googlu se podle konzole mohou projevit se zpožděním.</li></ul>
<p>Uložený secret je viditelný pouze přihlášenému správci v této části administrace. Neposílej jej ve snímcích obrazovky ani nevkládej do veřejného repozitáře.</p>
<p>Oficiální nápověda: <a href="https://support.google.com/cloud/answer/15549257?hl=en" target="_blank" rel="noopener noreferrer">Clients</a> · <a href="https://support.google.com/cloud/answer/15549135?hl=en" target="_blank" rel="noopener noreferrer">Data Access</a> · <a href="https://support.google.com/cloud/answer/15549945?hl=en" target="_blank" rel="noopener noreferrer">Audience</a>.</p>
</section>
<script src="<?= $escape($basePath) ?>assets/google-login-settings.js?v=<?= filemtime(__DIR__ . '/../../assets/google-login-settings.js') ?>" defer></script>
