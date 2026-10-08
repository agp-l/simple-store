<?php
declare(strict_types=1);

class MeekroDB
{
    public array $reset;
    public string $passwordHash;
    public int $commits = 0;
    public int $rollbacks = 0;

    public function __construct(string $token, string $role, string $passwordHash)
    {
        $this->passwordHash = $passwordHash;
        $this->reset = ['token_hash' => hash('sha256', $token), 'role' => $role,
            'user_id' => 7, 'password_hash_at_issue' => hash('sha256', $passwordHash)];
    }

    public function queryFirstField(string $sql, mixed ...$values): int
    {
        return 1;
    }

    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        if (str_contains($sql, 'SHA2(')) throw new RuntimeException('SQL hash comparison depends on connection collation.');
        if ($values !== [$this->reset['token_hash'], $this->reset['role'], $this->reset['role']]) return null;
        return ['user_id' => $this->reset['user_id'],
            'password_hash_at_issue' => $this->reset['password_hash_at_issue'],
            'password_hash' => $this->passwordHash];
    }

    public function startTransaction(): void {}

    public function commit(): void { $this->commits++; }

    public function rollback(): void { $this->rollbacks++; }

    public function query(string $sql, mixed ...$values): void
    {
        if (str_starts_with($sql, 'UPDATE shop_users SET')) {
            $this->passwordHash = $values[0];
        } elseif (str_starts_with($sql, 'DELETE FROM shop_password_resets')) {
            $this->reset['token_hash'] = '';
        }
    }
}

require dirname(__DIR__) . '/src/Auth/PasswordResetService.php';

use SimpleStore\Auth\PasswordResetService;

$token = str_repeat('a', 64);
$db = new MeekroDB($token, 'admin', password_hash('old-password', PASSWORD_DEFAULT));
$service = new PasswordResetService($db);
if (!$service->valid('admin', $token) || $service->valid('customer', $token) ||
    $service->valid('admin', 'bad-token')) {
    throw new RuntimeException('The token was not correctly scoped and validated.');
}
$db->passwordHash = password_hash('changed-elsewhere', PASSWORD_DEFAULT);
if ($service->valid('admin', $token)) throw new RuntimeException('Changed password did not invalidate the token.');
try {
    $service->complete('admin', $token, 'new-secure-password', 'new-secure-password');
    throw new RuntimeException('Stale token was accepted.');
} catch (InvalidArgumentException $expected) {
    if ($db->rollbacks !== 1 || $db->commits !== 0) throw new RuntimeException('Stale reset was not rolled back.');
}
$db->passwordHash = password_hash('old-password', PASSWORD_DEFAULT);
$db->reset['password_hash_at_issue'] = hash('sha256', $db->passwordHash);
try {
    $service->complete('admin', $token, '123456789', '123456789');
    throw new RuntimeException('Nine-character password was accepted.');
} catch (InvalidArgumentException $expected) {
    if ($db->commits !== 0) throw new RuntimeException('Invalid password changed the account.');
}
$service->complete('admin', $token, '1234567890', '1234567890');
if ($db->commits !== 1 || $service->valid('admin', $token) ||
    !password_verify('1234567890', $db->passwordHash)) {
    throw new RuntimeException('Password reset did not finish and invalidate the token.');
}
echo "Password reset collation regression OK\n";
