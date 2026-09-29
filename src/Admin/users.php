<?php
declare(strict_types=1);

use SimpleStore\Admin\CustomerManagementRepository;

// admin.php has already authenticated the administrator and checked POST CSRF.
$screen = 'users';
$usersError = '';
$manager = new CustomerManagementRepository($db);
$customer = null;
$search = $_GET['search'] ?? '';
$rawOffset = $_GET['offset'] ?? '0';
$offset = is_string($rawOffset) ? filter_var($rawOffset, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 100000]]) : false;
if (!is_string($search) || $offset === false) {
    $usersError = 'Neplatný filtr zákazníků.';
    http_response_code(422);
    $search = '';
    $offset = 0;
}
$rawId = $_POST['id'] ?? $_GET['id'] ?? null;
$userId = is_string($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]) : false;
if ($method === 'POST') {
    try {
        $field = static function (string $key): string {
            if (!is_string($_POST[$key] ?? null)) throw new InvalidArgumentException('Neplatné pole účtu.');
            return $_POST[$key];
        };
        $action = $field('action');
        if ($action === 'customer-create') {
            $manager->create($field('email'), $field('display_name'), $field('password'));
            header('Location: ' . $adminUrl . '?section=users&saved=1', true, 303);
            exit;
        }
        if ($userId === false) throw new InvalidArgumentException('Vyber zákazníka.');
        if ($action === 'customer-update') {
            $manager->update($userId, $field('email'), $field('display_name'), $field('phone'));
        } elseif ($action === 'customer-active') {
            $active = $field('active');
            if (!in_array($active, ['0', '1'], true)) throw new InvalidArgumentException('Neplatný stav účtu.');
            $manager->setActive($userId, $active === '1');
        } elseif ($action === 'customer-password') {
            $manager->setPassword($userId, $field('password'));
        } else {
            throw new InvalidArgumentException('Neznámá akce zákazníka.');
        }
        header('Location: ' . $adminUrl . '?section=users&id=' . $userId . '&saved=1', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        $usersError = $exception->getMessage();
        http_response_code(422);
    }
}
if ($userId !== false) {
    $customer = $manager->find($userId);
    if ($customer === null) {
        $usersError = 'Zákazník nebyl nalezen.';
        http_response_code(404);
    }
}
try {
    $usersPage = $manager->page($search, $offset);
} catch (InvalidArgumentException $exception) {
    $usersError = $exception->getMessage();
    $search = '';
    $offset = 0;
    $usersPage = $manager->page('', 0);
    http_response_code(422);
}
