<?php
declare(strict_types=1);

use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Database\ConnectionFactory;

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
ini_set('display_errors', '0');

// Webglobe schedules a URL on the domain. The secret is stored outside Git and
// config/ is blocked by Apache; do not use a source-IP allowlist as authentication.
$root = __DIR__;
$configPath = $root . '/config/mail-cron.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit;
}
try { $config = require $configPath; }
catch (Throwable $error) {
    error_log('Mail cron token configuration: ' . $error->getMessage());
    http_response_code(503);
    exit;
}
$expected = is_array($config) ? ($config['token'] ?? null) : null;
$provided = $_GET['token'] ?? null;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' ||
    !in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true) ||
    !is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1 ||
    !is_string($provided) || preg_match('/^[a-f0-9]{64}$/D', $provided) !== 1 ||
    !hash_equals($expected, $provided)) {
    http_response_code(404);
    exit;
}

try {
    require $root . '/src/bootstrap.php';
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/config/database.php')) {
        throw new RuntimeException('Missing Composer or database setup.');
    }
    $queue = new OrderMailQueue(ConnectionFactory::create(require $root . '/config/database.php'));
    $queue->dispatchDue(3);
    echo "OK\n";
} catch (Throwable $error) {
    error_log('Mail cron: ' . $error->getMessage());
    http_response_code(503);
    echo "Worker unavailable\n";
}
