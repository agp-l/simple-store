<?php
declare(strict_types=1);

class MeekroDB
{
    public array $admins = [];

    public function queryFirstField(string $sql, mixed ...$values): int
    {
        if (str_contains($sql, 'information_schema.TABLES')) return 1;
        return count(array_filter($this->admins, static fn (array $user): bool =>
            $user['role'] === 'admin' && $user['is_active'] === 1));
    }

    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        foreach ($this->admins as $user) {
            if ($user['role'] !== 'admin' || $user['is_active'] !== 1) continue;
            if (str_contains($sql, 'WHERE username=%s') && $user['username'] === $values[0]) return $user;
            if (str_contains($sql, 'WHERE id=%i') && $user['id'] === $values[0]) return $user;
        }
        return null;
    }

    public function insert(string $table, array $fields): void
    {
        if (isset($this->admins[$fields['username']])) throw new RuntimeException('Duplicate username.');
        $this->admins[$fields['username']] = ['id' => count($this->admins) + 1] + $fields;
    }

    public function query(string $sql, mixed ...$values): array
    {
        if (str_starts_with($sql, 'UPDATE users SET')) {
            foreach ($this->admins as &$user) {
                if ($user['id'] === $values[1] && $user['role'] === $values[2]) {
                    $user['password_hash'] = $values[0];
                }
            }
        }
        return [];
    }
}

require dirname(__DIR__) . '/src/Admin/AdminUserRepository.php';
require dirname(__DIR__) . '/src/Auth/RoleAuth.php';
require dirname(__DIR__) . '/src/Admin/AdminAuth.php';

use SimpleStore\Admin\AdminAuth;
use SimpleStore\Admin\AdminUserRepository;

$db = new MeekroDB();
$users = new AdminUserRepository($db);
if (!$users->installed() || $users->hasAdmin()) {
    throw new RuntimeException('Administrator setup detection failed.');
}
$users->createAdmin('admin', password_hash('correct-password', PASSWORD_DEFAULT));
$auth = new AdminAuth($users, '/simple-store/');
if (!$users->hasAdmin() || $auth->signedIn() || !$auth->validToken($auth->token()) ||
    $auth->validToken('wrong-token') || $auth->signIn('admin', 'wrong-password') ||
    !$auth->signIn('admin', 'correct-password') || !$auth->signedIn()) {
    throw new RuntimeException('Administrator login failed.');
}
$users->replacePassword(1, password_hash('new-password', PASSWORD_DEFAULT));
if ($auth->signedIn() || $auth->signIn('admin', 'correct-password') ||
    !$auth->signIn('admin', 'new-password')) {
    throw new RuntimeException('Password reset did not invalidate the existing session and old password.');
}
$db->admins['admin']['is_active'] = 0;
if ($auth->signedIn() || $auth->signIn('admin', 'new-password')) {
    throw new RuntimeException('A deactivated account retained access.');
}
$db->admins['admin']['is_active'] = 1;
$auth->signOut();
if ($auth->signedIn()) {
    throw new RuntimeException('Administrator logout failed.');
}
echo "Admin authentication tests passed.\n";
