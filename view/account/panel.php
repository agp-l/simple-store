<?php
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$accountMoney = static fn (int $czk): string => $escape($priceDisplay instanceof \SimpleStore\Pricing\BitcoinPriceDisplay
    ? $priceDisplay->format($czk) : number_format($czk, 0, ',', ' ') . ' Kč');
$sectionNames = ['overview' => 'Přehled', 'orders' => 'Objednávky', 'addresses' => 'Moje adresy',
    'payments' => 'Platby', 'settings' => 'Nastavení účtu'];
$checkoutReturn = $checkoutReturn ?? $accountUrl;
?>
<div class="panel-area">
  <?php if ($screen === 'account'): ?>
    <div class="panel-shell panel-wrap">
      <?php
      $panelHeading = 'Můj účet';
      $panelSubtitle = 'ZÁKAZNICKÁ ZÓNA';
      $panelIdentity = $user['display_name'];
      $panelLogoutUrl = $accountUrl;
      $panelCurrent = $section;
      $panelLinks = [
          ['key' => 'overview', 'label' => 'Přehled', 'icon' => '⌂', 'href' => $accountUrl],
          ['key' => 'orders', 'label' => 'Objednávky', 'icon' => '▤', 'href' => $accountUrl . '?section=orders'],
          ['key' => 'addresses', 'label' => 'Moje adresy', 'icon' => '⌖', 'href' => $accountUrl . '?section=addresses'],
          ['key' => 'payments', 'label' => 'Platby', 'icon' => '▣', 'href' => $accountUrl . '?section=payments'],
          ['key' => 'settings', 'label' => 'Nastavení účtu', 'icon' => '⚙', 'href' => $accountUrl . '?section=settings'],
      ];
      require __DIR__ . '/../panel/sidebar.php';
      ?>
      <main class="panel-main" id="obsah">
        <div class="panel-intro"><div><p class="panel-eyebrow">Zákaznická zóna</p><h1><?= $escape($sectionNames[$section]) ?></h1><p><?= $escape($user['display_name']) ?> · <?= $escape($user['email']) ?></p></div></div>
        <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
        <?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Změny byly uloženy.</p><?php endif; ?>

        <?php if ($section === 'overview'): ?>
          <div class="panel-grid panel-overview">
            <section class="panel-panel"><p class="panel-eyebrow">Na cestu</p><h2>Ahoj, <?= $escape($user['display_name']) ?>.</h2><p>Vítej ve svém účtu. Zůstáváš v obchodě a vše důležité máš tady po ruce.</p><a class="panel-button" href="<?= $escape($basePath . $language) ?>#produkty">Prohlédnout vybavení →</a></section>
            <section class="panel-panel"><p class="panel-eyebrow">Tvůj přehled</p><h2>Vše na jednom místě</h2><div class="panel-metrics"><a href="<?= $escape($accountUrl) ?>?section=orders"><strong><?= count($orders) ?></strong><span>posledních aktivních objednávek</span></a><a href="<?= $escape($accountUrl) ?>?section=addresses"><strong><?= count($addresses) ?></strong><span>uložených adres</span></a></div><p class="panel-help">Aktivní objednávky a historii najdeš v sekci Objednávky.</p></section>
          </div>
        <?php elseif ($section === 'orders'): ?>
          <section class="panel-panel"><h2><?= $orderHistory ? 'Historie objednávek' : 'Aktivní objednávky' ?></h2>
            <nav class="panel-order-tabs" aria-label="Stav objednávek"><a href="<?= $escape($accountUrl) ?>?section=orders" <?= !$orderHistory ? 'aria-current="page"' : '' ?>>Aktivní</a><a href="<?= $escape($accountUrl) ?>?section=orders&amp;history=1" <?= $orderHistory ? 'aria-current="page"' : '' ?>>Historie</a></nav>
            <p class="panel-help"><?= $orderHistory ? 'Dokončené, zrušené a testovací objednávky.' : 'Objednávky čekající na platbu, zpracování nebo doručení. Zaplacená objednávka zůstane aktivní do dokončení.' ?></p>
            <?php if ($orders === []): ?><p class="panel-empty"><?= $orderHistory ? 'V historii zatím nic není.' : 'Nemáš žádnou aktivní objednávku.' ?></p><a class="panel-text-link" href="<?= $escape($basePath . $language) ?>#produkty">Vrátit se k vybavení →</a>
            <?php else: ?><div class="panel-order-list"><?php foreach ($orders as $order): ?>
              <?php $orderLabel = match ($order['status'] ?? '') {
                  'completed' => 'Dokončeno', 'cancelled' => 'Zrušeno', 'shipped' => 'Odesláno',
                  'processing' => 'Připravuje se', 'ready_to_ship' => 'Připraveno k odeslání', 'test' => 'Testovací objednávka',
                  default => (($order['payment_status'] ?? '') === 'paid' ? 'Zaplaceno, čeká na zpracování' : 'Čeká na platbu'),
              }; ?>
              <div class="panel-order"><strong>Objednávka <?= $escape($order['order_number']) ?></strong><span><?= $escape($order['created_at']) ?> · <?= $escape($orderLabel) ?></span><strong><?= $accountMoney((int) $order['total_czk']) ?></strong>
                <a href="<?= $escape($accountUrl . '?section=orders' . ($orderHistory ? '&history=1' : '') . '&id=' . (int) $order['id']) ?>">Podrobnosti objednávky →</a>
              </div>
            <?php endforeach; ?></div><?php endif; ?>
            <div class="panel-order-pages"><?php if ($orderOffset > 0): ?><a href="<?= $escape($accountUrl . '?section=orders' . ($orderHistory ? '&history=1' : '') . '&offset=' . max(0, $orderOffset - 20)) ?>">← Předchozí</a><?php endif; ?><?php if ($orderPage['nextOffset'] !== null): ?><a href="<?= $escape($accountUrl . '?section=orders' . ($orderHistory ? '&history=1' : '') . '&offset=' . $orderPage['nextOffset']) ?>">Další →</a><?php endif; ?></div>
          </section>
          <?php if ($orderDetail !== null): ?>
          <section class="panel-panel panel-customer-order"><h2>Objednávka <?= $escape($orderDetail['order_number']) ?></h2>
            <?php $detailStatus = match ($orderDetail['status']) {
                'completed' => 'Dokončeno', 'cancelled' => 'Zrušeno', 'shipped' => 'Odesláno',
                'processing' => 'Připravuje se', 'ready_to_ship' => 'Připraveno k odeslání', 'test' => 'Testovací objednávka',
                default => 'Přijato',
            }; ?>
            <p><?= $escape($orderDetail['created_at']) ?> · Stav: <?= $escape($detailStatus) ?> · Platba: <?= $escape(match ($orderDetail['payment_status']) {
                'paid' => 'Zaplaceno', 'pending' => 'Čeká na platbu', 'test' => 'Testovací platba', default => 'Neznámý stav',
            }) ?></p>
            <?php foreach ($orderDetail['items'] as $item): ?><?php if (!is_array($item)) continue; ?>
              <div class="panel-order-line"><span><?= $escape($item['name'] ?? 'Položka') ?> · <?= (int) ($item['quantity'] ?? 0) ?> ks</span><strong><?= $accountMoney((int) ($item['unit_price_czk'] ?? 0) * (int) ($item['quantity'] ?? 0)) ?></strong></div>
            <?php endforeach; ?>
            <p>Produkty: <?= $accountMoney((int) $orderDetail['subtotal_czk']) ?> · <?= $escape($orderDetail['shipping']['label'] ?? 'Doprava') ?>: <?= $accountMoney((int) $orderDetail['shipping_czk']) ?></p>
            <p><strong>Celkem <?= $accountMoney((int) $orderDetail['total_czk']) ?></strong></p>
            <?php if (!empty($orderDetail['shipping']['pickup_point'])): ?><p>Výdejní místo: <?= $escape($orderDetail['shipping']['pickup_point']) ?>, <?= $escape($orderDetail['shipping']['pickup_address'] ?? '') ?></p>
            <?php else: ?><p>Doručení: <?= $escape($orderDetail['shipping']['recipient'] ?? $orderDetail['shipping']['name'] ?? '') ?>, <?= $escape($orderDetail['shipping']['street'] ?? '') ?>, <?= $escape($orderDetail['shipping']['postal_code'] ?? '') ?> <?= $escape($orderDetail['shipping']['city'] ?? '') ?></p><?php endif; ?>
            <?php if ($orderTrackingUrl !== null): ?><p><a class="panel-text-link" href="<?= $escape($orderTrackingUrl) ?>" target="_blank" rel="noopener noreferrer">Sledovat zásilku u Zásilkovny →</a></p><?php endif; ?>
            <?php if (!empty($orderDetail['order_token'])): ?><a class="panel-text-link" href="<?= $escape($basePath . $language . '/objednavka/' . $orderDetail['order_token']) ?>">Zobrazit platební údaje →</a><?php endif; ?>
          </section>
          <?php endif; ?>
          <section class="panel-panel"><h2>Přidat starší nákup bez účtu</h2><p>Pokud sis objednal jako host, vlož soukromý odkaz z potvrzovací stránky. E-mail v objednávce musí odpovídat e-mailu tohoto účtu. Pouhé číslo objednávky nestačí.</p>
            <form class="panel-form" method="post" action="<?= $escape($accountUrl . '?section=orders') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="claim-order"><label>Odkaz na objednávku nebo její tajný kód <input name="order_reference" maxlength="1000" required autocomplete="off"></label><button class="panel-button" type="submit">Přiřadit objednávku</button></form>
          </section>
        <?php elseif ($section === 'addresses'): ?>
          <div class="panel-grid panel-grid-catalog">
            <section class="panel-panel"><h2>Uložené adresy</h2>
              <?php if ($addresses === []): ?><p class="panel-empty">Zatím nemáš uloženou žádnou adresu.</p><?php endif; ?>
              <?php foreach ($addresses as $address): ?><div class="panel-address-row"><div><strong><?= $escape($address['label']) ?></strong><p><?= $escape($address['recipient']) ?><br><?= $escape($address['street']) ?><br><?= $escape($address['postal_code'] . ' ' . $address['city'] . ', ' . $address['country']) ?></p></div><div class="panel-address-actions"><a href="<?= $escape($accountUrl . '?section=addresses&edit=' . $address['id']) ?>">Upravit</a><form method="post" action="<?= $escape($accountUrl . '?section=addresses') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="address-remove"><input type="hidden" name="id" value="<?= (int) $address['id'] ?>"><button type="submit">Smazat</button></form></div></div><?php endforeach; ?>
            </section>
            <section class="panel-panel"><p class="panel-eyebrow">Adresa</p><h2><?= $editAddress === null ? 'Přidat adresu' : 'Upravit adresu' ?></h2>
              <form class="panel-form" method="post" action="<?= $escape($accountUrl . '?section=addresses') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="address-save"><input type="hidden" name="id" value="<?= (int) ($editAddress['id'] ?? 0) ?: '' ?>">
                <label>Označení <input name="label" maxlength="60" required placeholder="Domů nebo práce" value="<?= $escape($editAddress['label'] ?? '') ?>"></label>
                <label>Jméno příjemce <input name="recipient" maxlength="120" autocomplete="name" required value="<?= $escape($editAddress['recipient'] ?? $user['display_name']) ?>"></label>
                <label>Ulice a číslo <input name="street" maxlength="190" autocomplete="street-address" required value="<?= $escape($editAddress['street'] ?? '') ?>"></label>
                <div class="panel-fields-two"><label>Město <input name="city" maxlength="120" autocomplete="address-level2" required value="<?= $escape($editAddress['city'] ?? '') ?>"></label><label>PSČ <input name="postal_code" maxlength="20" autocomplete="postal-code" required value="<?= $escape($editAddress['postal_code'] ?? '') ?>"></label></div>
                <div class="panel-fields-two"><label>Země (kód) <input name="country" maxlength="2" autocomplete="country" required value="<?= $escape($editAddress['country'] ?? 'CZ') ?>"></label><label>Telefon (volitelný) <input name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= $escape($editAddress['phone'] ?? $user['phone']) ?>"></label></div>
                <button class="panel-button" type="submit">Uložit adresu</button>
              </form>
            </section>
          </div>
        <?php elseif ($section === 'payments'): ?>
          <section class="panel-panel panel-payment"><p class="panel-eyebrow">Platební metody</p><h2>Bankovní převod</h2><p>Objednávku zatím zaplatíte převodem na účet. Číslo účtu, variabilní symbol a QR kód se zobrazí po odeslání objednávky. Platební karty se na tomto webu neukládají.</p><a class="panel-text-link" href="<?= $escape($accountUrl) ?>">Zpět na přehled →</a></section>
        <?php elseif ($section === 'settings'): ?>
          <div class="panel-grid panel-grid-catalog">
            <section class="panel-panel"><h2>Osobní údaje</h2><form class="panel-form" method="post" action="<?= $escape($accountUrl . '?section=settings') ?>"><input type="hidden" name="action" value="profile"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><label>Jméno <input name="display_name" maxlength="120" autocomplete="name" required value="<?= $escape($user['display_name']) ?>"></label><label>Telefon (volitelný) <input name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= $escape($user['phone']) ?>"></label><button class="panel-button" type="submit">Uložit profil</button></form></section>
            <section class="panel-panel"><h2>Změnit e-mail</h2><p>Současný přihlašovací e-mail: <strong><?= $escape($user['email']) ?></strong>. Novou adresu napiš dvakrát a potvrď heslem účtu.</p><form class="panel-form" method="post" action="<?= $escape($accountUrl . '?section=settings') ?>"><input type="hidden" name="action" value="email"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><label>Nový e-mail <input type="email" name="new_email" maxlength="254" autocomplete="email" required></label><label>Potvrdit nový e-mail <input type="email" name="email_confirm" maxlength="254" required></label><label>Současné heslo <input type="password" name="current_password" autocomplete="current-password" required></label><button class="panel-button" type="submit">Změnit e-mail</button></form></section>
            <section class="panel-panel"><h2>Změnit heslo</h2><form class="panel-form" method="post" action="<?= $escape($accountUrl . '?section=settings') ?>"><input type="hidden" name="action" value="password"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><label>Současné heslo <input type="password" name="current_password" autocomplete="current-password" required></label><label>Nové heslo <input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><label>Potvrdit nové heslo <input type="password" name="password_confirm" minlength="12" autocomplete="new-password" required></label><button class="panel-button" type="submit">Změnit heslo</button></form></section>
          </div>
        <?php endif; ?>
      </main>
    </div>
  <?php else: ?>
    <main class="panel-wrap panel-auth-main" id="obsah"><section class="panel-panel panel-centered">
      <?php if ($screen === 'setup'): ?><p class="panel-eyebrow">Příprava účtu</p><h1>Klientská zóna zatím není připravená</h1><p>Importuj aktuální <code>database/schema.sql</code> do databáze obchodu.</p>
      <?php elseif ($screen === 'error'): ?><p class="panel-eyebrow">Účet</p><h1>Stránku se nepodařilo načíst</h1><p class="panel-error" role="alert"><?= $escape($error) ?></p>
      <?php elseif ($screen === 'reset-request'): ?>
        <h1>Obnovit heslo</h1>
        <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
        <?php if (($_GET['sent'] ?? '') === '1'): ?><p class="panel-notice" role="status">Pokud u nás máš účet s touto adresou, poslali jsme odkaz pro obnovu.</p><?php endif; ?>
        <form class="panel-form" method="post" action="<?= $escape($accountUrl . '?mode=forgot') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="reset-request">
          <label>E-mail účtu<input type="email" name="email" maxlength="254" autocomplete="email" required autofocus></label>
          <button class="panel-button" type="submit">Poslat odkaz</button></form>
        <p><a href="<?= $escape($accountUrl) ?>">Zpět na přihlášení</a></p>
      <?php elseif ($screen === 'reset-complete'): ?>
        <h1>Nastavit nové heslo</h1>
        <?php if (($_GET['done'] ?? '') === '1'): ?><p class="panel-notice" role="status">Heslo bylo změněno. Přihlas se novým heslem.</p><?php else: ?>
          <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
          <?php if ($resetToken !== ''): ?><form class="panel-form" method="post" action="<?= $escape($accountUrl . '?mode=reset') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="reset-complete"><input type="hidden" name="token" value="<?= $escape($resetToken) ?>">
            <label>Nové heslo<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
            <label>Potvrdit nové heslo<input type="password" name="password_confirm" minlength="12" maxlength="72" autocomplete="new-password" required></label>
            <button class="panel-button" type="submit">Změnit heslo</button></form><?php endif; ?>
        <?php endif; ?><p><a href="<?= $escape($accountUrl) ?>">Přihlásit se</a></p>
      <?php else: ?>
        <p class="panel-eyebrow">Dobrodruzi / účet</p><h1><?= $screen === 'register' ? 'Vytvořit účet' : 'Přihlášení' ?></h1>
        <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
        <form class="panel-form" method="post" action="<?= $escape($accountUrl . ($screen === 'register' ? '?mode=register' : '') . (($checkoutReturn !== $accountUrl) ? ($screen === 'register' ? '&' : '?') . 'checkout=1' : '')) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="<?= $screen === 'register' ? 'register' : 'login' ?>">
          <?php if ($screen === 'register'): ?><label>Jméno <input name="display_name" maxlength="120" autocomplete="name" required></label><?php endif; ?>
          <label>E-mail <input type="email" name="email" maxlength="254" autocomplete="email" required autofocus></label>
          <label>Heslo <input type="password" name="password" <?= $screen === 'register' ? 'minlength="12" autocomplete="new-password"' : 'autocomplete="current-password"' ?> required></label>
          <?php if ($screen === 'register'): ?><label>Potvrdit heslo <input type="password" name="password_confirm" minlength="12" autocomplete="new-password" required></label><?php endif; ?>
          <button class="panel-button" type="submit"><?= $screen === 'register' ? 'Zaregistrovat se' : 'Přihlásit se' ?></button>
        </form>
        <?php if ($screen === 'login'): ?><p><a href="<?= $escape($accountUrl . '?mode=forgot') ?>">Zapomenuté heslo?</a></p><?php endif; ?>
        <?php if ($screen === 'register' || $registrationAllowed): ?><p class="panel-auth-switch"><?= $screen === 'register' ? 'Už máš účet?' : 'Ještě nemáš účet?' ?> <?php if ($screen === 'register'): ?><a href="<?= $escape($accountUrl . ($checkoutReturn !== $accountUrl ? '?checkout=1' : '')) ?>">Přihlásit se</a><?php else: ?><a href="<?= $escape($accountUrl . '?mode=register' . ($checkoutReturn !== $accountUrl ? '&checkout=1' : '')) ?>">Vytvořit účet</a><?php endif; ?></p><?php endif; ?>
      <?php endif; ?>
    </section></main>
  <?php endif; ?>
</div>
