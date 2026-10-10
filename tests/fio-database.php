<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Checkout\BankTransferPayment;
use SimpleStore\Checkout\FioBankReconciler;
use SimpleStore\Checkout\FioStatementClient;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

$db = ConnectionFactory::create([
    'host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$bank = new BankTransferPayment('', '123456789/2010', 'Test');
$orders = new OrderRepository($db, $bank);
$order = $orders->create(null, 'fio-test@example.test', [[
    'product_key' => str_repeat('a', 32), 'language' => 'cs', 'slug' => 'stan',
    'name' => 'Stan', 'quantity' => 1, 'unit_price_czk' => 1000,
    'image_path' => '', 'options' => [],
]], [
    'method' => 'gls_home', 'label' => 'GLS domů', 'name' => 'Eva Nová',
    'phone' => '123', 'street' => 'Polní 1', 'city' => 'Praha',
    'postal_code' => '11000', 'country' => 'CZ',
], 79, bin2hex(random_bytes(32)));

$token = str_repeat('T', 64);
$config = ['enabled' => true, 'token' => $token, 'account_display' => '123456789/2010'];
$wrongAccount = false;
$accountId = '999999999';
$rows = [];
$client = new FioStatementClient(static function (string $url) use (&$accountId, &$rows, $token): array {
    if (!str_contains($url, '/periods/' . $token . '/')) throw new RuntimeException('Bad Fio endpoint.');
    return [200, json_encode(['accountStatement' => [
        'info' => ['accountId' => $accountId, 'bankId' => '2010', 'currency' => 'CZK'],
        'transactionList' => ['transaction' => $rows],
    ]], JSON_THROW_ON_ERROR)];
});
$sync = new FioBankReconciler($db, $config, $client);
if (!$sync->installed()) throw new RuntimeException('Fio schema was not installed.');
try { $sync->sync(); } catch (RuntimeException $expected) { $wrongAccount = true; }
if (!$wrongAccount || $orders->findById((int) $order['id'])['payment_status'] !== 'pending') {
    throw new RuntimeException('A different bank account was accepted.');
}
$accountId = '123456789';
$rows = [
    ['column22' => ['value' => 90001], 'column5' => ['value' => $order['variable_symbol']],
        'column1' => ['value' => 1078], 'column14' => ['value' => 'CZK']],
    ['column22' => ['value' => 90002], 'column5' => ['value' => $order['variable_symbol']],
        'column1' => ['value' => 1079], 'column14' => ['value' => 'CZK']],
];
// Simulate elapsed 35-second Fio request interval without waiting during CI.
$db->query('UPDATE shop_fio_requests SET last_requested_at=UTC_TIMESTAMP() - INTERVAL 40 SECOND
    WHERE token_hash=%s', hash('sha256', $token));
$result = $sync->sync();
$paid = $orders->findById((int) $order['id']);
if ($result !== ['checked' => 2, 'matched' => 1, 'ignored' => 1] ||
    $paid['payment_status'] !== 'paid' || $paid['provider_reference'] !== 'Fio:90002' ||
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_fio_matches WHERE order_id=%i', $order['id']) !== 1) {
    throw new RuntimeException('Exact bank transfer was not settled once.');
}
try { $sync->sync(); throw new RuntimeException('Fio rate limit was ignored.'); }
catch (InvalidArgumentException $expected) {
    if ($expected->getMessage() === 'Fio rate limit was ignored.') throw $expected;
}
$db->query('UPDATE shop_fio_requests SET last_requested_at=UTC_TIMESTAMP() - INTERVAL 40 SECOND
    WHERE token_hash=%s', hash('sha256', $token));
if ($sync->sync()['matched'] !== 0) throw new RuntimeException('Payment was settled twice.');
$db->query('UPDATE shop_orders SET payment_status=%s, payment_paid_at=NULL WHERE id=%i',
    'pending', $order['id']);
$db->query('UPDATE shop_fio_requests SET last_requested_at=UTC_TIMESTAMP() - INTERVAL 40 SECOND
    WHERE token_hash=%s', hash('sha256', $token));
if ($sync->sync()['matched'] !== 0 ||
    $orders->findById((int) $order['id'])['payment_status'] !== 'pending') {
    throw new RuntimeException('A corrected payment was automatically marked paid again.');
}
echo "Fio database tests passed.\n";
