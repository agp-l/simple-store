<?php
declare(strict_types=1);

use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\FioBankReconciler;
use SimpleStore\Database\ConnectionFactory;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'");
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit;
}
$key = $_GET['key'] ?? null;
if (!is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
    http_response_code(403);
    exit;
}

$root = __DIR__;
require $root . '/src/bootstrap.php';
try {
    if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/config/database.php')) {
        throw new RuntimeException('Not configured.');
    }
    $db = ConnectionFactory::create(require $root . '/config/database.php');
    $example = require $root . '/config/checkout.example.php';
    $local = is_file($root . '/config/checkout.php') ? require $root . '/config/checkout.php' : $example;
    $settings = (new CheckoutSettingsRepository($db))->load(
        CheckoutSettingsRepository::withDefaults($local, $example));
    $expected = (string) ($settings['fio_bank']['cron_key_hash'] ?? '');
    if (empty($settings['fio_bank']['enabled']) || strlen($expected) !== 64 ||
        !hash_equals($expected, hash('sha256', $key))) {
        http_response_code(403);
        exit;
    }
    $sender = (string) ((new TaxEvidenceRepository($db))->settings()['mail_from'] ?? '');
    $result = (new FioBankReconciler($db, $settings['fio_bank'], null,
        new OrderMailQueue($db), $sender))->sync();
    echo 'Checked ' . (int) $result['checked'] . '; paid ' . (int) $result['matched'] . "\n";
} catch (Throwable $error) {
    // HTTP cron logs can be viewed by third parties; never return credentials or a request URL.
    http_response_code(503);
    echo "Fio check failed. Open the shop administration for details.\n";
}
