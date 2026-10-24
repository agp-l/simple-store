<?php
declare(strict_types=1);
$usersBaseUrl = $adminUrl . '?section=users';
?>
<div class="panel-intro"><div><p class="panel-eyebrow">Správa přístupů</p><h1>Účty a administrátoři</h1><p>Zákazníci i správci na jednom místě. Každý správce se přihlašuje vlastním e-mailem a heslem.</p></div>
<?php if ($usersReady): ?><a class="panel-button panel-action" href="<?= \SimpleStore\Navigation\PanelUrl::escape($usersBaseUrl . '&new=1') ?>"><?= \SimpleStore\Rendering\Icon::render('plus') ?> Nový účet</a><?php endif; ?></div>
<?php if (!$usersReady): ?><p class="panel-error">Pro správu všech účtů nejdřív <a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php return; endif; ?>
<div id="account-management">
<?php if ($usersError !== ''): ?><p class="panel-error" role="alert"><?= $escape($usersError) ?></p><?php endif; ?>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Změna účtu byla uložena.</p><?php endif; ?>
<?php if (($_GET['deleted'] ?? '') === '1'): ?><p class="panel-notice" role="status">Účet byl smazán. Objednávky a doklady zůstaly zachované.</p><?php endif; ?>
<?php if ($account !== null || $showCreate): ?>
<?php $detailUrl = $usersBaseUrl . ($account !== null ? '&id=' . (int) $account['id'] : '&new=1'); ?>
<section class="panel-panel">
  <div class="users-heading"><h2><?= $account !== null ? $escape($account['display_name'] ?: $account['username']) : 'Nový účet' ?></h2><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($usersBaseUrl) ?>">Zpět na seznam</a></div>
  <?php if ($account !== null): ?><p class="panel-help">Účet #<?= (int) $account['id'] ?> · vytvořen <?= $escape($account['created_at']) ?><?= (int) $account['id'] === $actorId ? ' · váš účet' : '' ?></p><?php endif; ?>
  <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($detailUrl) ?>" data-user-profile>
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
    <?php if ($account !== null): ?><input type="hidden" name="action" value="user-update"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><input type="hidden" name="revision" value="<?= $escape($method === 'POST' && ($_POST['action'] ?? '') === 'user-update' ? (is_string($_POST['revision'] ?? null) ? $_POST['revision'] : '') : $account['revision']) ?>">
    <?php else: ?><input type="hidden" name="action" value="user-create"><?php endif; ?>
    <div class="panel-fields-two">
      <label>Jméno<input name="display_name" value="<?= $escape($accountForm['display_name']) ?>" maxlength="120" required autocomplete="off"></label>
      <label>Přihlašovací e-mail<input type="email" name="email" value="<?= $escape($accountForm['email']) ?>" maxlength="254" required autocomplete="off"></label>
      <label>Telefon<input type="tel" name="phone" value="<?= $escape($accountForm['phone']) ?>" maxlength="40" autocomplete="off"></label>
      <label>Identifikátor účtu<input name="username" value="<?= $escape($accountForm['username']) ?>" minlength="3" maxlength="80" pattern="[a-zA-Z0-9][a-zA-Z0-9_.\-]{2,79}" <?= $account !== null ? 'required' : '' ?> autocomplete="off"><small>Při založení lze nechat prázdné. Jedinečný název bez mezer a diakritiky, např. jana.novakova.</small></label>
      <label>Role<select name="role"><option value="customer" <?= $accountForm['role'] === 'customer' ? 'selected' : '' ?>>Zákazník</option><option value="admin" <?= $accountForm['role'] === 'admin' ? 'selected' : '' ?>>Administrátor — plný přístup</option></select></label>
      <label>Stav účtu<select name="is_active"><option value="1" <?= (string) $accountForm['is_active'] === '1' ? 'selected' : '' ?>>Aktivní</option><option value="0" <?= (string) $accountForm['is_active'] === '0' ? 'selected' : '' ?>>Zablokovaný</option></select></label>
    </div>
    <?php if ($account === null): ?><?php require __DIR__ . '/users/password.php'; ?><?php endif; ?>
    <p class="panel-help">Administrátor má plný přístup ke správě obchodu i ostatních účtů. Změna e-mailu, role nebo stavu ukončí dosavadní přihlášení tohoto účtu.</p>
    <button class="panel-button panel-action" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> <?= $account !== null ? 'Uložit údaje' : 'Vytvořit účet' ?></button>
  </form>
</section>
<?php endif; ?>
<?php if ($account !== null): ?>
<section class="panel-panel"><h2>Nastavit heslo</h2>
  <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($detailUrl) ?>" data-user-password>
    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="user-password"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><input type="hidden" name="revision" value="<?= $escape($account['revision']) ?>">
    <?php require __DIR__ . '/users/password.php'; ?>
    <p class="panel-help">Uložením přestane fungovat staré heslo, předchozí přihlášení i obnovovací odkazy.<?= (int) $account['id'] === $actorId ? ' U svého účtu se poté znovu přihlásíš.' : '' ?></p>
    <button class="panel-button panel-action" type="submit"><?= \SimpleStore\Rendering\Icon::render('lock') ?> Uložit nové heslo</button>
  </form>
</section>
<section class="panel-panel"><div class="users-heading"><h2>Uložené adresy</h2><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($detailUrl . '&address=0#account-address') ?>">Nová adresa</a></div>
  <?php if ($accountAddresses === []): ?><p class="panel-empty">Účet nemá uložené adresy.</p><?php endif; ?>
  <div class="users-addresses"><?php foreach ($accountAddresses as $address): ?><article><strong><?= $escape($address['label']) ?></strong><p><?= $escape($address['recipient']) ?><br><?= $escape($address['company']) ?><br><?= $escape($address['street']) ?><br><?= $escape($address['postal_code'] . ' ' . $address['city'] . ', ' . $address['country']) ?><br><?= $escape($address['phone']) ?></p><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($detailUrl . '&address=' . (int) $address['id'] . '#account-address') ?>">Upravit adresu</a></article><?php endforeach; ?></div>
  <details id="account-address" <?= $addressId > 0 || ($method === 'POST' && ($_POST['action'] ?? '') === 'user-address-save') || isset($_GET['address']) ? 'open' : '' ?>><summary><?= $addressId > 0 ? 'Upravit adresu' : 'Přidat adresu' ?></summary>
    <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($detailUrl . '#account-address') ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="user-address-save"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><input type="hidden" name="revision" value="<?= $escape($account['revision']) ?>"><input type="hidden" name="address_id" value="<?= $addressId ?>">
      <div class="panel-fields-two"><?php foreach (['label' => ['Označení', 60], 'recipient' => ['Příjemce', 120], 'company' => ['Firma', 120], 'street' => ['Ulice a číslo', 190], 'city' => ['Město', 120], 'postal_code' => ['PSČ', 20], 'country' => ['Kód země (např. CZ)', 2], 'phone' => ['Telefon', 40]] as $key => [$label, $max]): ?><label><?= $escape($label) ?><input name="<?= $key ?>" value="<?= $escape($addressForm[$key]) ?>" maxlength="<?= $max ?>" <?= in_array($key, ['phone', 'company'], true) ? '' : 'required' ?>></label><?php endforeach; ?></div>
      <button class="panel-button panel-action" type="submit">Uložit adresu</button><p class="panel-help">Úprava adresáře nemění adresy v již vytvořených objednávkách.</p>
    </form>
    <?php if ($addressId > 0): ?><form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($detailUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="user-address-delete"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><input type="hidden" name="revision" value="<?= $escape($account['revision']) ?>"><input type="hidden" name="address_id" value="<?= $addressId ?>"><label class="users-check"><input type="checkbox" name="confirm_delete" value="1" required> Potvrzuji smazání této uložené adresy.</label><button class="panel-button panel-action panel-action--danger" type="submit">Smazat adresu</button></form><?php endif; ?>
  </details>
</section>
<section class="panel-panel"><h2>Poslední objednávky</h2>
  <?php if ($accountOrders === []): ?><p class="panel-empty">Účet nemá přiřazené objednávky.</p><?php endif; ?>
  <div class="panel-order-list"><?php foreach ($accountOrders as $listedOrder): ?><a class="panel-order-row" href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl . '?section=orders&id=' . (int) $listedOrder['id']) ?>"><span><strong><?= $escape($listedOrder['order_number']) ?></strong><small><?= $escape($listedOrder['created_at']) ?></small></span><span><?= $escape($listedOrder['status']) ?></span><strong><?= number_format((int) $listedOrder['total_czk'], 0, ',', ' ') ?> Kč</strong><span>Detail</span></a><?php endforeach; ?></div>
</section>
<section class="panel-panel"><details><summary>Smazat účet</summary><p>Smaže přihlašovací účet a jeho adresář. Objednávky, reklamace, doklady a historie změn zůstanou zachované.</p>
  <?php if ((int) $account['id'] === $actorId): ?><p class="panel-help">Vlastní účet smaže jiný aktivní administrátor.</p><?php else: ?>
  <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($detailUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="user-delete"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>"><input type="hidden" name="revision" value="<?= $escape($account['revision']) ?>"><label class="users-check"><input type="checkbox" name="confirm_delete" value="1" required> Trvale smazat účet <?= $escape($account['email'] ?: $account['username']) ?>.</label><button class="panel-button panel-action panel-action--danger" type="submit"><?= \SimpleStore\Rendering\Icon::render('delete') ?> Smazat účet</button></form><?php endif; ?></details>
</section>
<?php endif; ?>
<section class="panel-panel"><h2>Seznam účtů</h2>
  <form class="panel-form users-filters" method="get" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>"><input type="hidden" name="section" value="users">
    <label>Hledat účet<input type="search" name="search" value="<?= $escape($search) ?>" maxlength="100" placeholder="Jméno, e-mail, telefon…"></label>
    <label>Role<select name="role"><option value="">Všechny role</option><option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Administrátoři</option><option value="customer" <?= $roleFilter === 'customer' ? 'selected' : '' ?>>Zákazníci</option></select></label>
    <label>Stav<select name="active"><option value="">Všechny stavy</option><option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Aktivní</option><option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Zablokované</option></select></label>
    <button class="panel-button panel-action" type="submit">Filtrovat</button>
  </form>
  <?php if ($usersPage['items'] === []): ?><p class="panel-empty">Hledání neodpovídá žádný účet.</p><?php else: ?>
  <div class="panel-table-wrap"><table class="panel-table users-table"><thead><tr><th>Účet</th><th>Role</th><th>Stav</th><th>Objednávky</th><th></th></tr></thead><tbody>
    <?php foreach ($usersPage['items'] as $listed): ?><tr><td><strong><?= $escape($listed['display_name'] ?: $listed['username']) ?></strong><small><?= $escape($listed['email'] ?: $listed['username']) ?></small></td><td><?= $listed['role'] === 'admin' ? 'Administrátor' : 'Zákazník' ?><?= (int) $listed['id'] === $actorId ? ' (vy)' : '' ?></td><td><?= (int) $listed['is_active'] === 1 ? 'Aktivní' : 'Zablokovaný' ?></td><td><?= (int) $listed['order_count'] ?></td><td><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($usersBaseUrl . '&id=' . (int) $listed['id']) ?>">Upravit<span class="users-sr-only"> účet <?= $escape($listed['email'] ?: $listed['username']) ?></span></a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php $filterUrl = $usersBaseUrl . '&' . http_build_query(['search' => $search, 'role' => $roleFilter, 'active' => $activeFilter]); ?>
  <div class="panel-order-pages"><?php if ($offset > 0): ?><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($filterUrl . '&offset=' . max(0, $offset - 25)) ?>">Předchozí</a><?php endif; ?><?php if ($usersPage['nextOffset'] !== null): ?><a class="panel-icon-link" href="<?= \SimpleStore\Navigation\PanelUrl::escape($filterUrl . '&offset=' . $usersPage['nextOffset']) ?>">Další</a><?php endif; ?></div>
</section>
</div>
<script src="<?= $escape($basePath . 'assets/admin-users.js') ?>" defer></script>
