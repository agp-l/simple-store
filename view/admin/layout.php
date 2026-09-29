<?php
declare(strict_types=1);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Administrace · dobrodruzi</title>
  <link rel="stylesheet" href="<?= $escape($basePath) ?>assets/admin.css">
</head>
<body>
  <header class="admin-header">
    <div class="admin-wrap admin-header-inner">
      <a class="admin-brand" href="<?= $escape($adminUrl) ?>">dobrodruzi<span class="admin-brand-label"> / redakce</span></a>
      <?php if ($screen === 'editor' || $screen === 'products'): ?>
        <div class="admin-header-actions">
          <a href="<?= $escape($basePath) ?>index.php">Zobrazit obchod</a>
          <form method="post" action="<?= $escape($adminUrl) ?>">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <button class="admin-quiet" type="submit" name="action" value="logout">Odhlásit se</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </header>
  <main class="admin-wrap">
    <?php if ($screen === 'setup'): ?>
      <section class="admin-panel admin-centered">
        <p class="admin-eyebrow">První spuštění</p><h1>Vytvoř administrátora</h1>
        <p>Nejdřív importuj <code>database/schema.sql</code>, pak v kořeni projektu spusť <code>/opt/lampp/bin/php tools/admin.php</code>. Příkaz jednou vypíše přístupové heslo; ulož si ho. Pokud už účet existoval v souboru, můžeš jej převést příkazem <code>/opt/lampp/bin/php tools/admin.php --migrate</code>.</p>
      </section>
    <?php elseif ($screen === 'forbidden'): ?>
      <section class="admin-panel admin-centered"><h1>Přístup odepřen</h1><p>Obnov stránku a zkus akci znovu.</p></section>
    <?php elseif ($screen === 'error'): ?>
      <section class="admin-panel admin-centered"><h1>Administraci se nepodařilo načíst</h1><p class="admin-error"><?= $escape($error) ?></p></section>
    <?php elseif ($screen === 'login'): ?>
      <section class="admin-panel admin-centered">
        <p class="admin-eyebrow">Redakční systém</p><h1>Přihlášení</h1>
        <?php if ($error !== ''): ?><p class="admin-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
        <form method="post" action="<?= $escape($adminUrl) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
          <label>Uživatelské jméno<input name="username" autocomplete="username" required autofocus></label>
          <label>Heslo<input type="password" name="password" autocomplete="current-password" required></label>
          <button class="admin-button" name="action" value="login">Přihlásit se</button>
        </form>
      </section>
    <?php elseif ($screen === 'products'): ?>
      <?php require __DIR__ . '/products.php'; ?>
    <?php else: ?>
      <?php require __DIR__ . '/contents.php'; ?>
    <?php endif; ?>
  </main>
</body>
</html>
