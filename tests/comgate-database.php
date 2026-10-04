<?php
declare(strict_types=1);

// CI-only integration check. The provider transport is fake; persistence uses real MySQL.
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Checkout\ComgateApiClient;
use SimpleStore\Checkout\ComgatePaymentService;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

function expectComgate(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = ConnectionFactory::create([
    'host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$settings = [
    'enabled' => true, 'test' => true, 'merchant' => 'test-merchant',
    'secret' => 'test-only-secret', 'return_base_url' => 'https://shop.example.test/',
];
$transId = 'CZ-TEST-' . strtoupper(bin2hex(random_bytes(6)));
$redirect = 'https://payments.comgate.cz/payment/' . rawurlencode($transId);
$providerStatus = 'PENDING';
$responseOverride = [];
$calls = [];
$transport = static function (
    string $method, string $url, ?array $json, string $merchant, string $secret
) use (&$calls, &$providerStatus, &$responseOverride, $transId, $redirect): array {
    $calls[] = [$method, $url, $json, $merchant, $secret];
    if ($method === 'POST') {
        return ['code' => 0, 'message' => 'OK', 'transId' => $transId, 'redirect' => $redirect];
    }
    if ($method === 'GET') {
        return array_replace([
            'code' => 0, 'message' => 'OK', 'transId' => $transId,
            'status' => $providerStatus, 'test' => true,
            'price' => 107900, 'curr' => 'CZK',
            'refId' => (string) ($calls[0][2]['refId'] ?? ''),
        ], $responseOverride);
    }
    throw new RuntimeException('Unexpected Comgate transport method.');
};
$client = new ComgateApiClient($settings['merchant'], $settings['secret'], $transport);
$payments = new ComgatePaymentService($db, $settings, $client);
expectComgate($payments->installed(), 'Comgate payment tables were not installed.');

$items = [[
    'product_key' => str_repeat('a', 32), 'language' => 'cs', 'slug' => 'stan',
    'name' => 'Stan', 'quantity' => 2, 'unit_price_czk' => 500,
    'image_path' => '', 'options' => [],
]];
$shipping = [
    'method' => 'gls_home', 'label' => 'GLS domů', 'name' => 'Eva Nová',
    'phone' => '123',
    'street' => 'Polní 1', 'city' => 'Praha', 'postal_code' => '11000', 'country' => 'CZ',
];
$orders = new OrderRepository($db);
$order = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'comgate');
expectComgate((int) $order['total_czk'] === 1079 && $order['payment_method'] === 'comgate' &&
    $order['payment_status'] === 'pending' && $order['payment_details'] === [] &&
    $order['payment_due_at'] === null,
    'Comgate order saved the wrong total, method or bank transfer instructions.');
expectComgate($payments->receiptUrl($order, 'cs') ===
    'https://shop.example.test/cs/objednavka/' . $order['order_token'],
    'Confirmation mail cannot link to the canonical private order receipt.');

$url = $payments->initiate($order);
expectComgate($url === $redirect && count($calls) === 1 && $calls[0][0] === 'POST' &&
    $calls[0][3] === 'test-merchant' && $calls[0][4] === 'test-only-secret' &&
    $calls[0][2]['test'] === true && $calls[0][2]['price'] === 107900 &&
    !isset($calls[0][2]['phone']) &&
    $calls[0][2]['curr'] === 'CZK' && $calls[0][2]['refId'] === $order['order_number'],
    'Comgate request lost credentials, test flag or the order amount/reference.');
expectComgate($payments->initiate($order) === $redirect && count($calls) === 1,
    'Repeated checkout initiated a second provider payment.');
$stored = $orders->findById((int) $order['id']);
expectComgate($stored['provider_reference'] === $transId &&
    $payments->state((int) $order['id']) !== null,
    'Provider transaction reference was not persisted.');

// A browser redirect or callback payload cannot declare a payment paid on its own.
$notification = [
    'transId' => $transId, 'merchant' => $settings['merchant'], 'secret' => $settings['secret'],
    'status' => 'PAID', 'test' => true, 'price' => 107900, 'curr' => 'CZK',
    'refId' => $order['order_number'],
];
$rejected = false;
try {
    $payments->notify(array_replace($notification, ['secret' => 'forged']));
} catch (InvalidArgumentException $expected) {
    $rejected = true;
}
expectComgate($rejected && count($calls) === 1 &&
    $orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'Unauthenticated callback was accepted or queried the provider.');
$payments->notify($notification);
expectComgate($orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'Unverified callback marked an unpaid provider transaction as paid.');
$providerStatus = 'AUTHORIZED';
$payments->refresh($order);
expectComgate($orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'Authorization was incorrectly treated as a captured payment.');

foreach ([
    ['price' => 107800], ['curr' => 'EUR'], ['refId' => 'OTHER-ORDER'],
    ['test' => false], ['transId' => 'OTHER-TRANSACTION'],
] as $invalid) {
    $responseOverride = $invalid;
    $rejected = false;
    try {
        $payments->refresh($order);
    } catch (Throwable $expected) {
        $rejected = true;
    }
    expectComgate($rejected && $orders->findById((int) $order['id'])['payment_status'] === 'pending',
        'Mismatched provider status was accepted as payment.');
}
$responseOverride = [];
$providerStatus = 'PAID';
$payments->notify($notification);
expectComgate($orders->findById((int) $order['id'])['payment_status'] === 'paid',
    'Verified provider payment did not settle the order.');
$paidAt = $orders->findById((int) $order['id'])['payment_paid_at'];
$providerStatus = 'CANCELLED';
$payments->notify($notification);
expectComgate($orders->findById((int) $order['id'])['payment_status'] === 'paid' &&
    $orders->findById((int) $order['id'])['payment_paid_at'] === $paidAt,
    'Late or replayed cancellation downgraded a verified payment.');
$disabled = new ComgatePaymentService($db, array_replace($settings, ['enabled' => false]), $client);
expectComgate(!$disabled->canInitiate() &&
    $disabled->refresh($orders->findById((int) $order['id']))['payment_status'] === 'paid',
    'Disabling new payments blocked reconciliation of an existing transaction.');

$uncertainOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'comgate');
$attempts = 0;
$brokenClient = new ComgateApiClient($settings['merchant'], $settings['secret'],
    static function () use (&$attempts): array {
        $attempts++;
        throw new RuntimeException('Simulated timeout after provider request.');
    });
$brokenPayments = new ComgatePaymentService($db, $settings, $brokenClient);
foreach ([1, 2] as $iteration) {
    $failed = false;
    try {
        $brokenPayments->initiate($uncertainOrder);
    } catch (RuntimeException $expected) {
        $failed = true;
    }
    expectComgate($failed && $attempts === 1,
        'Ambiguous provider request was retried or unexpectedly succeeded.');
}
expectComgate($orders->findById((int) $uncertainOrder['id'])['payment_status'] === 'pending',
    'Ambiguous request changed the order to paid.');

$cancelOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'comgate');
$retryIds = [
    'CZ-CANCEL-' . strtoupper(bin2hex(random_bytes(5))),
    'CZ-RETRY-' . strtoupper(bin2hex(random_bytes(5))),
];
$retryCalls = 0;
$retryClient = new ComgateApiClient($settings['merchant'], $settings['secret'],
    static function (
        string $method, string $url, ?array $body, string $merchant, string $secret
    ) use (
        &$retryCalls, $retryIds, $cancelOrder
    ): array {
        if ($method === 'POST') {
            $id = $retryIds[$retryCalls++] ?? null;
            if ($id === null) throw new RuntimeException('More than two retry transactions created.');
            return ['code' => 0, 'transId' => $id,
                'redirect' => 'https://payments.comgate.cz/payment/' . $id];
        }
        if ($method === 'GET') {
            return ['code' => 0, 'transId' => $retryIds[0], 'status' => 'CANCELLED',
                'test' => true, 'price' => 107900, 'curr' => 'CZK',
                'refId' => $cancelOrder['order_number']];
        }
        throw new RuntimeException('Unexpected retry transport method.');
    });
$retryPayments = new ComgatePaymentService($db, $settings, $retryClient);
$retryPayments->initiate($cancelOrder);
$retryPayments->refresh($cancelOrder);
expectComgate($retryPayments->state((int) $cancelOrder['id'])['status'] === 'cancelled' &&
    $orders->findById((int) $cancelOrder['id'])['payment_status'] === 'pending',
    'Cancelled payment incorrectly cancelled the order.');
$retryPayments->initiate($cancelOrder);
expectComgate($retryCalls === 2 &&
    $retryPayments->state((int) $cancelOrder['id'])['trans_id'] === $retryIds[1] &&
    $orders->findById((int) $cancelOrder['id'])['provider_reference'] === $retryIds[1],
    'A confirmed cancellation could not be retried with a new transaction.');

// A stale provider response can change after its locally terminal cancellation.
// Record a late charge once, without reopening the cancelled order or mailing a receipt.
$lateCancelOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'comgate');
$lateCancelId = 'CZ-CANCEL-LATE-' . strtoupper(bin2hex(random_bytes(5)));
$lateCancelState = 'PENDING';
$lateCancelClient = new ComgateApiClient($settings['merchant'], $settings['secret'],
    static function (string $method) use ($lateCancelId, $lateCancelOrder, &$lateCancelState): array {
        if ($method === 'POST') return ['code' => 0, 'transId' => $lateCancelId,
            'redirect' => 'https://payments.comgate.cz/payment/' . $lateCancelId];
        if ($method === 'GET') return ['code' => 0, 'transId' => $lateCancelId,
            'status' => $lateCancelState, 'test' => true, 'price' => 107900,
            'curr' => 'CZK', 'refId' => $lateCancelOrder['order_number']];
        throw new RuntimeException('Unexpected late-cancellation operation.');
    });
$lateCancelPayments = new ComgatePaymentService($db, $settings, $lateCancelClient);
$lateCancelPayments->initiate($lateCancelOrder);
$activeCancellationRejected = false;
try {
    $orders->setFulfillmentStatus((int) $lateCancelOrder['id'], 'cancelled');
} catch (InvalidArgumentException $expected) {
    $activeCancellationRejected = true;
}
expectComgate($activeCancellationRejected, 'An active Comgate attempt was cancelled and released stock.');
$lateCancelState = 'CANCELLED';
$lateCancelPayments->refresh($lateCancelOrder);
$orders->setFulfillmentStatus((int) $lateCancelOrder['id'], 'cancelled');
$lateCancelState = 'PAID';
$lateCancelPayments->notify([
    'transId' => $lateCancelId, 'merchant' => $settings['merchant'],
    'secret' => $settings['secret'], 'test' => true,
    'price' => 107900, 'curr' => 'CZK', 'refId' => $lateCancelOrder['order_number'],
]);
$lateCancelPayments->notify([
    'transId' => $lateCancelId, 'merchant' => $settings['merchant'],
    'secret' => $settings['secret'], 'test' => true,
    'price' => 107900, 'curr' => 'CZK', 'refId' => $lateCancelOrder['order_number'],
]);
$lateCancelled = $orders->findById((int) $lateCancelOrder['id']);
expectComgate($lateCancelled['status'] === 'cancelled' && $lateCancelled['payment_status'] === 'paid' &&
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_order_financial_events
        WHERE order_id=%i AND action=%s', $lateCancelOrder['id'], 'provider_payment_after_cancel') === 1,
    'Late Comgate settlement after cancellation was lost or recorded twice.');

// Removing an order cannot make a later, authenticated payment disappear.
$deletedOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'comgate');
$deletedTransId = 'CZ-LATE-' . strtoupper(bin2hex(random_bytes(6)));
$latePrice = 107900;
$deletedClient = new ComgateApiClient($settings['merchant'], $settings['secret'],
    static function (string $method, string $url, ?array $body) use (
        $deletedTransId, $deletedOrder, &$latePrice
    ): array {
        if ($method === 'POST') return ['code' => 0, 'transId' => $deletedTransId,
            'redirect' => 'https://payments.comgate.cz/payment/' . $deletedTransId];
        if ($method === 'GET') return ['code' => 0, 'transId' => $deletedTransId,
            'status' => 'PAID', 'test' => true, 'price' => $latePrice,
            'curr' => 'CZK', 'refId' => $deletedOrder['order_number']];
        throw new RuntimeException('Unexpected detached payment transport method.');
    });
$deletedPayments = new ComgatePaymentService($db, $settings, $deletedClient);
$deletedPayments->initiate($deletedOrder);
$db->query('UPDATE shop_comgate_payments SET order_number=%s, total_czk=%i, order_id=NULL WHERE order_id=%i',
    $deletedOrder['order_number'], $deletedOrder['total_czk'], $deletedOrder['id']);
$db->query('DELETE FROM shop_orders WHERE id=%i', $deletedOrder['id']);
$deletedNotification = [
    'transId' => $deletedTransId, 'merchant' => $settings['merchant'],
    'secret' => $settings['secret'], 'test' => true, 'price' => 107900,
    'curr' => 'CZK', 'refId' => $deletedOrder['order_number'],
];
$latePrice = 107800;
$mismatchRejected = false;
try {
    $deletedPayments->notify($deletedNotification);
} catch (RuntimeException $expected) {
    $mismatchRejected = true;
}
expectComgate($mismatchRejected && (string) $db->queryFirstField(
    'SELECT status FROM shop_comgate_payments WHERE trans_id=%s', $deletedTransId
) === 'pending', 'A detached Comgate payment accepted a different verified amount.');
$latePrice = 107900;
$deletedPayments->notify($deletedNotification);
$deletedPayments->notify($deletedNotification);
expectComgate($orders->findById((int) $deletedOrder['id']) === null &&
    (string) $db->queryFirstField('SELECT status FROM shop_comgate_payments WHERE trans_id=%s',
        $deletedTransId) === 'paid' &&
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_order_financial_events
        WHERE order_number=%s AND action=%s', $deletedOrder['order_number'],
        'provider_payment_after_delete') === 1,
    'A verified late Comgate payment was lost, duplicated or recreated the order.');

echo "Comgate payment database integration passed.\n";
