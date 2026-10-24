<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\FioStatementClient;
use SimpleStore\Checkout\FioTransferMatcher;
use SimpleStore\Checkout\BankTransferPayment;

$token = str_repeat('A', 64);
$calls = [];
$client = new FioStatementClient(static function (string $url) use (&$calls): array {
    $calls[] = $url;
    return [200, json_encode(['accountStatement' => [
        'info' => ['accountId' => '123456789', 'bankId' => '2010', 'currency' => 'CZK',
            'iban' => BankTransferPayment::ibanForCzechAccount('123456789/2010')],
        'transactionList' => ['transaction' => [[
            'column22' => ['value' => 76543210], 'column5' => ['value' => '1234567890'],
            'column1' => ['value' => 1079.0], 'column14' => ['value' => 'CZK'],
        ]]],
    ]], JSON_THROW_ON_ERROR)];
});
$statement = $client->fetch($token, '2026-10-01', '2026-10-10');
if ($calls !== ['https://fioapi.fio.cz/v1/rest/periods/' . $token .
    '/2026-10-01/2026-10-10/transactions.json'] ||
    FioTransferMatcher::account($statement['info'], '0123456789/2010') !== ':123456789/2010' ||
    FioTransferMatcher::movement($statement['transactions'][0]) !== [
        'id' => '76543210', 'vs' => '1234567890', 'amount' => 1079,
    ]) throw new RuntimeException('Fio statement fields were parsed incorrectly.');

$movement = FioTransferMatcher::movement($statement['transactions'][0]);
$order = ['payment_method' => 'bank_transfer', 'payment_status' => 'pending', 'status' => 'new',
    'variable_symbol' => '1234567890', 'total_czk' => 1079,
    'payment_details_json' => '{"account_display":"123456789/2010"}'];
if (!FioTransferMatcher::matchesOrder($order, $movement, ':123456789/2010') ||
    FioTransferMatcher::matchesOrder(array_replace($order, ['total_czk' => 1080]), $movement, ':123456789/2010') ||
    FioTransferMatcher::matchesOrder(array_replace($order, ['payment_details_json' =>
        '{"account_display":"123456789/5500"}']), $movement, ':123456789/2010')) {
    throw new RuntimeException('Fio matching accepted a wrong account or amount.');
}
foreach (['123456789/2010', '123-123456789/2010'] as $account) {
    $iban = BankTransferPayment::ibanForCzechAccount($account);
    $info = ['accountId' => substr($iban, 8, 16), 'bankId' => '2010',
        'currency' => 'CZK', 'iban' => $iban];
    if (FioTransferMatcher::account($info, $account) !== FioTransferMatcher::canonicalAccount($account)) {
        throw new RuntimeException('A 16-digit accountId or prefixed account was rejected.');
    }
    try {
        FioTransferMatcher::account(array_replace($info, ['iban' =>
            BankTransferPayment::ibanForCzechAccount('999999999/2010')]), $account);
        throw new RuntimeException('A different IBAN was accepted.');
    } catch (RuntimeException $expected) {
        if (str_contains($expected->getMessage(), 'was accepted')) throw $expected;
    }
}

foreach ([
    404 => 'parametry',
    409 => '30 sekund',
    413 => 'pohybů',
    422 => 'historii',
    500 => 'není aktivní',
] as $status => $explanation) {
    $failure = new FioStatementClient(static fn(string $url): array => [$status, '']);
    try {
        $failure->fetch($token, '2026-10-01', '2026-10-10');
        throw new RuntimeException('Undetected Fio HTTP error.');
    } catch (RuntimeException $expected) {
        if (!str_contains($expected->getMessage(), 'HTTP ' . $status) ||
            !str_contains($expected->getMessage(), $explanation) ||
            str_contains($expected->getMessage(), $token)) throw $expected;
    }
}
if (FioStatementClient::validToken(str_repeat('A', 63)) ||
    FioStatementClient::validToken(str_repeat('A', 65)) ||
    FioStatementClient::validToken(str_repeat('A', 63) . '/')) {
    throw new RuntimeException('Incorrect token lengths or unsafe tokens were accepted.');
}
try {
    $client->fetch(str_repeat('A', 63), '2026-10-01', '2026-10-10');
    throw new RuntimeException('Invalid Fio token was sent to the transport.');
} catch (RuntimeException $expected) {
    if (str_contains($expected->getMessage(), 'was sent to the transport')) throw $expected;
}
if (count($calls) !== 1) throw new RuntimeException('Fio sent an invalid token over HTTP.');

foreach ([
    ['column1' => ['value' => -1079]], ['column1' => ['value' => 1079.50]],
    ['column1' => ['value' => 1079.0000001]], ['column1' => ['value' => '1079.0000001']],
    ['column1' => ['value' => '1e3']], ['column1' => ['value' => true]],
    ['column22' => ['value' => true]], ['column5' => ['value' => true]],
    ['column14' => ['value' => 'EUR']], ['column5' => null], ['column22' => null],
] as $change) {
    if (FioTransferMatcher::movement(array_replace($statement['transactions'][0], $change)) !== null) {
        throw new RuntimeException('A nonmatching bank movement was accepted.');
    }
}
if (FioTransferMatcher::movement(array_replace($statement['transactions'][0],
    ['column1' => ['value' => '9999999.00']]))['amount'] !== 9999999) {
    throw new RuntimeException('An exact maximum CZK amount was rejected.');
}
try {
    FioTransferMatcher::account(array_replace($statement['info'], ['iban' => []]),
        '123456789/2010');
    throw new RuntimeException('A malformed account identity was accepted.');
} catch (RuntimeException $expected) {
    if (str_contains($expected->getMessage(), 'was accepted')) throw $expected;
}
try {
    FioTransferMatcher::account(array_replace($statement['info'], ['accountId' => '123456780']),
        '123456789/2010');
    throw new RuntimeException('A token belonging to another account was accepted.');
} catch (RuntimeException $expected) {
    if (str_contains($expected->getMessage(), 'A token belonging')) throw $expected;
}
echo "Fio statement tests passed.\n";

$now = new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC'));
foreach ([
    ['2026-10-24 12:00:00', 600], ['2026-10-24 11:59:59', 3600],
    ['2026-10-17 12:00:00', 3600], ['2026-10-17 11:59:59', null],
] as [$due, $interval]) {
    if (\SimpleStore\Checkout\FioPollingPolicy::interval([
        'created_at' => '2026-10-01 00:00:00', 'payment_due_at' => $due,
    ], $now) !== $interval) throw new RuntimeException('Incorrect Fio automatic polling boundary.');
}
if (\SimpleStore\Checkout\FioPollingPolicy::interval([
    'created_at' => '2026-09-01 12:00:00', 'payment_due_at' => null,
], $now) !== null) throw new RuntimeException('Legacy orders without a due date must eventually stop polling.');
echo "Fio polling policy tests passed.\n";

foreach ([
    [1340661600000, '2012-06-26'], ['1340661600000', '2012-06-26'],
    ['2026-10-24+0200', '2026-10-24'], ['2026-10-24+02:00', '2026-10-24'],
    ['2026-01-01', '2026-01-01'], ['2026-02-30+0100', null],
    ['2026-10-24junk', null], [null, null], [true, null], [123, null],
] as [$value, $expectedDate]) {
    if (\SimpleStore\Accounting\FioPaymentEvidence::bookingDate(['column0'=>['value'=>$value]]) !== $expectedDate) {
        throw new RuntimeException('Incorrect Fio bank booking date.');
    }
}
// Bank midnight is the next calendar day compared to UTC, including year rollover.
$millis = (new DateTimeImmutable('2025-12-31 23:00:00', new DateTimeZone('UTC')))->getTimestamp() * 1000;
if (\SimpleStore\Accounting\FioPaymentEvidence::bookingDate(['column0'=>['value'=>$millis]]) !== '2026-01-01') {
    throw new RuntimeException('Bank receipt date shifted across calendar years.');
}
echo "Fio evidence date tests passed.\n";
