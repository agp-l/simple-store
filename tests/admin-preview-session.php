<?php
declare(strict_types=1);

// Reopen the same administrator session without an SQL server.
class MeekroDB
{
    public function __construct(private string $passwordHash)
    {
    }

    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        if (str_contains($sql, 'FROM shop_tax_settings')) return null;
        if (str_contains($sql, 'FROM shop_mail_settings')) {
            return ['settings_json' => '{"admin_recovery_email":"owner@example.test"}'];
        }
        if (str_contains($sql, 'WHERE username=%s') && $values[0] !== 'admin') return null;
        if (str_contains($sql, 'WHERE id=%i') && $values[0] !== 1) return null;
        return ['id' => 1, 'username' => 'admin', 'email' => null, 'password_hash' => $this->passwordHash];
    }

    public function queryFirstField(string $sql, mixed ...$values): int { return 1; }

    public function query(string $sql, mixed ...$values): array { return []; }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Admin\AdminAuth;
use SimpleStore\Admin\AdminUserRepository;

ini_set('session.save_path', '/tmp');
$users = new AdminUserRepository(new MeekroDB(password_hash('test-password', PASSWORD_DEFAULT)));
$auth = new AdminAuth($users, '/shop/');
if (!$auth->signIn('owner@example.test', 'test-password')) throw new RuntimeException('Administrator sign-in failed.');
$auth->setVisitorPreview(true);
if (!$auth->visitorPreviewEnabled()) throw new RuntimeException('Visitor preview did not start.');

$sessionId = session_id();
session_write_close();
session_id('');
$_COOKIE['simple_store_admin'] = $sessionId;
$auth = new AdminAuth($users, '/shop/');
if (!$auth->visitorPreviewEnabled()) throw new RuntimeException('Visitor preview did not survive navigation.');
$auth->setVisitorPreview(false);
if ($auth->visitorPreviewEnabled()) throw new RuntimeException('Visitor preview did not stop.');
$auth->setVisitorPreview(true);
$auth->signOut();
if ($auth->visitorPreviewEnabled()) throw new RuntimeException('Sign-out retained visitor preview.');

echo "Administrator preview session passed.\n";
