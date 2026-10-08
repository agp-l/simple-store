<?php
declare(strict_types=1);

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

if (count($argv) !== 2 || !in_array($argv[1], ['--status', '--apply'], true)) {
    fwrite(STDERR, "Use: php tools/apply-schema.php [--status|--apply]\n");
    exit(1);
}

try {
    if (!is_file($root . '/config/database.php') || !is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException('Database configuration or Composer dependencies are missing.');
    }
    $updater = new SchemaUpdater(ConnectionFactory::create(require $root . '/config/database.php'),
        $root . '/database/schema.sql');
    $status = $updater->status();
    if ($argv[1] === '--status') {
        echo 'Database: ' . $status['database'] . PHP_EOL;
        echo 'Schema: ' . ($status['current'] ? 'current' : 'update available') . PHP_EOL;
        exit;
    }
    echo $updater->apply() ? "Schema updated.\n" : "Schema already current.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Schema update failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
