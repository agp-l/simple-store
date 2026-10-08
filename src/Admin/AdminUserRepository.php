<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Accounting\MailSettingsRepository;

/** Read and update administrator accounts stored in the users table. */
final class AdminUserRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'users'
        ) > 0;
    }

    public function hasAdmin(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM users WHERE role=%s AND is_active=1', 'admin'
        ) > 0;
    }

    public function findAdminByUsername(string $username): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT id, username, email, password_hash FROM users
             WHERE username=%s AND role=%s AND is_active=1 LIMIT 1', $username, 'admin'
        );
    }

    /** The configured recovery address is the login identity for the single shop administrator. */
    public function loginEmail(): ?string
    {
        $settings = (new MailSettingsRepository($this->db))->load()['settings'];
        $configured = strtolower(trim((string) ($settings['admin_recovery_email'] ?? '')));
        if (self::validEmail($configured)) return $configured;
        $user = $this->db->queryFirstRow('SELECT email FROM users WHERE role=%s AND is_active=1 ORDER BY id LIMIT 1', 'admin');
        $stored = strtolower(trim((string) ($user['email'] ?? '')));
        return self::validEmail($stored) ? $stored : null;
    }

    public function findAdminByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        $configured = $this->loginEmail();
        if ($configured === null || !hash_equals($configured, $email)) return null;
        return $this->db->queryFirstRow(
            'SELECT id, username, email, password_hash FROM users
             WHERE role=%s AND is_active=1 ORDER BY id LIMIT 1', 'admin'
        );
    }

    public function findAdminById(int $id): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT id, username, password_hash FROM users
             WHERE id=%i AND role=%s AND is_active=1 LIMIT 1', $id, 'admin'
        );
    }

    public function createAdmin(string $username, string $passwordHash, ?string $email = null): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{2,79}$/D', $username) !== 1 ||
            password_get_info($passwordHash)['algo'] === null ||
            ($email !== null && !self::validEmail($email))) {
            throw new InvalidArgumentException('Invalid administrator name, email or password hash.');
        }
        $this->db->insert('users', [
            'username' => $username,
            'email' => $email === null ? null : strtolower(trim($email)),
            'password_hash' => $passwordHash,
            'role' => 'admin',
            'is_active' => 1,
        ]);
    }

    public function replacePassword(int $id, string $passwordHash): void
    {
        if ($id < 1 || password_get_info($passwordHash)['algo'] === null) {
            throw new InvalidArgumentException('Invalid administrator or password hash.');
        }
        $this->db->query(
            'UPDATE users SET password_hash=%s, password_changed_at=CURRENT_TIMESTAMP
             WHERE id=%i AND role=%s AND is_active=1',
            $passwordHash, $id, 'admin'
        );
    }

    public function setEmail(int $id, string $email): void
    {
        if ($id < 1 || !self::validEmail($email)) {
            throw new InvalidArgumentException('Invalid administrator or email.');
        }
        $this->db->query('UPDATE users SET email=%s WHERE id=%i AND role=%s AND is_active=1',
            strtolower(trim($email)), $id, 'admin');
    }

    private static function validEmail(string $email): bool
    {
        return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
