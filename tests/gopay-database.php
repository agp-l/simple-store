<?php
declare(strict_types=1);

// CI-only integration check: a fake GoPay transport with real MySQL persistence.
require dirname(__DIR__) . '/vendor/autoload.php';

if (!class_exists(\GoPay\Api::class) || !method_exists(\GoPay\Api::class, 'payments')) {
    throw new RuntimeException('Official GoPay SDK is missing from the Composer installation.');
}

use SimpleStore\Checkout\GoPayApiClient;
use SimpleStore\Checkout\GoPayPaymentService;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Checkout\GoPayPaidOrderGuard;
use SimpleStore\Checkout\CarrierShipmentDraft;
use SimpleStore\Checkout\CarrierShipmentRepository;
use SimpleStore\Accounting\InvoiceRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

function expectGoPay(bool $condition, string $message): void
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
    'enabled' => true,
    'test' => true,
    'goid' => '8123456789',
    'client_id' => 'fake-client-id',
    'client_secret' => 'fake-client-secret',
    'return_base_url' => 'https://shop.example.test/store',
];
$paymentId = (string) random_int(100000000000, 999999999999);
$redirect = 'https://gw.sandbox.gopay.com/gw/v3/' . $paymentId;
$providerState = 'CREATED';
$statusOverride = [];
$calls = [];
$reference = '';
$transport = static function (
    string $operation,
    $argument,
    string $goid,
    string $clientId,
    string $clientSecret,
    bool $test
) use (&$calls, &$providerState, &$statusOverride, &$reference, $paymentId, $redirect): array {
    $calls[] = [$operation, $argument, $goid, $clientId, $clientSecret, $test];
    if ($operation === 'create') {
        $reference = (string) ($argument['order_number'] ?? '');
        return ['id' => $paymentId, 'gw_url' => $redirect, 'state' => 'CREATED',
            'amount' => $argument['amount'], 'currency' => $argument['currency'],
            'order_number' => $reference, 'target' => $argument['target']];
    }
    if ($operation === 'status') {
        return array_replace_recursive([
            'id' => $paymentId,
            'state' => $providerState,
            'amount' => 107900,
            'currency' => 'CZK',
            'order_number' => $reference,
            'target' => ['type' => 'ACCOUNT', 'goid' => '8123456789'],
        ], $statusOverride);
    }
    throw new RuntimeException('Unexpected GoPay transport operation.');
};

$client = new GoPayApiClient(
    $settings['goid'], $settings['client_id'], $settings['client_secret'], true, $transport
);
$payments = new GoPayPaymentService($db, $settings, $client);
expectGoPay($payments->installed() && $payments->canInitiate(),
    'GoPay payment table was not installed or configured.');

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
$orders = new OrderRepository($db);
$order = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'gopay');
expectGoPay((int) $order['total_czk'] === 1079 && $order['payment_method'] === 'gopay' &&
    $order['payment_status'] === 'pending' && $order['payment_details'] === [] &&
    $order['payment_due_at'] === null,
    'GoPay order saved an incorrect amount or bank-transfer details.');
expectGoPay($payments->receiptUrl($order, 'cs') ===
    'https://shop.example.test/store/cs/objednavka/' . $order['order_token'],
    'The order confirmation cannot link to its private receipt.');

expectGoPay($payments->initiate($order) === $redirect, 'The gateway redirect was not returned.');
expectGoPay(count($calls) === 1 && $calls[0][0] === 'create' &&
    $calls[0][2] === '8123456789' && $calls[0][3] === 'fake-client-id' &&
    $calls[0][4] === 'fake-client-secret' && $calls[0][5] === true &&
    ($calls[0][1]['amount'] ?? null) === 107900 &&
    ($calls[0][1]['currency'] ?? null) === 'CZK' &&
    ($calls[0][1]['order_number'] ?? null) === $order['order_number'] &&
    (string) ($calls[0][1]['target']['goid'] ?? '') === '8123456789',
    'GoPay request lost credentials, payment amount, currency, reference or target.');
expectGoPay($payments->initiate($order) === $redirect && count($calls) === 1,
    'Repeated checkout created another provider payment.');
expectGoPay($orders->findById((int) $order['id'])['provider_reference'] === $paymentId &&
    $payments->state((int) $order['id']) !== null,
    'Provider payment ID was not stored with the order.');

// An arbitrary callback ID and a browser redirect carry no proof of payment.
$rejected = false;
try {
    $payments->notify(str_repeat('9', 30));
} catch (Throwable $expected) {
    $rejected = true;
}
expectGoPay($rejected && count($calls) === 1 &&
    $orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'A foreign callback ID was accepted or queried the provider.');
$payments->notify($paymentId);
expectGoPay(count($calls) === 2 && $calls[1][0] === 'status' &&
    $calls[1][1] === $paymentId &&
    $orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'An unverified callback marked an unpaid GoPay transaction as paid.');

// AUTHORIZED is not a captured payment, even if a provider adds this state later.
$providerState = 'AUTHORIZED';
try {
    $payments->refresh($order);
} catch (Throwable $expected) {
    // Rejecting a previously unknown provider state is also safe.
}
expectGoPay($orders->findById((int) $order['id'])['payment_status'] === 'pending',
    'An authorization was incorrectly treated as a captured payment.');

$providerState = 'PAID';
foreach ([
    ['amount' => 107800],
    ['currency' => 'EUR'],
    ['order_number' => 'ANOTHER-ORDER'],
    ['id' => '999999999999'],
    ['target' => ['goid' => '9999999999']],
] as $invalid) {
    $statusOverride = $invalid;
    try {
        $payments->notify($paymentId);
    } catch (Throwable $expected) {
        // The status response must fail closed.
    }
    expectGoPay($orders->findById((int) $order['id'])['payment_status'] === 'pending',
        'A mismatched GoPay status was accepted as a payment.');
}
$statusOverride = [];
$payments->notify($paymentId);
expectGoPay($orders->findById((int) $order['id'])['payment_status'] === 'paid',
    'A verified GoPay payment did not settle the order.');
$returnToken = (string) $db->queryFirstField(
    'SELECT return_token FROM shop_gopay_payments WHERE order_id=%i LIMIT 1', (int) $order['id']
);
expectGoPay($payments->returnOrder(str_repeat('f', 64), $paymentId) === null &&
    $payments->returnOrder($returnToken, str_repeat('9', 30)) === null &&
    (int) $payments->returnOrder($returnToken, $paymentId)['id'] === (int) $order['id'],
    'The private browser return did not bind its token to the original payment.');
$seller = [
    'name' => 'Prodejce OSVČ', 'ico' => '12345678', 'street' => 'Test 1',
    'city' => 'Praha', 'postal_code' => '11000', 'bank_account' => '',
];
$buyer = ['name' => 'Eva Nová', 'street' => 'Polní 1', 'city' => 'Praha',
    'postal_code' => '11000', 'ico' => ''];
$invoices = new InvoiceRepository($db);
$invoice = $invoices->issue((int) $order['id'], $seller, $buyer);
expectGoPay($invoice['payment_method'] === 'gopay' && (int) $invoice['total_czk'] === 1079,
    'A paid GoPay order did not produce a correctly labeled invoice.');
$guard = new GoPayPaidOrderGuard($db);
$guard->assertPaid((int) $order['id'], $paymentId);
$carrier = new CarrierShipmentRepository($db);
$draft = CarrierShipmentDraft::fromOrder($orders->findById((int) $order['id']), [
    'recipient' => 'Eva Nová', 'email' => 'buyer@example.test',
    'phone' => '+420777111222', 'weight_kg' => '1.2',
    'first_name' => 'Eva', 'surname' => 'Nová', 'street' => 'Polní',
    'house_number' => '1', 'city' => 'Praha', 'postal_code' => '11000',
]);
$carrier->save((int) $order['id'], 1, $draft);
$orders->setFulfillmentStatus((int) $order['id'], 'ready_to_ship');
expectGoPay($orders->findById((int) $order['id'])['status'] === 'ready_to_ship' &&
    $carrier->find((int) $order['id'])['status'] === 'draft',
    'A fully paid GoPay order could not prepare GLS shipping.');

// Keep the historical payment date, but block fulfillment when the charge is refunded.
foreach (['PARTIALLY_REFUNDED', 'REFUNDED'] as $refundedState) {
    $providerState = $refundedState;
    $payments->notify($paymentId);
    $guardBlocked = false;
    $draftBlocked = false;
    $shippingBlocked = false;
    try {
        $guard->assertPaid((int) $order['id'], $paymentId);
    } catch (InvalidArgumentException $expected) {
        $guardBlocked = true;
    }
    try {
        $carrier->save((int) $order['id'], 1, $draft);
    } catch (InvalidArgumentException $expected) {
        $draftBlocked = true;
    }
    try {
        $orders->setFulfillmentStatus((int) $order['id'], 'shipped');
    } catch (InvalidArgumentException $expected) {
        $shippingBlocked = true;
    }
    expectGoPay($guardBlocked && $draftBlocked && $shippingBlocked &&
        $payments->state((int) $order['id'])['status'] === strtolower($refundedState) &&
        $orders->findById((int) $order['id'])['status'] === 'ready_to_ship',
        $refundedState . ' GoPay charge was allowed into GLS shipping or fulfillment.');
    $providerState = 'PAID'; // Delayed older responses must not reopen either refund state.
    $payments->notify($paymentId);
    expectGoPay($payments->state((int) $order['id'])['status'] === strtolower($refundedState),
        'A stale PAID response reopened a ' . $refundedState . ' GoPay charge.');
}
$registrationBlocked = false;
try {
    $carrier->register((int) $order['id'], 1, 'GLS123456');
} catch (InvalidArgumentException $expected) {
    $registrationBlocked = true;
}
expectGoPay($registrationBlocked && $carrier->find((int) $order['id'])['status'] === 'draft',
    'A fully refunded GoPay charge was allowed to register the prepared parcel.');
$paidAt = $orders->findById((int) $order['id'])['payment_paid_at'];
$payments->notify($paymentId);
expectGoPay($orders->findById((int) $order['id'])['payment_paid_at'] === $paidAt,
    'A repeated GoPay callback changed the original settlement time.');
$providerState = 'CANCELED';
$payments->notify($paymentId);
expectGoPay($orders->findById((int) $order['id'])['payment_status'] === 'paid' &&
    $orders->findById((int) $order['id'])['payment_paid_at'] === $paidAt,
    'A delayed cancellation downgraded a verified payment.');
$disabled = new GoPayPaymentService($db, array_replace($settings, ['enabled' => false]), $client);
expectGoPay(!$disabled->canInitiate() &&
    $disabled->refresh($orders->findById((int) $order['id']))['payment_status'] === 'paid',
    'Disabling new GoPay payments blocked reconciliation of existing transactions.');

$invoiceRefundOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'gopay');
$invoiceRefundId = (string) random_int(100000000000, 999999999999);
$invoiceRefundState = 'PAID';
$invoiceRefundClient = new GoPayApiClient('8123456789', 'fake-client-id', 'fake-client-secret', true,
    static function (string $operation, $argument) use (
        $invoiceRefundId, $invoiceRefundOrder, &$invoiceRefundState
    ): array {
        if ($operation === 'create') {
            return ['id' => $invoiceRefundId,
                'gw_url' => 'https://gw.sandbox.gopay.com/gw/v3/' . $invoiceRefundId,
                'state' => 'CREATED', 'amount' => $argument['amount'],
                'currency' => $argument['currency'],
                'order_number' => $argument['order_number'], 'target' => $argument['target']];
        }
        if ($operation === 'status') {
            return ['id' => $invoiceRefundId, 'state' => $invoiceRefundState,
                'amount' => 107900, 'currency' => 'CZK',
                'order_number' => $invoiceRefundOrder['order_number'],
                'target' => ['type' => 'ACCOUNT', 'goid' => '8123456789']];
        }
        throw new RuntimeException('Unexpected invoice refund operation.');
    });
$invoiceRefundPayments = new GoPayPaymentService($db, $settings, $invoiceRefundClient);
$invoiceRefundPayments->initiate($invoiceRefundOrder);
$invoiceRefundPayments->notify($invoiceRefundId);
$invoiceRefundState = 'REFUNDED';
$invoiceRefundPayments->notify($invoiceRefundId);
$invoiceRejected = false;
try {
    $invoices->issue((int) $invoiceRefundOrder['id'], $seller, $buyer);
} catch (InvalidArgumentException $expected) {
    $invoiceRejected = true;
}
expectGoPay($invoiceRejected && $invoices->byOrder((int) $invoiceRefundOrder['id']) === null &&
    $orders->findById((int) $invoiceRefundOrder['id'])['payment_status'] === 'paid',
    'A new invoice was issued for a GoPay payment already refunded in full.');

$refundOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'gopay');
$refundId = (string) random_int(100000000000, 999999999999);
$refundClient = new GoPayApiClient('8123456789', 'fake-client-id', 'fake-client-secret', true,
    static function (string $operation, $argument) use ($refundId, $refundOrder): array {
        if ($operation === 'create') {
            return ['id' => $refundId,
                'gw_url' => 'https://gw.sandbox.gopay.com/gw/v3/' . $refundId,
                'state' => 'CREATED', 'amount' => $argument['amount'],
                'currency' => $argument['currency'],
                'order_number' => $argument['order_number'], 'target' => $argument['target']];
        }
        if ($operation === 'status') {
            return ['id' => $refundId, 'state' => 'REFUNDED', 'amount' => 107900,
                'currency' => 'CZK', 'order_number' => $refundOrder['order_number'],
                'target' => ['type' => 'ACCOUNT', 'goid' => '8123456789']];
        }
        throw new RuntimeException('Unexpected refund operation.');
    });
$refundPayments = new GoPayPaymentService($db, $settings, $refundClient);
$refundPayments->initiate($refundOrder);
$refundPayments->notify($refundId);
expectGoPay($orders->findById((int) $refundOrder['id'])['payment_status'] === 'pending',
    'A refund without any verified PAID transition incorrectly settled the order.');

$uncertainOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'gopay');
$attempts = 0;
$brokenClient = new GoPayApiClient('8123456789', 'fake-client-id', 'fake-client-secret', true,
    static function () use (&$attempts): array {
        $attempts++;
        throw new RuntimeException('Simulated timeout after provider request.');
    });
$brokenPayments = new GoPayPaymentService($db, $settings, $brokenClient);
foreach ([1, 2] as $iteration) {
    $failed = false;
    try {
        $brokenPayments->initiate($uncertainOrder);
    } catch (Throwable $expected) {
        $failed = true;
    }
    expectGoPay($failed && $attempts === 1,
        'An ambiguous create call was retried and could have charged the customer twice.');
}

$cancelOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'gopay');
$firstRetryId = random_int(100000000000, 999999999998);
$retryIds = [(string) $firstRetryId, (string) ($firstRetryId + 1)];
$retryStates = [$retryIds[0] => 'CANCELED', $retryIds[1] => 'CREATED'];
$retryCalls = 0;
$retryClient = new GoPayApiClient('8123456789', 'fake-client-id', 'fake-client-secret', true,
    static function (string $operation, $argument) use (
        &$retryCalls, &$retryStates, $retryIds, $cancelOrder
    ): array {
        if ($operation === 'create') {
            $id = $retryIds[$retryCalls++] ?? null;
            if ($id === null) throw new RuntimeException('Too many retry attempts.');
            return ['id' => $id, 'gw_url' => 'https://gw.sandbox.gopay.com/gw/v3/' . $id,
                'state' => 'CREATED', 'amount' => $argument['amount'],
                'currency' => $argument['currency'],
                'order_number' => $argument['order_number'], 'target' => $argument['target']];
        }
        if ($operation === 'status') {
            return ['id' => $argument, 'state' => $retryStates[$argument] ?? 'CREATED',
                'amount' => 107900,
                'currency' => 'CZK', 'order_number' => $cancelOrder['order_number'],
                'target' => ['type' => 'ACCOUNT', 'goid' => '8123456789']];
        }
        throw new RuntimeException('Unexpected retry operation.');
    });
$retryPayments = new GoPayPaymentService($db, $settings, $retryClient);
$retryPayments->initiate($cancelOrder);
$retryPayments->refresh($cancelOrder);
expectGoPay($orders->findById((int) $cancelOrder['id'])['payment_status'] === 'pending',
    'A canceled gateway attempt canceled the order.');
$retryPayments->initiate($cancelOrder);
expectGoPay($retryCalls === 2 &&
    $orders->findById((int) $cancelOrder['id'])['provider_reference'] === $retryIds[1],
    'A canceled payment could not be retried with a new transaction.');
$retryStates[$retryIds[0]] = 'PAID';
$retryPayments->notify($retryIds[0]);
expectGoPay($orders->findById((int) $cancelOrder['id'])['payment_status'] === 'paid' &&
    $orders->findById((int) $cancelOrder['id'])['provider_reference'] === $retryIds[0],
    'A late confirmed payment on an older attempt was lost.');
$retryStates[$retryIds[1]] = 'PAID';
$doubleChargeDetected = false;
try {
    $retryPayments->notify($retryIds[1]);
} catch (RuntimeException $expected) {
    $doubleChargeDetected = true;
}
expectGoPay($doubleChargeDetected &&
    $orders->findById((int) $cancelOrder['id'])['provider_reference'] === $retryIds[0],
    'Two paid attempts on the same order were silently reconciled as a single charge.');

// Provider state remains verifiable after an admin removes the original order.
$deletedOrder = $orders->create(null, 'buyer@example.test', $items, $shipping, 79,
    bin2hex(random_bytes(32)), 'gopay');
$deletedPaymentId = (string) random_int(100000000000, 999999999999);
$lateState = 'PAID';
$lateAmount = 107900;
$deletedClient = new GoPayApiClient('8123456789', 'fake-client-id', 'fake-client-secret', true,
    static function (string $operation, $argument) use (
        $deletedPaymentId, $deletedOrder, &$lateState, &$lateAmount
    ): array {
        if ($operation === 'create') return ['id' => $deletedPaymentId,
            'gw_url' => 'https://gw.sandbox.gopay.com/gw/v3/' . $deletedPaymentId,
            'state' => 'CREATED', 'amount' => $argument['amount'],
            'currency' => $argument['currency'], 'order_number' => $argument['order_number'],
            'target' => $argument['target']];
        if ($operation === 'status') return ['id' => $deletedPaymentId,
            'state' => $lateState, 'amount' => $lateAmount, 'currency' => 'CZK',
            'order_number' => $deletedOrder['order_number'],
            'target' => ['type' => 'ACCOUNT', 'goid' => '8123456789']];
        throw new RuntimeException('Unexpected detached payment transport operation.');
    });
$deletedPayments = new GoPayPaymentService($db, $settings, $deletedClient);
$deletedPayments->initiate($deletedOrder);
$db->query('UPDATE shop_gopay_payments SET order_number=%s, total_czk=%i, order_id=NULL WHERE order_id=%i',
    $deletedOrder['order_number'], $deletedOrder['total_czk'], $deletedOrder['id']);
$db->query('DELETE FROM shop_orders WHERE id=%i', $deletedOrder['id']);
$lateAmount = 107800;
$mismatchRejected = false;
try {
    $deletedPayments->notify($deletedPaymentId);
} catch (RuntimeException $expected) {
    $mismatchRejected = true;
}
expectGoPay($mismatchRejected && (string) $db->queryFirstField(
    'SELECT status FROM shop_gopay_payments WHERE payment_id=%s', $deletedPaymentId
) === 'created', 'A detached GoPay payment accepted a different verified amount.');
$lateAmount = 107900;
foreach (['PAID', 'PAID', 'PARTIALLY_REFUNDED', 'PARTIALLY_REFUNDED', 'REFUNDED', 'PAID'] as $lateState) {
    $deletedPayments->notify($deletedPaymentId);
}
expectGoPay($orders->findById((int) $deletedOrder['id']) === null &&
    (string) $db->queryFirstField('SELECT status FROM shop_gopay_payments WHERE payment_id=%s',
        $deletedPaymentId) === 'refunded' &&
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_order_financial_events
        WHERE order_number=%s AND action IN (%s,%s,%s)', $deletedOrder['order_number'],
        'provider_payment_after_delete', 'provider_partial_refund_deleted',
        'provider_refund_after_delete') === 3,
    'Late GoPay settlement/refunds were lost, duplicated or recreated the order.');

echo "GoPay payment database integration passed.\n";
