<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
$file = $root . '/config/admin.php';
if (is_file($file) && ($argv[1] ?? '') !== '--reset') {
    fwrite(STDERR, "An administrator already exists. Use --reset to replace the password.\n");
    exit(1);
}
if (isset($argv[1]) && $argv[1] !== '--reset') {
    fwrite(STDERR, "Use: php tools/admin.php [--reset]\n");
    exit(1);
}

// Generate a long password without putting it in shell history or a process argument.
$password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
$settings = ['username' => 'admin', 'password_hash' => password_hash($password, PASSWORD_DEFAULT)];
$contents = "<?php\ndeclare(strict_types=1);\n\n// Local administrator credentials. Never commit this file.\nreturn "
    . var_export($settings, true) . ";\n";
$temporary = tempnam($root . '/config', '.admin-');
// Apache may run under another user; config/ is blocked from direct HTTP access.
if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) === false ||
    !chmod($temporary, 0644) || !rename($temporary, $file)) {
    if ($temporary !== false && is_file($temporary)) {
        unlink($temporary);
    }
    fwrite(STDERR, "Could not write config/admin.php. Check the config directory permissions.\n");
    exit(1);
}

echo "Username: admin\nPassword: {$password}\nSave the password now; it will not be displayed again.\n";
