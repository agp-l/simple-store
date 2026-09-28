<?php
declare(strict_types=1);

use SimpleStore\Admin\AdminAuth;

require dirname(__DIR__) . '/src/Admin/AdminAuth.php';

$auth = new AdminAuth([
    'username' => 'admin',
    'password_hash' => password_hash('correct-password', PASSWORD_DEFAULT),
], '/simple-store/');
if ($auth->signedIn() || !$auth->validToken($auth->token()) || $auth->validToken('wrong-token') ||
    $auth->signIn('admin', 'wrong-password') || !$auth->signIn('admin', 'correct-password') ||
    !$auth->signedIn()) {
    throw new RuntimeException('Admin authentication failed.');
}
$auth->signOut();
if ($auth->signedIn()) {
    throw new RuntimeException('Admin logout failed.');
}
echo "Admin authentication tests passed.\n";
