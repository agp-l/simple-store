<?php
// This body is framed by the shared storefront shell.
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$inside = in_array($screen, ['editor', 'products', 'categories', 'menus'], true);
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
        ['key' => 'editor', 'label' => 'Stránky a články', 'icon' => '▤', 'href' => $adminUrl],
        ['key' => 'products', 'label' => 'Produkty', 'icon' => '▦', 'href' => $adminUrl . '?section=products'],
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
      <section class="panel-panel panel-centered"><h1>Přístup odepřen</h1><p>Obnov stránku a zkus akci znovu.</p></section>
    <?php elseif ($screen === 'error'): ?>
      <section class="panel-panel panel-centered"><h1>Administraci se nepodařilo načíst</h1><p class="panel-error"><?= $escape($error) ?></p></section>
    <?php elseif ($screen === 'login'): ?>
      <section class="panel-panel panel-centered">
        <p class="panel-eyebrow">Redakční systém</p><h1>Přihlášení</h1>
        <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
        <form method="post" action="<?= $escape($adminUrl) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
          <label>Uživatelské jméno<input name="username" autocomplete="username" required autofocus></label>
          <label>Heslo<input type="password" name="password" autocomplete="current-password" required></label>
          <button class="panel-button" name="action" value="login">Přihlásit se</button>
        </form>
        <p>Zapomenuté heslo? Na serveru spusť <code>/opt/lampp/bin/php tools/admin.php --reset</code>.</p>
      </section>
    <?php elseif ($screen === 'products'): ?>
      <?php require __DIR__ . '/products.php'; ?>
    <?php elseif ($screen === 'categories'): ?>
      <?php require __DIR__ . '/categories.php'; ?>
    <?php elseif ($screen === 'menus'): ?>
      <?php require __DIR__ . '/menus.php'; ?>
    <?php else: ?>
      <?php require __DIR__ . '/contents.php'; ?>
    <?php endif; ?>
  </main>
  <?php if ($inside): ?></div><?php endif; ?>
</div>
