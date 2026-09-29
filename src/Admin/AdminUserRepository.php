<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use MeekroDB;

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
            'SELECT id, username, password_hash FROM users
             WHERE username=%s AND role=%s AND is_active=1 LIMIT 1', $username, 'admin'
        );
    }

    public function findAdminById(int $id): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT id, username, password_hash FROM users
             WHERE id=%i AND role=%s AND is_active=1 LIMIT 1', $id, 'admin'
        );
    }

    public function createAdmin(string $username, string $passwordHash): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{2,79}$/D', $username) !== 1 ||
            password_get_info($passwordHash)['algo'] === null) {
            throw new InvalidArgumentException('Invalid administrator name or password hash.');
        }
        $this->db->insert('users', [
            'username' => $username,
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
}
