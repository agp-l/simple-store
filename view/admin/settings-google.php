<?php declare(strict_types=1); ?>
<section class="panel-panel"><h2>Přihlášení zákazníků přes Google</h2>
<p>Zákazník se může zaregistrovat, přihlásit a ve svém účtu Google připojit nebo odpojit. Přihlášení heslem zůstává dostupné. Tato volba se netýká administrátorů.</p>
<?php if (!$googleSettingsReady): ?><p class="panel-error">Nejdřív aktualizuj tabulky v sekci Databáze.</p><?php else: ?>
<form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=settings&tab=accounts') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save-google-login"><input type="hidden" name="tab" value="accounts">
<label><input type="checkbox" name="enabled" value="1" <?= $googleSettings['enabled']?'checked':'' ?>> Zapnout přihlášení přes Google</label>
<label>Client ID<input name="client_id" maxlength="200" value="<?= $escape($googleSettings['client_id']) ?>" autocomplete="off"></label>
<label>Client secret<input type="password" name="client_secret" maxlength="512" autocomplete="new-password" value=""></label>
<p><?= $googleSettings['client_secret_encrypted']!==''?'Tajný klíč je uložený. Prázdné pole jej zachová.':'Tajný klíč zatím není uložený.' ?> Klíč se nevrací do formuláře ani neexportuje v zálohách textů.</p>
<label>Autorizovaná návratová adresa<input type="url" readonly maxlength="1000" value="<?= $escape($googleSettings['redirect_uri']) ?>"></label>
<p class="panel-help">Adresa vzniká ze společné konfigurace webu. Po přesunu ji také aktualizuj v Google Cloud v Authorized redirect URIs.</p>
<button class="panel-button" type="submit">Uložit přihlášení Google</button></form>
<?php endif; ?>
<h3>Jednorázové nastavení u Googlu</h3>
<ol><li>Ve svém Google Cloud projektu otevři Google Auth Platform. Vyplň údaje aplikace a doménu obchodu.</li><li>Vytvoř OAuth klienta typu Webová aplikace. Do Authorized redirect URIs vlož přesnou HTTPS adresu obchodu zakončenou <code>/account/?google=callback</code> (bez <code>/cs</code>).</li><li>Client ID a Client secret vlož do polí výše. Povol pouze základní údaje <code>openid email profile</code>.</li><li>V testovacím režimu přidej testovací uživatele. Pro zákazníky mimo tento seznam musí mít aplikace odpovídající veřejné publikum a produkční stav.</li><li>Zapni přihlášení a ověř nový účet, připojení k existujícímu účtu a odpojení po nastavení hesla.</li></ol>
<p>Bez vlastních OAuth údajů nelze přihlášení aktivovat. Nevkládej tajný klíč do veřejného repozitáře ani do článků. Tlačítko na webu se zobrazí až po zapnutí.</p>
</section>
