<?php
declare(strict_types=1);

use SimpleStore\Admin\AdminUserRepository;
use SimpleStore\Database\ConnectionFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
$command = $argv[1] ?? 'create';
$emailOption = $argv[2] ?? null;
if (!in_array($command, ['create', '--reset', '--migrate'], true) || count($argv) > 3 ||
    ($emailOption !== null && !str_starts_with($emailOption, '--email='))) {
    fwrite(STDERR, "Use: php tools/admin.php [create|--reset|--migrate] [--email=owner@example.com]\n");
    exit(1);
}
$emailOption = $emailOption === null ? null : substr($emailOption, strlen('--email='));

try {
    if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/config/database.php')) {
        throw new RuntimeException('Run composer install and configure config/database.php first.');
    }
    $users = new AdminUserRepository(ConnectionFactory::create(require $root . '/config/database.php'));
    if (!$users->installed()) {
        throw new RuntimeException('Import database/schema.sql into the configured database first (shop_users table missing).');
    }
    $configuredEmail = $users->loginEmail();
    $loginEmail = $configuredEmail;
    if ($emailOption !== null && filter_var($emailOption, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Enter a valid administrator email.');
    }
    if ($loginEmail !== null && $emailOption !== null && strcasecmp($loginEmail, $emailOption) !== 0) {
        throw new RuntimeException('A different administrator email is configured in the shop mail settings. Change it there first.');
    }
    $loginEmail ??= $emailOption;
    if ($loginEmail === null) {
        throw new RuntimeException('Set the administrator email with --email=owner@example.com.');
    }

    if ($command === '--migrate') {
        if ($users->hasAdmin()) {
            throw new RuntimeException('An administrator already exists in the database. Migration did not overwrite it.');
        }
        $oldFile = $root . '/config/admin.php';
        if (!is_readable($oldFile)) {
            throw new RuntimeException('Cannot read config/admin.php. Use --reset to create a new password instead.');
        }
        $old = require $oldFile;
        if (!is_array($old) || !is_string($old['username'] ?? null) ||
            !is_string($old['password_hash'] ?? null)) {
            throw new RuntimeException('Invalid legacy administrator file. Use --reset to create a new password.');
        }
        $users->createAdmin($old['username'], $old['password_hash'], $configuredEmail === null ? $loginEmail : null);
        echo "Administrator migrated. Email: {$loginEmail}. The old password still works. config/admin.php is no longer read by the web.\n";
        exit;
    }

    $existing = $users->findAdminByUsername('admin');
    if ($command === 'create' && $users->hasAdmin()) {
        throw new RuntimeException('An administrator already exists. Use --reset to replace the password.');
    }
    if ($command === '--reset' && $existing === null && $users->hasAdmin()) {
        throw new RuntimeException('An administrator with another username exists. No account was changed.');
    }

    // Generate a password without putting it in shell history or process arguments.
    $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($existing === null) {
        $users->createAdmin('admin', $hash, $configuredEmail === null ? $loginEmail : null);
    } else {
        if ($configuredEmail === null) $users->setEmail((int) $existing['id'], $loginEmail);
        $users->replacePassword((int) $existing['id'], $hash);
    }
    echo "Email: {$loginEmail}\nPassword: {$password}\nSave the password now; it will not be displayed again.\n";
    if (is_file($root . '/config/admin.php')) {
        echo "The old config/admin.php is not used. Remove it after verifying the new login.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
