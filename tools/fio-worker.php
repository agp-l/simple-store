<?php
declare(strict_types=1);

use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\FioBankReconciler;
use SimpleStore\Database\ConnectionFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
if (count($argv) !== 1) {
    fwrite(STDERR, "Use: php tools/fio-worker.php\n");
    exit(1);
}
try {
    if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/config/database.php')) {
        throw new RuntimeException('Install dependencies and configure the database first.');
    }
    $db = ConnectionFactory::create(require $root . '/config/database.php');
    $example = require $root . '/config/checkout.example.php';
    $local = is_file($root . '/config/checkout.php') ? require $root . '/config/checkout.php' : $example;
    $settings = (new CheckoutSettingsRepository($db))->load(
        CheckoutSettingsRepository::withDefaults($local, $example));
    $sender = (string) ((new TaxEvidenceRepository($db))->settings()['mail_from'] ?? '');
    $result = (new FioBankReconciler($db, $settings['fio_bank'], null,
        new OrderMailQueue($db), $sender))->sync();
    echo "Movements checked: {$result['checked']}; orders paid: {$result['matched']}; ignored: {$result['ignored']}\n";
} catch (Throwable $error) {
    // A bank token is passed in a URL. Never print exception traces or HTTP requests here.
    fwrite(STDERR, "Fio check failed. Check settings, schema and PHP server logs.\n");
    exit(1);
}
