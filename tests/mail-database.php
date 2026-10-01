<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Accounting\MailSettingsRepository;
use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Checkout\OrderTrackingRepository;
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
$templates['paid']['enabled'] = '0';
$settings->save(['from_email' => 'shop@example.test', 'from_name' => 'Dobrodruzi',
    'reply_to' => '', 'public_base_url' => '', 'automatic_enabled' => '1', 'templates' => $templates]);
$queue->notifyStage($id, 'paid');
if ($db->queryFirstField('SELECT id FROM shop_mail_outbox WHERE event_key=%s', 'paid:' . $id) !== null) {
    throw new RuntimeException('Disabled payment template still queued a message.');
}
echo "Live customer mail settings and tracking OK\n";
