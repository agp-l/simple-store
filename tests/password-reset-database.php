<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Accounting\MailCredential;
use SimpleStore\Accounting\MailSettingsRepository;
use SimpleStore\Auth\PasswordResetService;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$templates = [];
foreach (MailSettingsRepository::EVENTS as $code => $definition) {
    $templates[$code] = ['enabled' => '1', 'subject' => $definition['subject'], 'message' => $definition['message']];
}
$config = new MailSettingsRepository($db);
$config->save(['from_email' => 'store@example.test', 'from_name' => 'Dobrodruzi',
    'public_base_url' => 'https://example.test/shop', 'reply_to' => '', 'automatic_enabled' => '1',
    'admin_recovery_email' => 'owner@example.test', 'smtp_host' => 'smtp.example.test',
    'smtp_port' => '587', 'smtp_security' => 'starttls', 'smtp_username' => 'store@example.test',
    'smtp_password' => 'test-only-password', 'templates' => $templates]);
$loaded = $config->load()['settings'];
if ($loaded['smtp_password_encrypted'] === 'test-only-password' ||
    MailCredential::decrypt($loaded['smtp_password_encrypted']) !== 'test-only-password') {
    throw new RuntimeException('SMTP password was not encrypted at rest.');
}
$config->save(['from_email' => 'store@example.test', 'from_name' => 'Dobrodruzi',
    'public_base_url' => 'https://example.test/shop', 'reply_to' => '', 'automatic_enabled' => '1',
    'admin_recovery_email' => 'owner@example.test', 'smtp_host' => 'smtp.example.test',
    'smtp_port' => '587', 'smtp_security' => 'starttls', 'smtp_username' => 'store@example.test',
    'smtp_password' => '', 'templates' => $templates]);
if ($config->load()['settings']['smtp_password_encrypted'] !== $loaded['smtp_password_encrypted']) {
    throw new RuntimeException('Saving other settings erased the SMTP secret.');
}
$suffix = bin2hex(random_bytes(6));
$customer = 'reset-' . $suffix . '@example.test';
$db->insert('users', ['username' => 'reset_' . $suffix, 'email' => $customer,
    'password_hash' => password_hash('old-customer-password', PASSWORD_DEFAULT),
    'role' => 'customer', 'is_active' => 1]);
$customerId = (int) $db->queryFirstField('SELECT id FROM users WHERE email=%s', $customer);
$db->insert('users', ['username' => 'recovery_' . $suffix,
    'password_hash' => password_hash('old-admin-password', PASSWORD_DEFAULT),
    'role' => 'admin', 'is_active' => 1]);
$adminId = (int) $db->queryFirstField('SELECT id FROM users WHERE role=%s AND is_active=1 ORDER BY id LIMIT 1', 'admin');
$messages = [];
$service = new PasswordResetService($db, static function (string $email, string $subject,
    string $body, string $headers) use (&$messages): bool {
    $messages[] = [$email, $body];
    return true;
});
$service->request('customer', 'unknown-' . $suffix . '@example.test', 'account.php');
if ($messages !== []) throw new RuntimeException('Unknown accounts received a recovery email.');
$service->request('customer', $customer, 'account.php');
$service->request('customer', $customer, 'account.php');
if (count($messages) !== 1 || $messages[0][0] !== $customer ||
    preg_match('/\?mode=reset&token=([a-f0-9]{64})/', $messages[0][1], $matches) !== 1) {
    throw new RuntimeException('Customer recovery was not sent once with a valid token.');
}
$token = $matches[1];
if (!$service->valid('customer', $token) || $service->valid('admin', $token) ||
    $db->queryFirstRow('SELECT token_hash FROM shop_password_resets WHERE token_hash=%s', $token) !== null) {
    throw new RuntimeException('Recovery token was not role-scoped and hashed.');
}
$service->complete('customer', $token, 'new-customer-password', 'new-customer-password');
if ($service->valid('customer', $token) || !password_verify('new-customer-password',
    $db->queryFirstField('SELECT password_hash FROM users WHERE id=%i', $customerId))) {
    throw new RuntimeException('Used token remained valid or password was not changed.');
}
$service->request('admin', 'not-owner@example.test', 'admin.php');
if (count($messages) !== 1) throw new RuntimeException('Arbitrary address can recover an admin account.');
$service->request('admin', 'owner@example.test', 'admin.php');
if (count($messages) !== 2 || $messages[1][0] !== 'owner@example.test' ||
    !str_contains($messages[1][1], '/shop/admin.php?mode=reset&token=')) {
    throw new RuntimeException('Admin recovery did not use the configured owner address.');
}
preg_match('/\?mode=reset&token=([a-f0-9]{64})/', $messages[1][1], $matches);
$service->complete('admin', $matches[1], 'new-admin-password', 'new-admin-password');
if (!password_verify('new-admin-password',
    $db->queryFirstField('SELECT password_hash FROM users WHERE id=%i', $adminId))) {
    throw new RuntimeException('Admin password was not changed.');
}
echo "SMTP settings and password reset lifecycle OK\n";
