<?php
declare(strict_types=1);

use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\ComgatePaymentService;
use SimpleStore\Database\ConnectionFactory;

require __DIR__ . '/src/bootstrap.php';
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json' || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    http_response_code(400);
    exit;
}
$raw = file_get_contents('php://input', false, null, 0, 16385);
$data = is_string($raw) && strlen($raw) <= 16384 ? json_decode($raw, true) : null;
if (!is_array($data)) {
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
    (new ComgatePaymentService($db, $settings['comgate'] ?? []))->notify($data);
    http_response_code(200);
    echo 'OK';
} catch (InvalidArgumentException $error) {
    // Authentication and amount mismatches are never acknowledged as successful payments.
    error_log('Comgate notification rejected: ' . $error->getMessage());
    http_response_code(400);
} catch (Throwable $error) {
    // Comgate retries non-2xx notifications after temporary database/API failures.
    error_log('Comgate notification failed: ' . $error->getMessage());
    http_response_code(503);
}
