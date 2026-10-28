<?php declare(strict_types=1); ?>
<?php if ($screen === 'account'): ?>
<section class="panel-panel"><h2>Přihlášení a Google účet</h2>
<?php if (($user['password_hash'] ?? 'unavailable') === ''): ?>
<p>Přihlašuješ se přes Google. Nastav si také heslo, abys mohl používat e-mail a heslo nebo Google později odpojit.</p>
<form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($accountUrl.'?section=settings') ?>">
<input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="google-password">
<label>Nové heslo<input type="password" name="new_password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
<label>Potvrdit heslo<input type="password" name="password_confirm" minlength="10" maxlength="72" autocomplete="new-password" required></label>
<button class="panel-button" type="submit">Nastavit heslo</button></form>
<?php if (!empty($googleReady)): ?><form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($accountUrl.'?section=settings') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="google-start"><p>Pro nastavení hesla je potřeba ověření přes Google z posledních pěti minut.</p><button class="panel-button" type="submit">Znovu ověřit přes Google</button></form><?php endif; ?>
<form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($accountUrl.'?section=settings') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="google-recovery"><p>Heslo můžeš nastavit také pomocí odkazu na e-mailu účtu. Po odeslání se odhlásíš.</p><button class="panel-button" type="submit">Poslat odkaz pro nastavení hesla</button></form>
<?php endif; ?>
<?php if (!empty($googleLinked)): ?>
<p>Připojený Google účet: <strong><?= $escape($googleLinked['google_email']) ?></strong></p>
<?php if (($user['password_hash'] ?? 'unavailable') !== ''): ?>
<p>Google můžeš odpojit. Přihlášení e-mailem a heslem i tvoje objednávky zůstanou zachované. Ostatní přihlášená zařízení budou odhlášena.</p>
<form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($accountUrl.'?section=settings') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="google-unlink"><label>Heslo účtu<input type="password" name="current_password" autocomplete="current-password" required></label><button class="panel-button" type="submit">Odpojit Google</button></form>
<?php else: ?><p>Odpojení bude dostupné, až si nastavíš heslo.</p><?php endif; ?>
<?php elseif (!empty($googleReady)): ?>
<p>Připoj Google ke svému účtu. Přihlášení heslem bude fungovat dál.</p>
<form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($accountUrl.'?section=settings') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="google-start"><label>Současné heslo<input type="password" name="current_password" autocomplete="current-password" required></label><button class="panel-button" type="submit">Připojit Google účet</button></form>
<?php else: ?><p>Přihlašování přes Google zatím není zapnuté.</p><?php endif; ?>
</section>
<?php elseif (!empty($googleReady)): ?>
<form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($accountUrl.($checkoutReturn!==$accountUrl?'?checkout=1':'')) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="google-start"><button class="panel-button panel-google-login" type="submit">Přihlásit se přes Google</button><p><?= !empty($registrationAllowed) ? 'Pokud účet ještě nemáš, vytvoříme ho při prvním přihlášení.' : 'Pro přihlášení musíš mít Google již připojený ke svému účtu.' ?></p></form>
<p>Nebo použij e-mail a heslo:</p>
<?php endif; ?>
