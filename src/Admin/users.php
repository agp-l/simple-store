<?php
declare(strict_types=1);

use SimpleStore\Admin\AccountManagementRepository;

// admin.php authenticates the administrator and validates CSRF before routing here.
$screen = 'users';
$usersError = '';
$manager = new AccountManagementRepository($db);
$usersReady = $manager->installed();
if (!$usersReady) return;
$actorId = (int) $auth->user()['id'];
$account = null;
$accountOrders = $accountAddresses = [];
$accountForm = ['username' => '', 'email' => '', 'display_name' => '', 'phone' => '', 'role' => 'customer', 'is_active' => '1'];
$addressForm = ['label' => '', 'recipient' => '', 'company' => '', 'street' => '', 'city' => '', 'postal_code' => '', 'country' => 'CZ', 'phone' => ''];
$read = static fn (array $input, string $key, string $default = ''): string => is_string($input[$key] ?? null) ? $input[$key] : $default;
$search = $read($_GET, 'search');
$roleFilter = $read($_GET, 'role');
$activeFilter = $read($_GET, 'active');
$offset = filter_var($read($_GET, 'offset', '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
$offset = $offset === false ? 0 : $offset;
$userId = (int) filter_var($method === 'POST' ? $read($_POST, 'id', '0') : $read($_GET, 'id', '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$addressId = (int) filter_var($method === 'POST' ? $read($_POST, 'address_id', '0') : $read($_GET, 'address', '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$showCreate = $read($_GET, 'new') === '1' || ($method === 'POST' && $read($_POST, 'action') === 'user-create');
if ($userId > 0) {
    $account = $manager->find($userId);
    if ($account === null) { $usersError = 'Účet nebyl nalezen.'; http_response_code(404); }
    else {
        $accountForm = array_intersect_key($account, $accountForm);
        if ($account['role'] === 'admin') {
            $accountForm['email'] = $account['login_email'] ?? '';
        }
        if ($accountForm['display_name'] === '') $accountForm['display_name'] = $accountForm['username'];
    }
}
if ($method === 'POST') {
    try {
        $action = $read($_POST, 'action');
        $revision = $read($_POST, 'revision');
        if ($action === 'user-create') {
            $userId = $manager->create($actorId, $_POST, $read($_POST, 'password'));
        } elseif ($account === null) {
            throw new InvalidArgumentException('Vyber existující účet.');
        } elseif ($action === 'user-update') {
            $manager->update($actorId, $userId, $revision, $_POST);
        } elseif ($action === 'user-password') {
            $manager->setPassword($actorId, $userId, $revision, $read($_POST, 'password'));
        } elseif ($action === 'user-delete') {
            if ($read($_POST, 'confirm_delete') !== '1') throw new InvalidArgumentException('Potvrď smazání účtu.');
            $manager->delete($actorId, $userId, $revision);
            header('Location: ' . $adminUrl . '?section=users&deleted=1', true, 303);
            exit;
        } elseif ($action === 'user-address-save') {
            $manager->saveAddress($actorId, $userId, $revision, $addressId > 0 ? $addressId : null, $_POST);
        } elseif ($action === 'user-address-delete') {
            if ($read($_POST, 'confirm_delete') !== '1') throw new InvalidArgumentException('Potvrď smazání adresy.');
            $manager->deleteAddress($actorId, $userId, $revision, $addressId);
        } else {
            throw new InvalidArgumentException('Neznámá akce účtu.');
        }
        if (!$auth->signedIn()) {
            $auth->signOut();
            header('Location: ' . $adminUrl . '?account_changed=1', true, 303);
        } else {
            header('Location: ' . $adminUrl . '?section=users&id=' . $userId . '&saved=1', true, 303);
        }
        exit;
    } catch (InvalidArgumentException | RuntimeException $exception) {
        $usersError = $exception->getMessage();
        http_response_code(422);
        if (in_array($read($_POST, 'action'), ['user-create', 'user-update'], true)) {
            foreach ($accountForm as $key => $value) $accountForm[$key] = $read($_POST, $key);
        }
    }
}
if ($account !== null) {
    $accountOrders = $manager->recentOrders($userId);
    $accountAddresses = $manager->addresses($userId);
    if ($addressId > 0) {
        $found = false;
        foreach ($accountAddresses as $address) {
            if ((int) $address['id'] === $addressId) { $addressForm = array_intersect_key($address, $addressForm); $found = true; }
        }
        if (!$found) { $addressId = 0; $usersError = 'Adresa tohoto účtu nebyla nalezena.'; http_response_code(404); }
    }
    if ($method === 'POST' && $read($_POST, 'action') === 'user-address-save' && $usersError !== '') {
        foreach ($addressForm as $key => $value) $addressForm[$key] = $read($_POST, $key);
    }
}
try {
    $usersPage = $manager->page($search, $roleFilter, $activeFilter, $offset);
} catch (InvalidArgumentException $exception) {
    $usersError = $exception->getMessage();
    $search = $roleFilter = $activeFilter = ''; $offset = 0;
    $usersPage = $manager->page('', '', '', 0);
    http_response_code(422);
}
