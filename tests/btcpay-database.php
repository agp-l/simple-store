<?php
declare(strict_types=1);

// The BTCPay HTTP transport is fake; invoice persistence and reconciliation use real MySQL.
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Checkout\BTCPayApiClient;
use SimpleStore\Checkout\BTCPayPaymentService;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

function expectBTCPay(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = ConnectionFactory::create([
    'host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$settings = [
    'enabled' => true,
    'server_url' => 'https://pay.example.test',
    'store_id' => 'test-store',
    'api_key' => 'test-api-key',
    'webhook_secret' => 'test-webhook-secret',
    'return_base_url' => 'https://shop.example.test/store',
];
$invoiceId = bin2hex(random_bytes(11));
$checkoutLink = $settings['server_url'] . '/i/' . $invoiceId;
$status = 'New';
$statusOverride = [];
$createOverride = [];
$requests = [];
$reference = '';
$transport = static function (string $method, string $url, array $headers, ?string $body) use (
    &$requests, &$reference, &$status, &$statusOverride, &$createOverride,
    $invoiceId, $checkoutLink, $settings
): array {
    $requests[] = [$method, $url, $headers, $body];
    if ($method === 'POST') {
        $payload = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        $reference = (string) ($payload['metadata']['orderId'] ?? '');
        $response = array_replace_recursive([
            'id' => $invoiceId,
            'storeId' => $settings['store_id'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'status' => 'New',
            'additionalStatus' => 'None',
            'metadata' => ['orderId' => $reference],
            'checkoutLink' => $checkoutLink,
        ], $createOverride);
        return ['status' => 200, 'body' => json_encode($response, JSON_THROW_ON_ERROR)];
    }
    if ($method === 'GET') {
        $response = array_replace_recursive([
            'id' => $invoiceId,
            'storeId' => $settings['store_id'],
            'amount' => '1079.00',
            'currency' => 'CZK',
            'status' => $status,
            'additionalStatus' => 'None',
            'metadata' => ['orderId' => $reference],
        ], $statusOverride);
        return ['status' => 200, 'body' => json_encode($response, JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException('Unexpected BTCPay transport operation.');
};
$client = new BTCPayApiClient($settings['server_url'], $settings['store_id'], $settings['api_key'], $transport);
$payments = new BTCPayPaymentService($db, $settings, $client);
expectBTCPay($payments->installed() && $payments->canInitiate(),
    'BTCPay table was not installed or payments are unavailable.');

$orders = new OrderRepository($db);
$items = [[
    'product_key' => str_repeat('a', 32), 'language' => 'cs', 'slug' => 'stan',
    'name' => 'Stan', 'quantity' => 2, 'unit_price_czk' => 500,
    'image_path' => '', 'options' => [],
]];
$shipping = [
    'method' => 'gls_home', 'label' => 'GLS domů', 'name' => 'Eva Nová',
    'phone' => '+420777111222', 'street' => 'Polní 1', 'city' => 'Praha',
    'postal_code' => '11000', 'country' => 'CZ',
];
$order = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), false, 'btcpay');
expectBTCPay((int) $order['total_czk'] === 1079 && $order['payment_status'] === 'pending' &&
    $order['payment_method'] === 'btcpay' && $order['payment_details'] === [] &&
    $order['payment_due_at'] === null,
    'BTCPay order has an incorrect method, amount or bank transfer details.');
expectBTCPay($payments->receiptUrl($order, 'cs') ===
    'https://shop.example.test/store/cs/objednavka/' . $order['order_token'],
    'The customer receipt link did not use the configured canonical shop URL.');

expectBTCPay($payments->initiate($order) === $checkoutLink,
    'BTCPay checkout link was not returned.');
expectBTCPay(count($requests) === 1 && $requests[0][0] === 'POST' &&
    str_ends_with($requests[0][1], '/api/v1/stores/test-store/invoices'),
    'Invoice creation did not use the scoped Greenfield endpoint.');
$sent = json_decode((string) $requests[0][3], true, 512, JSON_THROW_ON_ERROR);
expectBTCPay(is_array($sent) && ($sent['currency'] ?? null) === 'CZK' &&
    preg_match('/^1079(?:\.0{1,2})?$/D', (string) ($sent['amount'] ?? '')) === 1 &&
    ($sent['metadata']['orderId'] ?? null) === $order['order_number'] &&
    str_contains(json_encode($requests[0][2]), 'test-api-key'),
    'Invoice request lost the exact CZK total, private API key or order reference.');
expectBTCPay($payments->initiate($order) === $checkoutLink && count($requests) === 1,
    'Repeated checkout created another BTCPay invoice.');
expectBTCPay($orders->findById((int) $order['id'])['provider_reference'] === $invoiceId &&
    $payments->state((int) $order['id']) !== null,
    'The BTCPay invoice was not linked to the order.');

// A guessed invoice ID, browser return, or unconfirmed invoice is never evidence of payment.
$unknownRejected = false;
try {
    $payments->notify(str_repeat('9', 22));
} catch (Throwable $expected) {
    $unknownRejected = true;
}
expectBTCPay($unknownRejected && count($requests) === 1 &&
    $orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'An unknown invoice ID caused an API lookup or changed the order.');
$returnToken = (string) $db->queryFirstField(
    'SELECT return_token FROM shop_btcpay_payments WHERE order_id=%i LIMIT 1', (int) $order['id']
);
expectBTCPay($payments->returnOrder(str_repeat('f', 64), $invoiceId) === null &&
    $payments->returnOrder($returnToken, str_repeat('9', 22)) === null,
    'The browser return token was not bound to the original invoice.');
expectBTCPay((int) $payments->returnOrder($returnToken, $invoiceId)['id'] === (int) $order['id'] &&
    $orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'The pending browser return did not load the private receipt safely.');
$status = 'Processing';
$payments->notify($invoiceId);
expectBTCPay($orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'Unconfirmed Bitcoin transaction was treated as a settled payment.');
$status = 'Expired';
$statusOverride = ['additionalStatus' => 'PaidLate'];
$payments->notify($invoiceId);
expectBTCPay($orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'An expired invoice with a late payment was treated as settled.');

$status = 'Settled';
$statusOverride = ['additionalStatus' => 'PaidPartial'];
$partialRejected = false;
try {
    $payments->notify($invoiceId);
} catch (Throwable $expected) {
    $partialRejected = true;
}
expectBTCPay($partialRejected && $orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'A partially paid invoice was treated as fully settled.');
foreach ([
    ['amount' => '1078.00'],
    ['currency' => 'EUR'],
    ['metadata' => ['orderId' => 'OTHER-ORDER']],
    ['storeId' => 'other-store'],
    ['id' => str_repeat('b', 22)],
] as $invalid) {
    $statusOverride = $invalid;
    $rejected = false;
    try {
        $payments->notify($invoiceId);
    } catch (Throwable $expected) {
        $rejected = true;
    }
    expectBTCPay($rejected && $orders->findById((int) $order['id'])['payment_status'] === 'pending',
        'A mismatched BTCPay invoice was accepted as paid.');
}
$statusOverride = [];
$payments->notify($invoiceId);
expectBTCPay($orders->findById((int) $order['id'])['payment_status'] === 'paid' &&
    $payments->state((int) $order['id'])['status'] === 'settled',
    'An API-verified settled invoice did not pay the order.');
$paidAt = $orders->findById((int) $order['id'])['payment_paid_at'];
$payments->notify($invoiceId);
expectBTCPay($orders->findById((int) $order['id'])['payment_paid_at'] === $paidAt,
    'A replayed BTCPay settlement modified its original payment timestamp.');
$status = 'Expired';
$payments->notify($invoiceId);
expectBTCPay($orders->findById((int) $order['id'])['payment_status'] === 'paid' &&
    $orders->findById((int) $order['id'])['payment_paid_at'] === $paidAt,
    'A delayed invoice expiry downgraded a settled order.');
$disabled = new BTCPayPaymentService($db, array_replace($settings, ['enabled' => false]), $client);
expectBTCPay(!$disabled->canInitiate() &&
    $disabled->refresh($orders->findById((int) $order['id']))['payment_status'] === 'paid',
    'Disabling new BTCPay payments also disabled reconciliation of existing invoices.');

// A timed-out POST might have created an invoice at BTCPay; never silently retry it.
$uncertainOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), false, 'btcpay');
$createCalls = 0;
$brokenClient = new BTCPayApiClient($settings['server_url'], $settings['store_id'], $settings['api_key'],
    static function () use (&$createCalls): array {
        $createCalls++;
        throw new RuntimeException('Simulated timeout after invoice creation.');
    });
$brokenPayments = new BTCPayPaymentService($db, $settings, $brokenClient);
for ($attempt = 0; $attempt < 2; $attempt++) {
    $failed = false;
    try {
        $brokenPayments->initiate($uncertainOrder);
    } catch (Throwable $expected) {
        $failed = true;
    }
    expectBTCPay($failed && $createCalls === 1,
        'Ambiguous invoice creation was repeated and might charge the customer twice.');
}

// If an administrator removes an order, a later settlement must remain visible exactly once.
$detachedOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), false, 'btcpay');
$detachedInvoiceId = bin2hex(random_bytes(11));
$detachedReference = $detachedOrder['order_number'];
$detachedClient = new BTCPayApiClient($settings['server_url'], $settings['store_id'], $settings['api_key'],
    static function (string $method, string $url, array $headers, ?string $body) use (
        $detachedInvoiceId, $detachedReference, $settings
    ): array {
        $response = [
            'id' => $detachedInvoiceId, 'storeId' => $settings['store_id'],
            'amount' => '1079.00', 'currency' => 'CZK',
            'status' => $method === 'POST' ? 'New' : 'Settled',
            'additionalStatus' => 'None',
            'metadata' => ['orderId' => $detachedReference],
            'checkoutLink' => $settings['server_url'] . '/i/' . $detachedInvoiceId,
        ];
        return ['status' => 200, 'body' => json_encode($response, JSON_THROW_ON_ERROR)];
    });
$detachedPayments = new BTCPayPaymentService($db, $settings, $detachedClient);
$detachedPayments->initiate($detachedOrder);
$db->query('UPDATE shop_btcpay_payments SET order_id=NULL WHERE order_id=%i', $detachedOrder['id']);
$db->query('DELETE FROM shop_orders WHERE id=%i', $detachedOrder['id']);
$detachedPayments->notify($detachedInvoiceId);
$detachedPayments->notify($detachedInvoiceId);
expectBTCPay($orders->findById((int) $detachedOrder['id']) === null &&
    (string) $db->queryFirstField('SELECT status FROM shop_btcpay_payments WHERE invoice_id=%s',
        $detachedInvoiceId) === 'settled' &&
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_order_financial_events
        WHERE order_number=%s AND action=%s', $detachedReference, 'provider_payment_after_delete') === 1,
    'Late payment after order deletion was lost or recorded twice.');

echo "BTCPay payment database integration passed.\n";
