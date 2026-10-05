<?php
declare(strict_types=1);

// Exercise the Greenfield adapter without a BTCPay account, Composer, or SQL.
require dirname(__DIR__) . '/src/Checkout/BTCPayApiRejectedException.php';
require dirname(__DIR__) . '/src/Checkout/BTCPayApiClient.php';

use SimpleStore\Checkout\BTCPayApiClient;
use SimpleStore\Checkout\BTCPayApiRejectedException;

function expectBTCPayClient(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$requests = [];
$code = 200;
$body = '{"id":"Abc123","status":"New"}';
$transport = static function (string $method, string $url, array $headers, ?string $json) use (
    &$requests, &$code, &$body
): array {
    $requests[] = [$method, $url, $headers, $json];
    return ['status' => $code, 'body' => $body];
};
$client = new BTCPayApiClient('https://pay.example.test/shop/', 'Store_123', 'test-api-key', $transport);
$created = $client->create(['amount' => '1079.00', 'currency' => 'CZK',
    'metadata' => ['orderId' => 'DB-123']]);
expectBTCPayClient(($created['id'] ?? null) === 'Abc123' && count($requests) === 1 &&
    $requests[0][0] === 'POST' &&
    $requests[0][1] === 'https://pay.example.test/shop/api/v1/stores/Store_123/invoices' &&
    in_array('Authorization: token test-api-key', $requests[0][2], true) &&
    json_decode((string) $requests[0][3], true)['metadata']['orderId'] === 'DB-123',
    'BTCPay create used the wrong endpoint, payload, or scoped API key.');
$client->status('Abc123');
expectBTCPayClient(count($requests) === 2 && $requests[1][0] === 'GET' &&
    $requests[1][1] === 'https://pay.example.test/shop/api/v1/stores/Store_123/invoices/Abc123' &&
    $requests[1][3] === null,
    'BTCPay invoice status did not use the Greenfield invoice endpoint.');

$invalidIdRejected = false;
try {
    $client->status('../other-store');
} catch (RuntimeException $expected) {
    $invalidIdRejected = true;
}
expectBTCPayClient($invalidIdRejected && count($requests) === 2,
    'An invalid invoice ID reached the HTTP transport.');

foreach (['http://public.example.test', 'https://user@pay.example.test',
    'https://pay.example.test/path?token=x', 'https://pay.example.test/#fragment'] as $unsafeUrl) {
    $rejected = false;
    try {
        new BTCPayApiClient($unsafeUrl, 'Store_123', 'test-api-key', $transport);
    } catch (RuntimeException $expected) {
        $rejected = true;
    }
    expectBTCPayClient($rejected, 'An unsafe BTCPay server URL was accepted: ' . $unsafeUrl);
}

$code = 400;
$body = '{"error":"provider echoes secret test-api-key"}';
$rejected = false;
try {
    $client->create(['amount' => '1.00']);
} catch (BTCPayApiRejectedException $expected) {
    $rejected = !str_contains($expected->getMessage(), 'test-api-key');
}
expectBTCPayClient($rejected, 'Definite 400 rejection is not distinguishable or leaks credentials.');

$code = 503;
$uncertain = false;
try {
    $client->create(['amount' => '1.00']);
} catch (RuntimeException $expected) {
    $uncertain = !$expected instanceof BTCPayApiRejectedException &&
        !str_contains($expected->getMessage(), 'test-api-key');
}
expectBTCPayClient($uncertain, 'Uncertain server failure was treated as a definite rejection.');

$code = 200;
$body = '{not-json';
$malformedRejected = false;
try {
    $client->status('Abc123');
} catch (RuntimeException $expected) {
    $malformedRejected = true;
}
expectBTCPayClient($malformedRejected, 'Malformed invoice JSON was accepted.');

$body = '[{"id":"Abc123"}]';
$listRejected = false;
try {
    $client->status('Abc123');
} catch (RuntimeException $expected) {
    $listRejected = true;
}
expectBTCPayClient($listRejected, 'An invoice list was accepted in place of one invoice object.');

echo "BTCPay API client passed.\n";
