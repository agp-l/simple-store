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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $escape($basePath) ?>assets/admin.css">
</head>
<body>
  <?php $inside = in_array($screen, ['editor', 'products', 'categories', 'menus'], true); ?>
  <header class="admin-header">
    <div class="admin-wrap admin-header-inner">
      <a class="admin-brand" href="<?= $escape($adminUrl) ?>"><svg class="admin-logo-mark" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20 10 5l3 7 2-4 6 12Z" /></svg>dobrodruzi<span class="admin-brand-label"> / správa</span></a>
      <?php if ($inside): ?>
        <div class="admin-header-actions">
          <a href="<?= $escape($basePath) ?>index.php">Otevřít obchod ↗</a>
          <form method="post" action="<?= $escape($adminUrl) ?>">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <button class="admin-quiet" type="submit" name="action" value="logout">Odhlásit se</button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </header>
  <?php if ($inside): ?>
  <div class="admin-shell admin-wrap">
    <aside class="admin-sidebar">
      <div class="admin-sidebar-heading"><span>Ovládací panel</span><small>OBCHOD A OBSAH</small></div>
      <nav aria-label="Správa webu">
        <a href="<?= $escape($adminUrl) ?>" <?= $screen === 'editor' ? 'aria-current="page"' : '' ?>><span aria-hidden="true">▤</span> Stránky a články</a>
        <a href="<?= $escape($adminUrl . '?section=products') ?>" <?= $screen === 'products' ? 'aria-current="page"' : '' ?>><span aria-hidden="true">▦</span> Produkty</a>
        <a href="<?= $escape($adminUrl . '?section=categories') ?>" <?= $screen === 'categories' ? 'aria-current="page"' : '' ?>><span aria-hidden="true">⌁</span> Kategorie</a>
        <a href="<?= $escape($adminUrl . '?section=menus') ?>" <?= $screen === 'menus' ? 'aria-current="page"' : '' ?>><span aria-hidden="true">☷</span> Menu</a>
      </nav>
      <div class="admin-sidebar-foot"><span class="admin-status-dot"></span> Přihlášený správce</div>
    </aside>
    <main class="admin-main" id="obsah">
  <?php else: ?>
    <main class="admin-wrap admin-auth-main" id="obsah">
  <?php endif; ?>
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
</body>
</html>
