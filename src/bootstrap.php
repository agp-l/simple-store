<?php
declare(strict_types=1);

// Make development errors visible before loading application code.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$site = require $root . '/config/site.php';
if (!($site['debug'] ?? true)) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

$composerAutoload = $root . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    // The storefront can still render without Composer; database access cannot.
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'SimpleStore\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if ($relative === '' || str_contains($relative, '..')) {
            return;
        }
        $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

return $site;
