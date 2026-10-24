<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/src/bootstrap.php';
set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
$basePath = '/'; $adminUrl = '/admin.php'; $csrf = 'test-token';
$site = ['default_language' => 'cs', 'languages' => ['cs']];
$screen = 'orders'; $ordersReady = true; $orderError = '';
$order = null; $orderBaseUrl = $adminUrl . '?section=orders'; $statusFilter = 'all';
$ordersPreviousUrl = $ordersNextUrl = ''; $orderPage = ['items' => [], 'nextOffset' => null];
foreach (['paid', 'pending', 'paid'] as $i => $payment) {
    $orderPage['items'][] = ['id' => $i + 1, 'order_number' => 'DB-20261024-00' . ($i + 1),
        'created_at' => '2026-10-24 10:32:00', 'payment_method' => 'bank_transfer',
        'payment_status' => $payment, 'status' => ['processing', 'new', 'shipped'][$i],
        'total_czk' => [3490, 1850, 6290][$i], 'variable_symbol' => '2026000' . ($i + 1),
        'customer_email' => ['eva@example.test', 'petr@example.test', 'jana@example.test'][$i],
        'shipping_json' => json_encode(['recipient' => ['Eva Nová', 'Petr Veselý', 'Jana Malá'][$i], 'label' => 'PPL ParcelShop', 'method' => 'ppl_pickup'])];
}
require dirname(__DIR__, 2) . '/view/admin/layout.php';
