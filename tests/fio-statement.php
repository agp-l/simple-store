<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\FioStatementClient;
use SimpleStore\Checkout\FioTransferMatcher;

$token = str_repeat('A', 64);
$calls = [];
$client = new FioStatementClient(static function (string $url) use (&$calls): array {
    $calls[] = $url;
    return [200, json_encode(['accountStatement' => [
        'info' => ['accountId' => '123456789', 'bankId' => '2010', 'currency' => 'CZK'],
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
foreach ([
    ['column1' => ['value' => -1079]], ['column1' => ['value' => 1079.50]],
    ['column14' => ['value' => 'EUR']], ['column5' => null], ['column22' => null],
] as $change) {
    if (FioTransferMatcher::movement(array_replace($statement['transactions'][0], $change)) !== null) {
        throw new RuntimeException('A nonmatching bank movement was accepted.');
    }
}
try {
    FioTransferMatcher::account(array_replace($statement['info'], ['accountId' => '123456780']),
        '123456789/2010');
    throw new RuntimeException('A token belonging to another account was accepted.');
} catch (RuntimeException $expected) {
    if (str_contains($expected->getMessage(), 'A token belonging')) throw $expected;
}
echo "Fio statement tests passed.\n";
