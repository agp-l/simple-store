<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Auth\LoginThrottle;

if (($argv[1] ?? '') === 'worker') {
    try { echo (new LoginThrottle($argv[2]))->consume('admin', 'parallel@example.test', '192.0.2.10', 1000) === 0 ? 'allowed' : 'blocked'; }
    catch (RuntimeException) { echo 'busy'; }
    exit;
}
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call): void {
    try { $call(); } catch (RuntimeException) { return; }
    throw new RuntimeException('Storage failure bypassed throttling.');
};
$root = sys_get_temp_dir() . '/login-throttle-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$file = $root . '/attempts.json';
try {
    for ($i = 0; $i < 10; $i++) {
        $check((new LoginThrottle($file))->consume('admin', ' Owner@Example.test ', '192.0.2.1', 1000) === 0, 'Budget rejected too early.');
    }
    $before = file_get_contents($file);
    $check((new LoginThrottle($file))->consume('admin', 'owner@example.test', '192.0.2.1', 1001) === 299, 'A fresh session/case variation reset the shared limit.');
    $check(file_get_contents($file) === $before, 'Blocked requests prolonged the lockout.');
    $check(!str_contains($before, 'owner') && !str_contains($before, '192.0.2.1'), 'State exposed raw identifiers.');
    $check((new LoginThrottle($file))->consume('customer', 'owner@example.test', '192.0.2.1', 1001) === 0, 'Admin lockout blocked customer role.');
    $check((new LoginThrottle($file))->consume('admin', 'owner@example.test', '192.0.2.1', 1300) === 0, 'Budget did not expire.');
    $account = new LoginThrottle($root . '/account.json');
    for ($i = 0; $i < 30; $i++) $check($account->consume('customer', 'target@example.test', '192.0.2.' . ($i + 1), 1000) === 0, 'Account budget rejected too early.');
    $check($account->consume('customer', 'target@example.test', '198.51.100.1', 1001) > 0, 'Rotating IPs bypassed account budget.');
    $ip = new LoginThrottle($root . '/ip.json');
    for ($i = 0; $i < 100; $i++) $check($ip->consume('customer', 'target' . $i . '@example.test', '2001:db8::1', 1000) === 0, 'IP budget rejected too early.');
    $check($ip->consume('customer', 'another@example.test', '2001:0db8:0:0:0:0:0:1', 1001) > 0, 'Rotating accounts or equivalent IPv6 bypassed IP budget.');
    $lock = fopen($file . '.lock', 'c'); flock($lock, LOCK_EX);
    $reject(fn () => (new LoginThrottle($file))->consume('admin', 'x', '192.0.2.1', 1000));
    flock($lock, LOCK_UN); fclose($lock);
    file_put_contents($file, '{broken');
    $reject(fn () => (new LoginThrottle($file))->consume('admin', 'x', '192.0.2.1', 1000));
    $reject(fn () => (new LoginThrottle($root . '/missing/state.json'))->consume('admin', 'x', '192.0.2.1', 1000));
    $check(file_get_contents($file) === '{broken', 'Corrupt state was silently reset.');
    $jobs = [];
    for ($i = 0; $i < 24; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $root . '/parallel.json'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start concurrency test.');
        fclose($pipes[0]); $jobs[] = [$process, $pipes];
    }
    $allowed = 0;
    foreach ($jobs as [$process, $pipes]) {
        $result = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $check(proc_close($process) === 0 && $errors === '', 'Concurrency worker failed: ' . $errors);
        $check(in_array($result, ['allowed','blocked','busy'], true), 'Unexpected worker result.');
        if ($result === 'allowed') $allowed++;
    }
    $check($allowed > 0 && $allowed <= 10, 'Concurrent requests exceeded the reserved budget.');
    $state = json_decode(file_get_contents($root . '/parallel.json'), true, 8, JSON_THROW_ON_ERROR);
    $check(count($state) === 3 && array_unique(array_column($state, 'count')) === [$allowed], 'Concurrent attempts lost counter updates.');
    $check(glob($root . '/*.tmp') === [], 'Temporary state was not cleaned.');
} finally {
    foreach (glob($root . '/*') as $path) unlink($path);
    rmdir($root);
}
echo "Shared login budgets: new sessions, account/IP rotation, expiry, failure closure and concurrency passed.\n";
