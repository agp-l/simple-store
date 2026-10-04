<?php
declare(strict_types=1);

use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Database\ConnectionFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
$option = $argv[1] ?? '--limit=20';
if (count($argv) > 2 || preg_match('/^--limit=([1-9][0-9]?)$/D', $option, $matches) !== 1 ||
    (int) $matches[1] > 50) {
    fwrite(STDERR, "Use: php tools/mail-worker.php [--limit=1..50]\n");
    exit(1);
}

try {
    if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/config/database.php')) {
        throw new RuntimeException('Run composer install and configure config/database.php first.');
    }
    $queue = new OrderMailQueue(ConnectionFactory::create(require $root . '/config/database.php'));
    $result = $queue->dispatchDue((int) $matches[1]);
    echo "Selected: {$result['selected']}; sent: {$result['sent']}; failed: {$result['failed']}; skipped: {$result['skipped']}\n";
} catch (Throwable $error) {
    error_log('Mail worker: ' . $error->getMessage());
    fwrite(STDERR, "Mail worker failed. Check the PHP error log and admin mail queue.\n");
    exit(1);
}
