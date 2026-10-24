<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Accounting\MailSettingsRepository;

/** Read and update administrator accounts stored in the shop_users table. */
final class AdminUserRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_users'
        ) > 0;
    }

    public function hasAdmin(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM shop_users WHERE role=%s AND is_active=1', 'admin'
        ) > 0;
    }

    public function findAdminByUsername(string $username): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT id, username, email, password_hash FROM shop_users
             WHERE username=%s AND role=%s AND is_active=1 LIMIT 1', $username, 'admin'
        );
    }

    /** Default identity for the CLI and older single-administrator installations. */
    public function loginEmail(): ?string
    {
        $user = $this->db->queryFirstRow('SELECT * FROM shop_users WHERE role=%s ORDER BY id LIMIT 1', 'admin');
        return $user === null || (int) ($user['is_active'] ?? 1) !== 1 ? null : $this->loginEmailFor($user);
    }

    /** Preserve the old configured login until the original account is explicitly saved. */
    public function loginEmailFor(array $user): ?string
    {
        if ((int) ($user['auth_version'] ?? 0) === 0) {
            $first = $this->db->queryFirstRow('SELECT * FROM shop_users WHERE role=%s ORDER BY id LIMIT 1', 'admin');
            if ($first !== null && (int) $first['id'] === (int) $user['id']) {
                $settings = (new MailSettingsRepository($this->db))->load()['settings'];
                $configured = strtolower(trim((string) ($settings['admin_recovery_email'] ?? '')));
                if (self::validEmail($configured)) return $configured;
            }
        }
        $stored = strtolower(trim((string) ($user['email'] ?? '')));
        return self::validEmail($stored) ? $stored : null;
    }

    public function findAdminByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if (!self::validEmail($email)) return null;
        // SELECT * tolerates installations awaiting the auth_version migration.
        $first = $this->db->queryFirstRow('SELECT * FROM shop_users WHERE role=%s ORDER BY id LIMIT 1', 'admin');
        if ($first !== null && (int) ($first['auth_version'] ?? 0) === 0) {
            $effective = $this->loginEmailFor($first);
            if ($effective !== null && hash_equals($effective, $email)) {
                return (int) ($first['is_active'] ?? 1) === 1 ? $first : null;
            }
            // An obsolete stored address must not recover the legacy owner's account.
            if (strtolower((string) ($first['email'] ?? '')) === $email) return null;
        }
        return $this->db->queryFirstRow('SELECT * FROM shop_users
            WHERE email=%s AND role=%s AND is_active=1 LIMIT 1', $email, 'admin');
    }

    public function findAdminById(int $id): ?array
    {
        return $this->db->queryFirstRow('SELECT * FROM shop_users
            WHERE id=%i AND role=%s AND is_active=1 LIMIT 1', $id, 'admin');
    }

    public function createAdmin(string $username, string $passwordHash, ?string $email = null): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{2,79}$/D', $username) !== 1 ||
            password_get_info($passwordHash)['algo'] === null ||
            ($email !== null && !self::validEmail($email))) {
            throw new InvalidArgumentException('Invalid administrator name, email or password hash.');
        }
        $fields = [
            'username' => $username,
            'email' => $email === null ? null : strtolower(trim($email)),
            'password_hash' => $passwordHash,
            'role' => 'admin',
            'is_active' => 1,
        ];
        if ($email !== null && (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', 'shop_users', 'auth_version') > 0) {
            $fields['auth_version'] = 1;
        }
        $this->db->insert('shop_users', $fields);
    }

    public function replacePassword(int $id, string $passwordHash): void
    {
        if ($id < 1 || password_get_info($passwordHash)['algo'] === null) {
            throw new InvalidArgumentException('Invalid administrator or password hash.');
        }
        $this->db->query(
            'UPDATE shop_users SET password_hash=%s, password_changed_at=CURRENT_TIMESTAMP
             WHERE id=%i AND role=%s AND is_active=1',
            $passwordHash, $id, 'admin'
        );
    }

    public function setEmail(int $id, string $email): void
    {
        if ($id < 1 || !self::validEmail($email)) {
            throw new InvalidArgumentException('Invalid administrator or email.');
        }
        $versioned = (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', 'shop_users', 'auth_version') > 0;
        $this->db->query('UPDATE shop_users SET email=%s' . ($versioned ? ', auth_version=auth_version+1' : '') .
            ' WHERE id=%i AND role=%s AND is_active=1', strtolower(trim($email)), $id, 'admin');
    }

    private static function validEmail(string $email): bool
    {
        return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
