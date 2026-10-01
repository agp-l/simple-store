<?php
declare(strict_types=1);

// The webhook must authenticate the exact HTTP body before parsing its JSON.
require dirname(__DIR__) . '/src/Checkout/BTCPayPaymentService.php';

use SimpleStore\Checkout\BTCPayPaymentService;

function expectBTCPaySignature(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$secret = 'test-webhook-secret';
$raw = '{"type":"InvoiceSettled","invoiceId":"test-invoice","amount":"1079.00"}';
$signature = 'sha256=' . hash_hmac('sha256', $raw, $secret);
expectBTCPaySignature(BTCPayPaymentService::verifySignature($raw, $signature, $secret),
    'A valid BTCPay signature was rejected.');
foreach ([
    [$raw . ' ', $signature, $secret],
    [str_replace('1079.00', '1078.00', $raw), $signature, $secret],
    [$raw, $signature, 'different-webhook-secret'],
    [$raw, 'sha256=' . str_repeat('0', 64), $secret],
    [$raw, 'sha256=' . substr($signature, 7, 63), $secret],
    [$raw, '', $secret],
] as [$body, $header, $webhookSecret]) {
    expectBTCPaySignature(!BTCPayPaymentService::verifySignature($body, $header, $webhookSecret),
        'An invalid or replayed BTCPay webhook signature was accepted.');
}

echo "BTCPay webhook signature verification passed.\n";
