<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Checkout\BankTransferPayment;
use SimpleStore\Checkout\FioBankReconciler;
use SimpleStore\Checkout\FioStatementClient;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Admin\OrderControlRepository;
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
$db->query('UPDATE shop_orders SET created_at=UTC_TIMESTAMP() - INTERVAL 10 DAY WHERE id=%i', $order['id']);
$expectedFrom = (string) $db->queryFirstField('SELECT DATE_FORMAT(created_at, %s) FROM shop_orders WHERE id=%i',
    '%Y-%m-%d', $order['id']);

$token = str_repeat('T', 64);
$config = ['enabled' => true, 'token' => $token, 'account_display' => '123456789/2010'];
$wrongAccount = false;
$accountId = '999999999';
$rows = [];
$requests = [];
$client = new FioStatementClient(static function (string $url) use (&$accountId, &$rows, &$requests, $token): array {
    if (!str_contains($url, '/periods/' . $token . '/')) throw new RuntimeException('Bad Fio endpoint.');
    $requests[] = $url;
    return [200, json_encode(['accountStatement' => [
        'info' => ['accountId' => $accountId, 'bankId' => '2010', 'currency' => 'CZK',
            'iban' => BankTransferPayment::ibanForCzechAccount($accountId . '/2010'),
            'openingBalance' => 123456.78, 'closingBalance' => 999999.99],
        'transactionList' => ['transaction' => $rows],
    ]], JSON_THROW_ON_ERROR)];
});
$sync = new FioBankReconciler($db, $config, $client);
if (!$sync->installed()) throw new RuntimeException('Fio schema was not installed.');
$lookup = $db->queryFirstRow('EXPLAIN SELECT id FROM shop_orders
    WHERE variable_symbol=CONVERT(%s USING ascii) COLLATE ascii_bin', $order['variable_symbol']);
if (!str_contains((string) ($lookup['possible_keys'] ?? ''), 'orders_variable_symbol')) {
    throw new RuntimeException('The Fio lookup cannot use the variable-symbol index.');
}
try { $sync->sync(); } catch (RuntimeException $expected) { $wrongAccount = true; }
if (!$wrongAccount || $orders->findById((int) $order['id'])['payment_status'] !== 'pending') {
    throw new RuntimeException('A different bank account was accepted.');
}
if (!str_contains($requests[0] ?? '', '/' . $expectedFrom . '/')) {
    throw new RuntimeException('Fio must start at the oldest pending order rather than 89 days ago.');
}
try { $sync->sync(); throw new RuntimeException('Fio rate limit was ignored.'); }
catch (InvalidArgumentException $expected) {
    if ($expected->getMessage() === 'Fio rate limit was ignored.') throw $expected;
}
$accountId = '123456789';
$bookingDate = (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d');
$rows = [
    ['column0' => ['value' => $bookingDate . '+0200'], 'column22' => ['value' => 90001], 'column5' => ['value' => $order['variable_symbol']],
        'column1' => ['value' => 1078], 'column14' => ['value' => 'CZK']],
    ['column0' => ['value' => $bookingDate . '+0200'], 'column22' => ['value' => 90002], 'column5' => ['value' => $order['variable_symbol']],
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
$requestCount = count($requests);
$db->startTransaction();
try {
    // Other integration tests may leave pending bank orders in the shared CI database.
    $db->query('UPDATE shop_orders SET status=%s
        WHERE payment_method=%s AND payment_status=%s AND status=%s',
        'cancelled', 'bank_transfer', 'pending', 'new');
    if ($sync->sync() !== ['checked' => 0, 'matched' => 0, 'ignored' => 0, 'skipped' => 'no_orders'] ||
        count($requests) !== $requestCount) {
        throw new RuntimeException('Fio must not be contacted without a pending bank order.');
    }
} finally {
    $db->rollback();
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
// Simulate an existing installation and apply the repeatable schema without
// dropping the movement that was already matched.
$db->query('ALTER TABLE shop_fio_matches
    DROP FOREIGN KEY fio_match_detached_order_fk,
    MODIFY COLUMN order_id BIGINT UNSIGNED NOT NULL,
    ADD CONSTRAINT fio_match_order_fk FOREIGN KEY (order_id)
        REFERENCES shop_orders(id) ON DELETE CASCADE');
$db->query('UPDATE shop_schema_updates SET state=%s WHERE id=%i', 'failed', 1);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$deleteRule = $db->queryFirstField('SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=%s AND CONSTRAINT_NAME=%s',
    'shop_fio_matches', 'fio_match_detached_order_fk');
if ($deleteRule !== 'SET NULL') {
    throw new RuntimeException('The previous Fio foreign key was not upgraded.');
}
$db->query('DELETE FROM shop_orders WHERE id=%i', $order['id']);
$used = $db->queryFirstRow('SELECT order_id, amount_czk FROM shop_fio_matches
    WHERE movement_id=CONVERT(%s USING ascii) COLLATE ascii_bin', '90002');
if ($used === null || $used['order_id'] !== null || (int) $used['amount_czk'] !== 1079) {
    throw new RuntimeException('Deleting an order must retain its consumed Fio movement ID.');
}
$replacement = $orders->create(null, 'fio-replacement@example.test', [[
    'product_key' => str_repeat('b', 32), 'language' => 'cs', 'slug' => 'stan',
    'name' => 'Stan', 'quantity' => 1, 'unit_price_czk' => 1000,
    'image_path' => '', 'options' => [],
]], [
    'method' => 'gls_home', 'label' => 'GLS domů', 'name' => 'Eva Nová',
    'phone' => '123', 'street' => 'Polní 1', 'city' => 'Praha',
    'postal_code' => '11000', 'country' => 'CZ',
], 79, bin2hex(random_bytes(32)));
$db->query('UPDATE shop_orders SET variable_symbol=%s WHERE id=%i',
    $order['variable_symbol'], $replacement['id']);
$db->query('UPDATE shop_fio_requests SET last_requested_at=UTC_TIMESTAMP() - INTERVAL 40 SECOND
    WHERE token_hash=%s', hash('sha256', $token));
if ($sync->sync()['matched'] !== 0 ||
    $orders->findById((int) $replacement['id'])['payment_status'] !== 'pending') {
    throw new RuntimeException('An old Fio movement was reused for a new order.');
}
$orders->markPaid((int) $replacement['id'], 1);
(new OrderControlRepository($db))->correctPayment((int) $replacement['id'], 1,
    'Bank returned the transfer', 'not_received');
$rows = [[
    'column0' => ['value' => $bookingDate], 'column22' => ['value' => 90003], 'column5' => ['value' => $order['variable_symbol']],
    'column1' => ['value' => 1079], 'column14' => ['value' => 'CZK'],
]];
$db->query('UPDATE shop_fio_requests SET last_requested_at=UTC_TIMESTAMP() - INTERVAL 40 SECOND
    WHERE token_hash=%s', hash('sha256', $token));
if ($sync->sync()['matched'] !== 0 ||
    $orders->findById((int) $replacement['id'])['payment_status'] !== 'pending') {
    throw new RuntimeException('Fio ignored an explicit administrator payment correction.');
}
// Isolate automatic scheduling from unpaid fixtures left by earlier CI tests.
$db->query('UPDATE shop_orders SET status=%s WHERE payment_status=%s AND status=%s', 'cancelled', 'pending', 'new');
$auto = $orders->create(null, 'fio-auto@example.test', [[
    'product_key' => str_repeat('c', 32), 'language' => 'cs', 'slug' => 'auto',
    'name' => 'Test', 'quantity' => 1, 'unit_price_czk' => 1000, 'image_path' => '', 'options' => [],
]], ['method' => 'gls_home', 'label' => 'GLS', 'name' => 'Test', 'phone' => '123',
    'street' => 'Test 1', 'city' => 'Praha', 'postal_code' => '11000', 'country' => 'CZ'],
    79, bin2hex(random_bytes(32)));
$rows = [];
$age = static function (int $seconds) use ($db, $token): void {
    $db->query('UPDATE shop_fio_requests SET last_requested_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL %i SECOND)
        WHERE token_hash=%s', $seconds, hash('sha256', $token));
};
$age(40);
$count = count($requests);
if (($sync->sync(false)['skipped'] ?? '') !== 'interval' || count($requests) !== $count) {
    throw new RuntimeException('An early cron call contacted the bank.');
}
$age(601);
$sync->sync(false);
if (count($requests) !== ++$count) throw new RuntimeException('An active order was not checked after ten minutes.');
$db->query('UPDATE shop_orders SET payment_due_at=UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id=%i', $auto['id']);
$age(601);
if (($sync->sync(false)['skipped'] ?? '') !== 'interval' || count($requests) !== $count) {
    throw new RuntimeException('Overdue-only cron should wait an hour.');
}
$age(3601);
$sync->sync(false);
if (count($requests) !== ++$count) throw new RuntimeException('A recently overdue order was not checked.');
$db->query('UPDATE shop_orders SET payment_due_at=UTC_TIMESTAMP() - INTERVAL 8 DAY WHERE id=%i', $auto['id']);
$age(7200);
if (($sync->sync(false)['skipped'] ?? '') !== 'no_orders' || count($requests) !== $count) {
    throw new RuntimeException('Old overdue orders must not cause automatic API requests.');
}
$db->query('UPDATE shop_orders SET payment_details_json=%s WHERE id=%i',
    json_encode(['account_display' => '999999999/2010']), $auto['id']);
if (($sync->sync()['skipped'] ?? '') !== 'no_orders' || count($requests) !== $count) {
    throw new RuntimeException('An order for a different account triggered a request.');
}
$db->query('UPDATE shop_orders SET payment_details_json=%s WHERE id=%i', json_encode($bank->snapshot()), $auto['id']);
// Separate connections prove that a slow fetch cannot overlap a second request.
$otherDb = ConnectionFactory::create(['host'=>'127.0.0.1','user'=>'root',
    'password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306]);
$lock = 'fio:' . substr(hash('sha256', $token), 0, 60);
$otherDb->queryFirstField('SELECT GET_LOCK(%s, 0)', $lock);
try {
    if (($sync->sync(false)['skipped'] ?? '') !== 'busy' || count($requests) !== $count) {
        throw new RuntimeException('Overlapping Fio checks were not prevented.');
    }
} finally { $otherDb->queryFirstField('SELECT RELEASE_LOCK(%s)', $lock); }
$rows = [['column0'=>['value'=>$bookingDate], 'column22'=>['value'=>90004], 'column5'=>['value'=>$auto['variable_symbol']],
    'column1'=>['value'=>1079], 'column14'=>['value'=>'CZK']]];
$entryCount = (int)$db->queryFirstField('SELECT COUNT(*) FROM shop_tax_entries');
if ($sync->sync(true, (int)$auto['id'])['matched'] !== 1 || count($requests) !== ++$count) {
    throw new RuntimeException('A manual check must settle an old overdue order.');
}
$evidence = (new \SimpleStore\Accounting\FioPaymentEvidence($db))->forOrder((int)$auto['id']);
if ($evidence === null || $evidence['paid_date'] !== $bookingDate ||
    $evidence['external_number'] !== 'Fio:90004' || (int)$evidence['amount_czk'] !== 1079 ||
    (int)$db->queryFirstField('SELECT COUNT(*) FROM shop_tax_entries') !== $entryCount) {
    throw new RuntimeException('Fio evidence was not stored correctly, or silently changed cash totals.');
}
$file = $db->queryFirstRow('SELECT * FROM shop_evidence_files WHERE document_id=%i', $evidence['id']);
$payload = json_decode($file['file_data'], true, 512, JSON_THROW_ON_ERROR);
if ($file['sha256'] !== hash('sha256', $file['file_data']) || $file['mime_type'] !== 'application/json' ||
    $payload['transaction']['column22']['value'] !== 90004 || isset($payload['account']['openingBalance']) ||
    isset($payload['account']['closingBalance']) || str_contains($file['file_data'], $token)) {
    throw new RuntimeException('Fio evidence leaked unrelated data or lost its original movement/hash.');
}
$tax = new \SimpleStore\Accounting\TaxEvidenceRepository($db);
$tax->addOrderReceipt((int)$auto['id'], ['entry_date'=>$bookingDate, 'reference'=>'Fio:90004']);
$receipt = $tax->orderReceipt((int)$auto['id']);
if ((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_evidence_links WHERE document_id=%i AND entry_id=%i',
    $evidence['id'], $receipt['id']) !== 1) throw new RuntimeException('Later receipt did not attach its Fio document.');
$archive = (new \SimpleStore\Accounting\EvidenceArchive($db))->build((int)substr($bookingDate,0,4));
try {
    $zip = new ZipArchive();
    if ($zip->open($archive['path']) !== true ||
        $zip->getFromName('soubory/'.$file['id'].'.json') !== $file['file_data']) {
        throw new RuntimeException('Fio original is missing from the tax evidence ZIP.');
    }
    $zip->close();
} finally { \SimpleStore\Accounting\EvidenceArchive::cleanup($archive['directory']); }
// A targeted request must not fall back to a global check after cancellation/payment.
try { $sync->sync(true, (int)$auto['id']); throw new RuntimeException('Paid order was checked again.'); }
catch (InvalidArgumentException $expected) {}
if (count($requests) !== $count) throw new RuntimeException('An ineligible targeted check contacted Fio.');
// Missing/invalid/future dates and reused VS from before creation cannot settle.
$atomic = $orders->create(null, 'fio-atomic@example.test', [[
    'product_key'=>str_repeat('d',32), 'language'=>'cs', 'slug'=>'atomic', 'name'=>'Test',
    'quantity'=>1, 'unit_price_czk'=>1000, 'image_path'=>'', 'options'=>[],
]], ['method'=>'gls_home','label'=>'GLS','name'=>'Test','phone'=>'123',
    'street'=>'Test 1','city'=>'Praha','postal_code'=>'11000','country'=>'CZ'],79,bin2hex(random_bytes(32)));
$rows[0]['column22']['value'] = 90005;
$rows[0]['column5']['value'] = $atomic['variable_symbol'];
foreach ([null, '2026-02-30', '2099-01-01', '2000-01-01'] as $badDate) {
    $rows[0]['column0']['value'] = $badDate;
    $age(40);
    if ($sync->sync(true,(int)$atomic['id'])['matched'] !== 0) throw new RuntimeException('Invalid payment date accepted.');
}
$rows[0]['column0']['value'] = $bookingDate;
// If saving the private original fails, paid state and consumed ID must roll back too.
$db->query("CREATE TRIGGER fio_test_evidence_failure BEFORE INSERT ON shop_evidence_files
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fio evidence test failure'");
$failed = false;
$age(40);
try { $sync->sync(true,(int)$atomic['id']); }
catch (Throwable $expected) { $failed = true; }
finally { $db->query('DROP TRIGGER fio_test_evidence_failure'); }
if (!$failed || $orders->findById((int)$atomic['id'])['payment_status'] !== 'pending' ||
    $db->queryFirstField('SELECT movement_id FROM shop_fio_matches WHERE order_id=%i',$atomic['id']) !== null ||
    (new \SimpleStore\Accounting\FioPaymentEvidence($db))->forOrder((int)$atomic['id']) !== null) {
    throw new RuntimeException('Fio settlement and evidence were not atomic.');
}
$age(40);
if ($sync->sync(true,(int)$atomic['id'])['matched'] !== 1 ||
    (int)$db->queryFirstField('SELECT COUNT(*) FROM shop_evidence_documents WHERE order_id=%i AND kind=%s',
        $atomic['id'],'bank_movement') !== 1) throw new RuntimeException('Fio retry lost or duplicated its evidence.');
echo "Fio database tests passed.\n";
