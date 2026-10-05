<?php
declare(strict_types=1);

use SimpleStore\Database\CommerceTestReset;
use SimpleStore\Database\ConnectionFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

try {
    if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/config/database.php')) {
        throw new RuntimeException('Nejdřív spusť composer install a nastav config/database.php.');
    }
    $db = ConnectionFactory::create(require $root . '/config/database.php');
    $reset = new CommerceTestReset($db);
    $report = $reset->report();
    if (($argv[1] ?? '--report') === '--report' && count($argv) <= 2) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }
    $args = getopt('', ['apply', 'database:', 'confirm:', 'backup-file:']);
    if (!isset($args['apply']) || count($argv) !== 5 ||
        ($args['confirm'] ?? '') !== 'DELETE-TEST-COMMERCE' ||
        !is_string($args['database'] ?? null) ||
        !hash_equals($report['database'], $args['database']) ||
        !is_string($args['backup-file'] ?? null) ||
        !is_file($args['backup-file']) || !is_readable($args['backup-file']) ||
        filesize($args['backup-file']) < 100) {
        throw new RuntimeException('Použití: php tools/reset-test-commerce.php --apply --database=NAZEV_DB '
            . '--confirm=DELETE-TEST-COMMERCE --backup-file=/cesta/k/overene-zaloze.sql');
    }
    $result = $reset->apply($args['database']);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
