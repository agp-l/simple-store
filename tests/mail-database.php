<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Accounting\MailSettingsRepository;
use SimpleStore\Accounting\MailDeliveryUncertainException;
use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Checkout\OrderTrackingRepository;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$settings = new MailSettingsRepository($db);
if (!$settings->installed()) throw new RuntimeException('Mail template schema missing.');
(new TaxEvidenceRepository($db))->saveSettings(['mail_from' => 'legacy@example.test']);
if ($settings->load()['settings']['from_email'] !== 'legacy@example.test') {
    throw new RuntimeException('Existing OSVČ sender must work before saving the new mail settings.');
}
$templates = [];
foreach (MailSettingsRepository::EVENTS as $code => $definition) {
    $templates[$code] = ['enabled' => '1', 'subject' => $definition['subject'],
        'message' => $definition['message']];
}
$settings->save(['from_email' => 'shop@example.test', 'from_name' => 'Dobrodruzi',
    'reply_to' => 'podpora@example.test', 'public_base_url' => 'https://shop.example.test',
    'automatic_enabled' => '1', 'templates' => $templates]);
$loaded = $settings->load();
if ($loaded['settings']['from_email'] !== 'shop@example.test' ||
    $loaded['templates']['shipped']['subject'] !== $templates['shipped']['subject']) {
    throw new RuntimeException('Mail settings did not persist.');
}
$number = 'MAIL-TEST-' . bin2hex(random_bytes(5));
$db->insert('shop_orders', ['order_number' => $number, 'status' => 'shipped',
    'customer_email' => 'recipient@example.test', 'subtotal_czk' => 1000,
    'shipping_czk' => 79, 'total_czk' => 1079,
    'items_json' => json_encode([['name' => 'Batoh', 'quantity' => 1, 'unit_price_czk' => 1000]], JSON_THROW_ON_ERROR),
    'shipping_json' => json_encode(['label' => 'GLS', 'recipient' => 'Eva'], JSON_THROW_ON_ERROR),
    'payment_method' => 'bank_transfer', 'payment_status' => 'paid',
    'variable_symbol' => (string) random_int(1000000000, 9999999999),
    'order_token' => bin2hex(random_bytes(32))]);
$id = (int) $db->queryFirstField('SELECT id FROM shop_orders WHERE order_number=%s', $number);
$tracking = new OrderTrackingRepository($db);
$tracking->save($id, 'GLS123456', 'https://carrier.example.test/track/GLS123456');
$sent = [];
$queue = new OrderMailQueue($db, static function (...$args) use (&$sent): bool {
    $sent[] = $args;
    return true;
});
$queue->notifyStage($id, 'shipped');
$queue->notifyStage($id, 'shipped');
$shipment = $db->queryFirstRow('SELECT state, attempts, body_text, body_html FROM shop_mail_outbox WHERE event_key=%s', 'shipped:' . $id);
if ($shipment === null || $shipment['state'] !== 'sent' || (int) $shipment['attempts'] !== 1 ||
    count($sent) !== 1 || !str_contains($shipment['body_text'], 'GLS123456') ||
    !str_contains($shipment['body_html'], 'Sledovat zásilku') ||
    !str_contains($sent[0][3], 'Reply-To: podpora@example.test')) {
    throw new RuntimeException('Shipment email was not persisted, sent once and linked to tracking.');
}
$queue->notifyStage($id, 'tracking', '', 'tracking:' . $id . ':one');
if (count($sent) !== 2) throw new RuntimeException('Tracking update email was not sent.');
$db->insert('shop_order_legal_snapshots', [
    'order_id' => $id, 'language' => 'cs', 'terms_document_key' => str_repeat('a', 32),
    'terms_revision' => 5, 'terms_text' => 'Přesné znění podmínek při objednání.',
]);
$paidMailId = $queue->enqueueStage((new OrderRepository($db))->findById($id), 'paid',
    'test:paid:legal:' . $id);
$paidMail = $db->queryFirstRow('SELECT body_text, body_html FROM shop_mail_outbox WHERE id=%i', $paidMailId);
if ($paidMail === null || !str_contains($paidMail['body_text'], 'Přesné znění podmínek při objednání.') ||
    !str_contains($paidMail['body_html'], 'Přesné znění podmínek při objednání.')) {
    throw new RuntimeException('A fast online payment must carry the immutable terms when the first mail was suppressed.');
}
$templates['paid']['enabled'] = '0';
$settings->save(['from_email' => 'shop@example.test', 'from_name' => 'Dobrodruzi',
    'reply_to' => '', 'public_base_url' => '', 'automatic_enabled' => '1', 'templates' => $templates]);
$queue->notifyStage($id, 'paid');
if ($db->queryFirstField('SELECT id FROM shop_mail_outbox WHERE event_key=%s', 'paid:' . $id) !== null) {
    throw new RuntimeException('Disabled payment template still queued a message.');
}

// The worker must recheck the locked order before delivering old payment instructions.
$db->insert('shop_mail_outbox', ['event_key' => 'order:' . $id, 'order_id' => $id,
    'recipient_email' => 'recipient@example.test', 'subject' => 'Stale order confirmation',
    'body_text' => 'Please pay', 'body_html' => '<p>Please pay</p>']);
$staleId = (int) $db->queryFirstField('SELECT id FROM shop_mail_outbox WHERE event_key=%s', 'order:' . $id);
$stale = new OrderMailQueue($db, static function (): bool {
    throw new RuntimeException('A stale order confirmation reached the transport.');
});
if ($stale->dispatchDue(1)['skipped'] !== 1 ||
    $db->queryFirstField('SELECT state FROM shop_mail_outbox WHERE id=%i', $staleId) !== 'suppressed') {
    throw new RuntimeException('Stale order confirmation was not suppressed after settlement.');
}

// A cron pass processes only due messages, and a failed transport backs off.
$workerKey = 'test:worker:' . bin2hex(random_bytes(10));
$db->insert('shop_mail_outbox', ['event_key' => $workerKey, 'recipient_email' => 'retry@example.test',
    'subject' => 'Retry test', 'body_text' => 'Body', 'body_html' => '<p>Body</p>']);
$workerId = (int) $db->queryFirstField('SELECT id FROM shop_mail_outbox WHERE event_key=%s', $workerKey);
$failedQueue = new OrderMailQueue($db, static fn (): bool => false);
$first = $failedQueue->dispatchDue(1);
$retry = $db->queryFirstRow('SELECT state, attempts, next_attempt_at,
    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), next_attempt_at) AS delay_seconds
    FROM shop_mail_outbox WHERE id=%i', $workerId);
if ($first !== ['selected' => 1, 'sent' => 0, 'failed' => 1, 'skipped' => 0] ||
    $retry['state'] !== 'failed' || (int) $retry['attempts'] !== 1 ||
    (int) $retry['delay_seconds'] < 295 || (int) $retry['delay_seconds'] > 300 ||
    $failedQueue->dispatchDue(1)['selected'] !== 0) {
    throw new RuntimeException('First retry must back off for five minutes.');
}
for ($attempt = 2; $attempt <= OrderMailQueue::MAX_AUTO_ATTEMPTS; $attempt++) {
    $db->query('UPDATE shop_mail_outbox SET next_attempt_at=UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id=%i', $workerId);
    if ($failedQueue->dispatchDue(1)['failed'] !== 1) {
        throw new RuntimeException('A due retry was not attempted.');
    }
    $retry = $db->queryFirstRow('SELECT attempts, next_attempt_at,
        TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), next_attempt_at) AS delay_seconds
        FROM shop_mail_outbox WHERE id=%i', $workerId);
    if ((int) $retry['attempts'] !== $attempt ||
        ($attempt === OrderMailQueue::MAX_AUTO_ATTEMPTS
            ? $retry['next_attempt_at'] !== null || $failedQueue->dispatchDue(1)['selected'] !== 0
            : (int) $retry['delay_seconds'] < 300 * 2 ** ($attempt - 1) - 5)) {
        throw new RuntimeException('Exponential backoff or automatic attempt cap failed.');
    }
}
$manual = new OrderMailQueue($db, static fn (): bool => true);
if (!$manual->dispatch($workerId) ||
    (int) $db->queryFirstField('SELECT attempts FROM shop_mail_outbox WHERE id=%i', $workerId) !== 6) {
    throw new RuntimeException('Administrator retry should bypass the automatic cap.');
}

// After DATA acceptance becomes uncertain, the worker must never auto-send it again.
$uncertainKey = 'test:uncertain:' . bin2hex(random_bytes(10));
$db->insert('shop_mail_outbox', ['event_key' => $uncertainKey, 'recipient_email' => 'uncertain@example.test',
    'subject' => 'Uncertain test', 'body_text' => 'Body', 'body_html' => '<p>Body</p>']);
$uncertainId = (int) $db->queryFirstField('SELECT id FROM shop_mail_outbox WHERE event_key=%s', $uncertainKey);
$ambiguous = new OrderMailQueue($db, static function (): never {
    throw new MailDeliveryUncertainException('lost SMTP DATA reply');
});
if ($ambiguous->dispatchDue(1)['skipped'] !== 1 ||
    $db->queryFirstField('SELECT state FROM shop_mail_outbox WHERE id=%i', $uncertainId) !== 'sending' ||
    $ambiguous->dispatchDue(1)['selected'] !== 0) {
    throw new RuntimeException('Ambiguous SMTP acceptance must stay in sending without another attempt.');
}
$db->query('UPDATE shop_mail_outbox SET attempted_at=UTC_TIMESTAMP() - INTERVAL 11 MINUTE WHERE id=%i', $uncertainId);
$ambiguous->reconcileSending($uncertainId, 'accepted');
if ($db->queryFirstField('SELECT state FROM shop_mail_outbox WHERE id=%i', $uncertainId) !== 'sent') {
    throw new RuntimeException('Verified SMTP acceptance was not reconciled.');
}
$missingKey = 'test:not-accepted:' . bin2hex(random_bytes(10));
$db->insert('shop_mail_outbox', ['event_key' => $missingKey, 'recipient_email' => 'missing@example.test',
    'subject' => 'Missing test', 'body_text' => 'Body', 'body_html' => '<p>Body</p>',
    'state' => 'sending', 'attempts' => 1,
    'attempted_at' => gmdate('Y-m-d H:i:s', time() - 660)]);
$missingId = (int) $db->queryFirstField('SELECT id FROM shop_mail_outbox WHERE event_key=%s', $missingKey);
$manual->reconcileSending($missingId, 'not_accepted');
if ($db->queryFirstField('SELECT state FROM shop_mail_outbox WHERE id=%i', $missingId) !== 'failed' ||
    $manual->dispatchDue(1)['selected'] !== 0 || !$manual->dispatch($missingId)) {
    throw new RuntimeException('Verified non-acceptance should allow a manual retry, with cron backoff.');
}
echo "Live customer mail settings and tracking OK\n";
