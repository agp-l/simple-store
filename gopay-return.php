<?php
declare(strict_types=1);

use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\GoPayPaymentService;
use SimpleStore\Database\ConnectionFactory;

$site = require __DIR__ . '/src/bootstrap.php';
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' ||
    !is_string($_GET['token'] ?? null) ||
    preg_match('/^[a-f0-9]{64}$/D', $_GET['token']) !== 1 ||
    !is_string($_GET['id'] ?? null) ||
    preg_match('/^[0-9]{1,30}$/D', $_GET['id']) !== 1) {
    http_response_code(404);
    exit;
}
try {
    $db = ConnectionFactory::create(require __DIR__ . '/config/database.php');
    $example = require __DIR__ . '/config/checkout.example.php';
    $local = is_file(__DIR__ . '/config/checkout.php')
        ? require __DIR__ . '/config/checkout.php' : $example;
    $settings = (new CheckoutSettingsRepository($db))->load(
        CheckoutSettingsRepository::withDefaults($local, $example));
    $order = (new GoPayPaymentService($db, $settings['gopay'] ?? []))
        ->returnOrder($_GET['token'], $_GET['id']);
    if ($order === null || preg_match('/^[a-f0-9]{64}$/D',
            (string) ($order['order_token'] ?? '')) !== 1) {
        http_response_code(404);
        exit;
    }
    $folder = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/gopay-return.php'))), '/');
    $base = $folder === '' ? '/' : '/' . $folder . '/';
    $lang = (string) ($site['default_language'] ?? 'cs');
    header('Location: ' . $base . $lang . '/objednavka/' . $order['order_token'], true, 303);
} catch (Throwable $error) {
    error_log('GoPay return failed: ' . $error->getMessage());
    http_response_code(503);
}
