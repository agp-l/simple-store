<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\AfterSales\CaseNotice;
use SimpleStore\AfterSales\CaseRepository;
use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

function expectCase(bool $ok, string $description): void
{
    if (!$ok) throw new RuntimeException($description);
}

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$repo = new CaseRepository($db);
expectCase($repo->installed(), 'After-sales migration missing.');
$orderToken = bin2hex(random_bytes(32));
$number = 'CASE-TEST-' . bin2hex(random_bytes(5));
$db->insert('shop_orders', [
    'order_number' => $number, 'order_token' => $orderToken, 'status' => 'shipped',
    'customer_email' => 'case@example.test', 'subtotal_czk' => 800,
    'shipping_czk' => 80, 'total_czk' => 880,
    'items_json' => json_encode([['name' => 'Turistické boty', 'quantity' => 2,
        'unit_price_czk' => 400, 'options' => ['Velikost' => '42']]], JSON_THROW_ON_ERROR),
    'shipping_json' => json_encode(['name' => 'Eva', 'recipient' => 'Eva', 'company' => 'Test s.r.o.',
        'phone' => '+420777123456'], JSON_THROW_ON_ERROR),
    'payment_method' => 'bank_transfer', 'payment_status' => 'paid',
]);
$orderId = (int) $db->insertId();
expectCase($repo->orderForToken(str_repeat('e', 64)) === null &&
    $repo->byToken(str_repeat('d', 64)) === null,
    'A guessed reference revealed a private order or case.');
$complaintKey = bin2hex(random_bytes(32));
$input = ['kind' => 'complaint', 'item_line' => '1', 'quantity' => '1',
    'description' => 'Prasklá podrážka na pravé botě.', 'requested_solution' => 'repair',
    'delivered_on' => '', 'request_key' => $complaintKey];
$complaint = $repo->submit($orderToken, $input);
expectCase($complaint['customer_company'] === 'Test s.r.o.' &&
    $complaint['item_name'] === 'Turistické boty' &&
    CaseNotice::itemLabel($complaint) === 'Turistické boty · Velikost: 42' &&
    $complaint['status'] === 'submitted' &&
    $complaint['received_at'] === null && count($complaint['events']) === 1,
    'Complaint snapshot, time line or delivery columns failed.');
expectCase($repo->submit($orderToken, $input)['id'] === $complaint['id'] &&
    (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_after_sales_cases WHERE order_id=%i', $orderId) === 1,
    'Submitting the same form twice created a duplicate complaint.');
$receipt = CaseNotice::receipt($complaint);
expectCase(str_contains($receipt['text'], 'Prasklá podrážka') &&
    str_contains($receipt['text'], 'case@example.test') &&
    str_contains($receipt['text'], $complaint['case_number']),
    'Complaint receipt omitted required proof of submission.');

$withdraw = $repo->submit($orderToken, ['kind' => 'withdrawal', 'item_line' => '1', 'quantity' => '1',
    'description' => '', 'delivered_on' => '', 'request_key' => bin2hex(random_bytes(32)),
    'confirmed' => '1']);
expectCase($withdraw['kind'] === 'withdrawal' && $withdraw['requested_solution'] === 'refund' &&
    str_contains(CaseNotice::receipt($withdraw)['text'], 'odstoupil'),
    'Express withdrawal statement was not persisted.');
try {
    $repo->submit($orderToken, ['kind' => 'withdrawal', 'item_line' => '1', 'quantity' => '2',
        'description' => '', 'delivered_on' => '', 'request_key' => bin2hex(random_bytes(32)),
        'confirmed' => '1']);
    throw new RuntimeException('Repeated withdrawal exceeded bought quantity.');
} catch (InvalidArgumentException $expected) {
}
$review = $repo->update((int) $complaint['id'], 1, 'received', 'Zboží převzato k posouzení.');
expectCase($review['case']['received_at'] !== null && $review['case']['status'] === 'reviewing',
    'Physical receipt did not record date and state.');
$outcome = $repo->update((int) $complaint['id'], 1, 'resolved',
    'Podrážka byla opravena a zásilka bude odeslána zákazníkovi.', 'repair', '3 dny');
expectCase($outcome['case']['resolved_at'] !== null && $outcome['case']['resolution_type'] === 'repair' &&
    $outcome['case']['repair_duration'] === '3 dny' &&
    count($outcome['case']['events']) === 3, 'Complaint outcome lost its written proof or timeline.');
try {
    $repo->update((int) $complaint['id'], 1, 'received', 'Zboží převzato znovu.');
    throw new RuntimeException('Closed outcome was changed by physical receipt without reopening.');
} catch (InvalidArgumentException $expected) {
}

// Custom notices are unrelated to an order's outbox cleanup and stay queued after order deletion.
$message = CaseNotice::receipt($withdraw);
$key = 'after-sales:' . $withdraw['id'] . ':submitted';
$mailId = (new OrderMailQueue($db))->enqueueCustom($key, null, 'case@example.test',
    $message['subject'], $message['text'], $message['html']);
expectCase($mailId > 0 && $db->queryFirstField('SELECT order_id FROM shop_mail_outbox WHERE id=%i', $mailId) === null,
    'Withdrawal notice was attached to an order that administrators can delete.');
$db->query('DELETE FROM shop_orders WHERE id=%i', $orderId);
expectCase($repo->byToken($withdraw['case_token'])['order_id'] === null &&
    $repo->byToken($complaint['case_token'])['item_name'] === 'Turistické boty' &&
    $db->queryFirstField('SELECT state FROM shop_mail_outbox WHERE id=%i', $mailId) === 'queued',
    'Deleting an order erased a legally relevant case or its pending receipt.');
$repo->delete((int) $withdraw['id'], (string) $withdraw['case_number']);
expectCase($repo->byToken($withdraw['case_token']) === null &&
    $db->queryFirstField('SELECT id FROM shop_mail_outbox WHERE id=%i', $mailId) === null,
    'Administrator could not remove a mistaken case and its local notice.');
echo "After-sales database tests passed.\n";
