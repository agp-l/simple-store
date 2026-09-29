<?php
declare(strict_types=1);

use SimpleStore\Checkout\OrderRepository;

// admin.php has already authenticated the administrator and verified POST CSRF.
$screen = 'orders';
$orders = new OrderRepository($db);
$ordersReady = $orders->installed();
$orderError = '';
$order = null;
$orderPage = ['items' => [], 'nextOffset' => null];
$statusFilter = $_GET['status'] ?? 'all';
$rawOffset = $_GET['offset'] ?? '0';
$offset = is_string($rawOffset) ? filter_var($rawOffset, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 100000]]) : false;
if (!is_string($statusFilter) || !in_array($statusFilter, ['pending', 'paid', 'test', 'all'], true) ||
    $offset === false) {
    http_response_code(422);
    $orderError = 'Neplatný filtr objednávek.';
    $statusFilter = 'all';
    $offset = 0;
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'mark-order-paid') {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    if (!$ordersReady || $id === false || ($_POST['bank_checked'] ?? null) !== '1') {
        http_response_code(422);
        $orderError = 'Vyber objednávku a potvrď kontrolu přijaté platby ve výpisu banky.';
    } else {
        $admin = $auth->user();
        if ($admin === null) {
            throw new RuntimeException('Přihlášení správce vypršelo.');
        }
        $orders->markPaid($id, (int) $admin['id']);
        header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&paid=1', true, 303);
        exit;
    }
}

$rawId = $_GET['id'] ?? null;
if ($rawId !== null) {
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    if ($id === false || !$ordersReady || ($order = $orders->findById($id)) === null) {
        http_response_code(404);
        $orderError = 'Objednávka nebyla nalezena.';
    }
} elseif ($ordersReady && $orderError === '') {
    $orderPage = $orders->managementPage($offset, 25, $statusFilter === 'all' ? null : $statusFilter);
}

$orderBaseUrl = $adminUrl . '?section=orders';
$orderPageUrl = $orderBaseUrl . '&status=' . rawurlencode($statusFilter);
$ordersPreviousUrl = $offset > 0 ? $orderPageUrl . '&offset=' . max(0, $offset - 25) : '';
$ordersNextUrl = $orderPage['nextOffset'] === null ? '' : $orderPageUrl . '&offset=' . (int) $orderPage['nextOffset'];
