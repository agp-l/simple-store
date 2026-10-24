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
        'order_token' => str_repeat((string) ($i + 1), 64),
        'invoice_id' => $i === 0 ? 12 : null, 'invoice_number' => $i === 0 ? 'FA-2026-0012' : null,
        'shipping_json' => json_encode(['recipient' => ['Eva Nová', 'Petr Veselý', 'Jana Malá'][$i], 'label' => 'PPL ParcelShop', 'method' => 'ppl_pickup'])];
}
if (($argv[1] ?? '') === 'states') {
    $orderPage['items'][] = array_replace($orderPage['items'][1], ['id' => 4, 'order_number' => 'OVERDUE', 'payment_due_at' => '2020-01-01 12:00:00']);
    $orderPage['items'][] = array_replace($orderPage['items'][0], ['id' => 5, 'order_number' => 'PAID-AFTER-CANCEL', 'status' => 'cancelled']);
    $orderPage['items'][] = array_replace($orderPage['items'][0], ['id' => 6, 'order_number' => 'GATEWAY-BLOCKED', 'payment_method' => 'btcpay', 'btcpay_payment_state' => 'processing']);
    $orderPage['items'][] = array_replace($orderPage['items'][0], ['id' => 7, 'order_number' => 'PACKETA-BLOCKED', 'status' => 'new', 'shipping_json' => json_encode(['method' => 'zasilkovna_pickup', 'label' => 'Zásilkovna'])]);
}
if (in_array($argv[1] ?? '', ['detail-home', 'detail-pickup'], true)) {
    $order = array_replace($orderPage['items'][1], [
        'created_at' => '2026-10-13 23:09:27', 'payment_due_at' => '2026-10-13 18:40:24',
        'subtotal_czk' => 1750, 'shipping_czk' => 100,
        'shipping' => ['method' => 'ppl_home', 'label' => 'PPL – na adresu',
            'recipient' => 'Eva Nová & syn', 'phone' => '+420 777 123 456',
            'street' => 'U Lesa 12/3', 'city' => 'Praha', 'postal_code' => '001 02', 'country' => 'CZ'],
        'payment_details' => ['account_display' => '123/2010'],
        'items' => [
            ['name' => 'Ultralehká turistická bunda s prodlouženým střihem', 'quantity' => 2, 'unit_price_czk' => 875,
                'variant_sku' => 'BUNDA-M-MODRA', 'options' => ['Velikost' => 'M', 'Barva' => 'Modrá <světlá>', 'Střih' => 'Prodloužený']],
            ['name' => 'Samostatný výrobek bez variant', 'quantity' => 1, 'unit_price_czk' => 0, 'options' => []],
        ],
    ]);
    if ($argv[1] === 'detail-pickup') {
        $order['shipping'] = array_replace($order['shipping'], ['method' => 'balikovna_pickup', 'label' => 'Balíkovna',
            'pickup_point' => 'Praha & okolí', 'pickup_address' => 'U Radnice 15, Praha, 110 00',
            'pickup_code' => '001234', 'pickup_postal_code' => '110 00', 'phone' => '']);
    }
}
if (($argv[1] ?? '') === 'returns') {
    $screen = 'returns'; $returnsReady = true; $returnError = ''; $returnCase = null;
    $returnSearch = ''; $returnRows = []; $returnBaseUrl = '/admin.php?section=returns';
}
require dirname(__DIR__, 2) . '/view/admin/layout.php';
