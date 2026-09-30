<?php
declare(strict_types=1);

use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\GoPayPaymentService;
use SimpleStore\Database\ConnectionFactory;

require __DIR__ . '/src/bootstrap.php';
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit;
}
if (!is_string($_GET['id'] ?? null) || preg_match('/^[0-9]{1,30}$/D', $_GET['id']) !== 1) {
    http_response_code(400);
    exit;
}
try {
    $db = ConnectionFactory::create(require __DIR__ . '/config/database.php');
    $example = require __DIR__ . '/config/checkout.example.php';
    $local = is_file(__DIR__ . '/config/checkout.php')
        ? require __DIR__ . '/config/checkout.php' : $example;
    $settings = (new CheckoutSettingsRepository($db))->load(
        CheckoutSettingsRepository::withDefaults($local, $example));
    (new GoPayPaymentService($db, $settings['gopay'] ?? []))->notify($_GET['id']);
    http_response_code(200);
    echo 'OK';
} catch (InvalidArgumentException $error) {
    // The notification is only a hint. Reconciliation always asks GoPay's API.
    error_log('GoPay notification rejected: ' . $error->getMessage());
    http_response_code(400);
} catch (Throwable $error) {
    error_log('GoPay notification verification failed: ' . $error->getMessage());
    http_response_code(503);
}
