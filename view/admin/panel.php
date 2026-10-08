<?php
// This body is framed by the shared storefront shell.
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$inside = in_array($screen, ['editor', 'categories', 'menus', 'media', 'orders', 'returns', 'accounting', 'settings', 'users', 'database'], true);
$panelPreviewControl = $inside && $csrf !== '' ? [
    'active' => (bool) ($adminPreviewActive ?? false),
    'csrf' => $csrf,
    'return_to' => $basePath . $site['default_language'],
    'action' => $adminUrl,
] : null;
?>
<div class="panel-area">
  <?php if ($inside): ?>
  <div class="panel-shell panel-wrap">
    <?php
    $panelHeading = 'Správa webu';
    $panelSubtitle = 'OBCHOD A OBSAH';
    $panelIdentity = 'Přihlášený správce';
    $panelLogoutUrl = $adminUrl;
    $panelCurrent = $screen;
    $panelLinks = [
        ['key' => 'editor', 'label' => 'Stránky', 'icon' => '▤', 'href' => $adminUrl . '?section=contents'],
        ['key' => 'products', 'label' => 'Produkty', 'icon' => '▦', 'href' => $basePath . $site['default_language'] . '?manage=1#produkty'],
        ['key' => 'orders', 'label' => 'Objednávky', 'icon' => '▣', 'href' => $adminUrl . '?section=orders'],
        ['key' => 'returns', 'label' => 'Reklamace a vrácení', 'icon' => '↩', 'href' => $adminUrl . '?section=returns'],
        ['key' => 'accounting', 'label' => 'Doklady a platby', 'icon' => '▤', 'href' => $adminUrl . '?section=accounting'],
        ['key' => 'users', 'label' => 'Zákazníci', 'icon' => '♙', 'href' => $adminUrl . '?section=users'],
        ['key' => 'settings', 'label' => 'Nastavení obchodu', 'icon' => '⚙', 'href' => $adminUrl . '?section=settings'],
        ['key' => 'database', 'label' => 'Databáze', 'icon' => '▤', 'href' => $adminUrl . '?section=database'],
        ['key' => 'media', 'label' => 'Fotografie', 'icon' => '▧', 'href' => $adminUrl . '?section=media'],
        ['key' => 'categories', 'label' => 'Kategorie', 'icon' => '⌁', 'href' => $adminUrl . '?section=categories'],
        ['key' => 'menus', 'label' => 'Menu', 'icon' => '☷', 'href' => $adminUrl . '?section=menus'],
    ];
    require __DIR__ . '/../panel/sidebar.php';
    ?>
    <main class="panel-main" id="obsah">
  <?php else: ?>
    <main class="panel-wrap panel-auth-main" id="obsah">
  <?php endif; ?>
    <?php if ($screen === 'setup'): ?>
      <section class="panel-panel panel-centered">
        <p class="panel-eyebrow">První spuštění</p><h1>Vytvoř administrátora</h1>
        <p>Nejdřív importuj <code>database/schema.sql</code>, pak v kořeni projektu spusť <code>/opt/lampp/bin/php tools/admin.php</code>. Příkaz jednou vypíše přístupové heslo; ulož si ho. Pokud už účet existoval v souboru, můžeš jej převést příkazem <code>/opt/lampp/bin/php tools/admin.php --migrate</code>.</p>
      </section>
    <?php elseif ($screen === 'forbidden'): ?>
      <section class="panel-panel panel-centered"><h1>Přístup odepřen</h1><p role="alert"><?= $escape($error) ?></p><p><a href="<?= $escape($adminUrl) ?>">Přejít do administrace</a></p></section>
    <?php elseif ($screen === 'error'): ?>
      <section class="panel-panel panel-centered"><h1>Administraci se nepodařilo načíst</h1><p class="panel-error"><?= $escape($error) ?></p></section>
    <?php elseif ($screen === 'login'): ?>
      <section class="panel-panel panel-centered">
        <p class="panel-eyebrow">Redakční systém</p><h1>Přihlášení správce</h1>
        <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
        <form method="post" action="<?= $escape($adminUrl) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
          <label>Uživatelské jméno<input name="username" autocomplete="username" required autofocus></label>
          <label>Heslo<input type="password" name="password" autocomplete="current-password" required></label>
          <button class="panel-button" name="action" value="login">Přihlásit se</button>
        </form>
        <p><a href="<?= $escape($adminUrl . '?mode=forgot') ?>">Zapomenuté heslo?</a></p>
      </section>
    <?php elseif ($screen === 'reset-request'): ?>
      <section class="panel-panel panel-centered"><h1>Obnovit heslo správce</h1>
        <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
        <?php if (($_GET['sent'] ?? '') === '1'): ?><p class="panel-notice" role="status">Pokud je adresa nastavená pro správce, poslali jsme na ni odkaz pro obnovu. Další zprávu lze vyžádat nejdříve za pět minut.</p><?php endif; ?>
        <form method="post" action="<?= $escape($adminUrl . '?mode=forgot') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="reset-request">
          <label>E-mail správce<input type="email" name="email" maxlength="254" autocomplete="email" required autofocus></label>
          <button class="panel-button" type="submit">Poslat odkaz</button></form>
        <p><a href="<?= $escape($adminUrl) ?>">Zpět na přihlášení</a></p>
        <p>Pokud nemáš přístup k e-mailu, na serveru lze spustit <code>php tools/admin.php --reset</code>.</p>
      </section>
    <?php elseif ($screen === 'reset-complete'): ?>
      <section class="panel-panel panel-centered"><h1>Nastavit nové heslo správce</h1>
        <?php if (($_GET['done'] ?? '') === '1'): ?><p class="panel-notice" role="status">Heslo bylo změněno. Přihlas se novým heslem.</p>
        <?php else: ?>
          <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
          <?php if ($resetToken !== ''): ?><form method="post" action="<?= $escape($adminUrl . '?mode=reset') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="reset-complete"><input type="hidden" name="token" value="<?= $escape($resetToken) ?>">
            <label>Nové heslo<input type="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
            <label>Potvrdit nové heslo<input type="password" name="password_confirm" minlength="10" maxlength="72" autocomplete="new-password" required></label>
            <button class="panel-button" type="submit">Změnit heslo</button></form><?php endif; ?>
        <?php endif; ?><p><a href="<?= $escape($adminUrl) ?>">Přihlásit se</a></p>
      </section>
    <?php elseif ($screen === 'categories'): ?>
      <?php require __DIR__ . '/categories.php'; ?>
    <?php elseif ($screen === 'media'): ?>
      <?php require __DIR__ . '/media.php'; ?>
    <?php elseif ($screen === 'menus'): ?>
      <?php require __DIR__ . '/menus.php'; ?>
    <?php elseif ($screen === 'orders'): ?>
      <?php require __DIR__ . '/orders.php'; ?>
    <?php elseif ($screen === 'returns'): ?>
      <?php require __DIR__ . '/returns.php'; ?>
    <?php elseif ($screen === 'accounting'): ?>
      <?php require __DIR__ . '/accounting.php'; ?>
    <?php elseif ($screen === 'users'): ?>
      <?php require __DIR__ . '/users.php'; ?>
    <?php elseif ($screen === 'settings'): ?>
      <?php require __DIR__ . '/settings.php'; ?>
    <?php elseif ($screen === 'database'): ?>
      <?php require __DIR__ . '/database.php'; ?>
    <?php else: ?>
      <?php require __DIR__ . '/contents.php'; ?>
    <?php endif; ?>
  </main>
  <?php if ($inside): ?></div><?php endif; ?>
</div>
