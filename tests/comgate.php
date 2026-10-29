<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $prefix = 'SimpleStore\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use SimpleStore\Checkout\ComgateApiClient;
use SimpleStore\Checkout\ComgatePaymentDraft;

function checkComgate(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$order = ['id' => 42, 'order_number' => '2026-42', 'total_czk' => 1079,
    'customer_email' => 'buyer@example.test', 'items' => [['sale_mode' => 'own']],
    'shipping' => ['name' => 'Eva Nová', 'method' => 'gls_home', 'phone' => '+420 123 456 789',
        'city' => 'Praha', 'street' => 'Polní 1', 'postal_code' => '11000']];
$draft = ComgatePaymentDraft::fromOrder($order, true);
checkComgate($draft['price'] === 107900 && $draft['curr'] === 'CZK' && $draft['test'] === true &&
    $draft['refId'] === '2026-42' && $draft['fullName'] === 'Eva Nová' &&
    $draft['phone'] === '+420123456789' && $draft['delivery'] === 'HOME_DELIVERY' &&
    $draft['homeDeliveryCity'] === 'Praha' && $draft['category'] === 'PHYSICAL_GOODS_ONLY',
    'Own goods must use minor units, customer fields and the physical goods category.');
$mixed = $order;
$mixed['items'][] = ['sale_mode' => 'assisted'];
$mixed['shipping'] = ['recipient' => 'Jan Nový', 'method' => 'zasilkovna_pickup', 'phone' => '123'];
$draft = ComgatePaymentDraft::fromOrder($mixed, false);
checkComgate($draft['category'] === 'OTHER' && $draft['test'] === false &&
    $draft['delivery'] === 'PICKUP' && $draft['fullName'] === 'Jan Nový' &&
    !isset($draft['homeDeliveryCity']) && !isset($draft['phone']),
    'Assisted services must not be declared physical-goods-only; pickup has no home address.');
foreach ([['total_czk' => 0], ['total_czk' => 1000001], ['total_czk' => 1.25],
    ['customer_email' => 'invalid'], ['shipping' => ['name' => ' ']]] as $bad) {
    $rejected = false;
    try { ComgatePaymentDraft::fromOrder(array_replace($order, $bad), false); }
    catch (InvalidArgumentException $expected) { $rejected = true; }
    checkComgate($rejected, 'Invalid payment draft reached the provider.');
}
foreach ([['{"code":0,"transId":"ABC-123"}', 201], ['{"code":"0"}', 200],
    ['{"code":1400}', 400], ['{"code":1301}', 403]] as [$json, $http]) {
    checkComgate(isset(ComgateApiClient::decodeResponse($json, $http)['code']), 'Valid API response rejected.');
}
foreach ([['{"code":0}', 500], ['{"code":0}', 302], ['{"code":0}', 403],
    ['{"code":0}', 0], ['{"code":false}', 200], ['{"code":[]}', 200],
    ['{"code":"0oops"}', 200], ['{}', 200], ['null', 200], ['<html>error</html>', 200],
    [str_repeat(' ', 65537), 200]] as [$json, $http]) {
    $rejected = false;
    try { ComgateApiClient::decodeResponse($json, $http); }
    catch (RuntimeException $expected) { $rejected = true; }
    checkComgate($rejected, 'Invalid or unsuccessful HTTP response was trusted.');
}
echo "Comgate payload and response checks passed.\n";
