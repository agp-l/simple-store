<?php
// One full-width workspace for every authenticated administration section.
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$inside = $adminWorkspace;
?>
<div class="panel-area">
  <?php if ($inside): ?>
  <div class="panel-shell panel-wrap <?= $screen === 'accounting' ? 'panel-shell--evidence' : '' ?>">
    <?php
    $panelCurrent = $screen === 'content-import' ? 'product-import' : $screen;
    $panelLinks = [
        ['key' => 'editor', 'label' => 'Stránky', 'icon_name' => 'document', 'href' => $adminUrl . '?section=contents'],
        ['key' => 'products', 'label' => 'Produkty', 'icon_name' => 'package', 'href' => $basePath . $site['default_language'] . '?manage=1#produkty'],
        ['key' => 'purchase-partners', 'label' => 'Prodávající', 'icon_name' => 'users', 'href' => $adminUrl . '?section=purchase-partners'],
        ['key' => 'product-import', 'label' => 'Import a export', 'icon_name' => 'upload', 'href' => $adminUrl . '?section=product-import'],
        ['key' => 'orders', 'label' => 'Objednávky', 'icon_name' => 'package', 'href' => $adminUrl . '?section=orders'],
        ['key' => 'returns', 'label' => 'Reklamace a vrácení', 'icon_name' => 'refresh', 'href' => $adminUrl . '?section=returns'],
        ['key' => 'accounting', 'label' => 'Doklady a platby', 'icon_name' => 'document', 'href' => $adminUrl . '?section=accounting'],
        ['key' => 'users', 'label' => 'Účty a administrátoři', 'icon_name' => 'users', 'href' => $adminUrl . '?section=users'],
        ['key' => 'settings', 'label' => 'Nastavení obchodu', 'icon_name' => 'settings', 'href' => $adminUrl . '?section=settings'],
        ['key' => 'database', 'label' => 'Databáze', 'icon_name' => 'database', 'href' => $adminUrl . '?section=database'],
        ['key' => 'media', 'label' => 'Fotografie', 'icon_name' => 'image', 'href' => $adminUrl . '?section=media'],
        ['key' => 'categories', 'label' => 'Kategorie', 'icon_name' => 'folder', 'href' => $adminUrl . '?section=categories'],
        ['key' => 'menus', 'label' => 'Menu', 'icon_name' => 'list', 'href' => $adminUrl . '?section=menus'],
    ];
    require __DIR__ . '/sidebar.php';
    ?>
    <main class="panel-main" id="obsah">
  <?php else: ?>
    <main class="panel-wrap panel-auth-main" id="obsah">
  <?php endif; ?>
    <?php if ($screen === 'setup'): ?>
      <section class="panel-panel panel-centered">
        <p class="panel-eyebrow">První spuštění</p><h1>Vytvoř administrátora</h1>
        <p>Nejdřív importuj <code>database/schema.sql</code>, pak v kořeni projektu spusť <code>php tools/admin.php create --email=tvuj@email.cz</code>. Příkaz jednou vypíše přístupové heslo; ulož si ho. Pokud už účet existoval v souboru, můžeš jej převést příkazem <code>php tools/admin.php --migrate --email=tvuj@email.cz</code>.</p>
      </section>
    <?php elseif ($screen === 'forbidden'): ?>
      <section class="panel-panel panel-centered"><h1>Přístup odepřen</h1><p role="alert"><?= $escape($error) ?></p><p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('arrow-right') ?> Přejít do administrace</a></p></section>
    <?php elseif ($screen === 'error'): ?>
      <section class="panel-panel panel-centered"><h1>Administraci se nepodařilo načíst</h1><p class="panel-error"><?= $escape($error) ?></p></section>
    <?php elseif ($screen === 'login'): ?>
      <section class="panel-panel panel-centered">
        <p class="panel-eyebrow">Redakční systém</p><h1>Přihlášení správce</h1>
        <?php if (($_GET['account_changed'] ?? '') === '1'): ?><p class="panel-notice">Přihlašovací údaje byly změněny. Přihlas se aktuálním e-mailem a heslem.</p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
        <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
          <label>E-mail správce<input type="email" name="email" maxlength="254" autocomplete="username" required autofocus></label>
          <label>Heslo<input type="password" name="password" autocomplete="current-password" required></label>
          <button class="panel-action panel-button" name="action" value="login"><?= \SimpleStore\Rendering\Icon::render('lock') ?> Přihlásit se</button>
        </form>
        <p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?mode=forgot') ?>">Zapomenuté heslo?</a></p>
      </section>
    <?php elseif ($screen === 'reset-request'): ?>
      <section class="panel-panel panel-centered"><h1>Obnovit heslo správce</h1>
        <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
        <?php if (($_GET['sent'] ?? '') === '1'): ?><p class="panel-notice" role="status">Pokud existuje aktivní správce s tímto e-mailem, obdrží odkaz pro obnovu hesla.</p><?php endif; ?>
        <form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?mode=forgot') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="reset-request">
          <label>E-mail správce<input type="email" name="email" maxlength="254" autocomplete="username" required></label><p>Každý správce obnovuje vlastní účet. Další zprávu lze poslat nejdříve za pět minut.</p>
          <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('mail') ?> Poslat odkaz</button></form>
        <p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('arrow-left') ?> Zpět na přihlášení</a></p>
        <p>Pokud nemáš přístup k e-mailu, na serveru lze spustit <code>php tools/admin.php --reset --email=tvuj@email.cz</code>.</p>
      </section>
    <?php elseif ($screen === 'reset-complete'): ?>
      <section class="panel-panel panel-centered"><h1>Nastavit nové heslo správce</h1>
        <?php if (($_GET['done'] ?? '') === '1'): ?><p class="panel-notice" role="status">Heslo bylo změněno. Přihlas se novým heslem.</p>
        <?php else: ?>
          <?php if ($error !== ''): ?><p class="panel-error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
          <?php if ($resetToken !== ''): ?><form method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?mode=reset') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="reset-complete"><input type="hidden" name="token" value="<?= $escape($resetToken) ?>">
            <label>Nové heslo<input type="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
            <label>Potvrdit nové heslo<input type="password" name="password_confirm" minlength="10" maxlength="72" autocomplete="new-password" required></label>
            <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('edit') ?> Změnit heslo</button></form><?php endif; ?>
        <?php endif; ?><p><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('lock') ?> Přihlásit se</a></p>
      </section>
    <?php elseif ($screen === 'content-import'): ?>
      <?php require __DIR__ . '/content-import.php'; ?>
    <?php elseif ($screen === 'purchase-partners'): ?>
      <?php require __DIR__ . '/purchase-partners.php'; ?>
    <?php elseif ($screen === 'product-import'): ?>
      <?php require __DIR__ . '/product-import.php'; ?>
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
  <?php if ($inside): ?><footer class="admin-footer"><span>dobrodruzi <span aria-hidden="true">/</span> Správa obchodu</span><span>Na další cestu připraveni <?= \SimpleStore\Rendering\Icon::render('arrow-right') ?></span></footer><?php endif; ?>
  </main>
  <?php if ($inside): ?></div><?php endif; ?>
</div>
