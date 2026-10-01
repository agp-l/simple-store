<?php
declare(strict_types=1);

use SimpleStore\Checkout\BTCPayPaymentService;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Database\ConnectionFactory;

require __DIR__ . '/src/bootstrap.php';
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$raw = file_get_contents('php://input', false, null, 0, 65537);
if (!is_string($raw) || $raw === '' || strlen($raw) > 65536) {
    http_response_code(413);
    exit;
}
try {
    $db = ConnectionFactory::create(require __DIR__ . '/config/database.php');
    $example = require __DIR__ . '/config/checkout.example.php';
    $local = is_file(__DIR__ . '/config/checkout.php') ? require __DIR__ . '/config/checkout.php' : $example;
    $settings = (new CheckoutSettingsRepository($db))->load(
        CheckoutSettingsRepository::withDefaults($local, $example))['btcpay'] ?? [];
    $header = $_SERVER['HTTP_BTCPAY_SIG'] ?? '';
    if (!is_string($header) || !BTCPayPaymentService::verifySignature(
            $raw, $header, (string) ($settings['webhook_secret'] ?? ''))) {
        http_response_code(401);
        exit;
    }
    $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($event) || !is_string($event['invoiceId'] ?? null) ||
        !is_string($event['storeId'] ?? null) || $event['storeId'] !== ($settings['store_id'] ?? null)) {
        http_response_code(400);
        exit;
    }
    // Signed events are hints only. Read the invoice with our own API key and
    // compare its store, reference, currency, and amount before changing orders.
    (new BTCPayPaymentService($db, $settings))->notify($event['invoiceId']);
    http_response_code(200);
    echo 'OK';
} catch (JsonException | InvalidArgumentException $error) {
    error_log('BTCPay notification rejected: ' . $error->getMessage());
    http_response_code(400);
} catch (Throwable $error) {
    error_log('BTCPay notification verification failed: ' . $error->getMessage());
    http_response_code(503);
}
