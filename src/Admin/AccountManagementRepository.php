<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use SimpleStore\Customer\CustomerRepository;
use Throwable;

/** All account mutations require a live administrator and share a database lock. */
final class AccountManagementRepository
{
    public function __construct(private MeekroDB $db) {}

    public function installed(): bool
    {
        return (new CustomerRepository($this->db))->installed() &&
            (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', 'shop_users', 'auth_version') === 1;
    }

    public function page(string $search, string $role, string $active, int $offset, int $limit = 25): array
    {
        if (preg_match('/^.{0,100}$/usD', $search) !== 1 ||
            !in_array($role, ['', 'admin', 'customer'], true) || !in_array($active, ['', '0', '1'], true) ||
            $offset < 0 || $offset > 100000 || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Neplatný filtr účtů.');
        }
        $sql = 'SELECT u.id, u.username, u.email, u.display_name, u.phone, u.role, u.is_active, u.auth_version, u.created_at,
            (SELECT COUNT(*) FROM shop_orders o WHERE o.user_id=u.id) AS order_count FROM shop_users u WHERE 1=1';
        $args = [];
        if ($role !== '') { $sql .= ' AND u.role=%s'; $args[] = $role; }
        if ($active !== '') { $sql .= ' AND u.is_active=%i'; $args[] = (int) $active; }
        if (trim($search) !== '') {
            $sql .= ' AND (LOCATE(%s, LOWER(u.email))>0 OR LOCATE(%s, LOWER(u.display_name))>0
                OR LOCATE(%s, LOWER(u.username))>0 OR LOCATE(%s, u.phone)>0)';
            array_push($args, strtolower(trim($search)), strtolower(trim($search)), strtolower(trim($search)), trim($search));
        }
        $rows = $this->db->query($sql . ' ORDER BY u.id DESC LIMIT %i OFFSET %i', ...array_merge($args, [$limit + 1, $offset]));
        foreach ($rows as &$row) {
            if ($row['role'] === 'admin' && (int) $row['auth_version'] === 0) {
                $row['email'] = (new AdminUserRepository($this->db))->loginEmailFor($row) ?? $row['email'];
            }
        }
        unset($row);
        return ['items' => array_slice($rows, 0, $limit), 'nextOffset' => count($rows) > $limit ? $offset + $limit : null];
    }

    public function find(int $id): ?array
    {
        $row = $id > 0 ? $this->db->queryFirstRow('SELECT * FROM shop_users WHERE id=%i', $id) : null;
        if ($row === null) return null;
        $row['revision'] = self::revision($row);
        if ($row['role'] === 'admin') $row['login_email'] = (new AdminUserRepository($this->db))->loginEmailFor($row);
        unset($row['password_hash']);
        return $row;
    }

    public function recentOrders(int $id): array
    {
        return $this->db->query('SELECT id, order_number, total_czk, status, payment_status, created_at
            FROM shop_orders WHERE user_id=%i ORDER BY id DESC LIMIT 10', $id);
    }

    public function addresses(int $id): array { return (new CustomerRepository($this->db))->addresses($id); }

    public function create(int $actor, array $input, string $password): int
    {
        if (is_string($input['username'] ?? null) && trim($input['username']) === '') {
            $input['username'] = 'user_' . bin2hex(random_bytes(12));
        }
        $fields = self::profile($input);
        self::password($password);
        return $this->write($actor, function () use ($fields, $password): int {
            $this->unique($fields, 0);
            $this->db->insert('shop_users', $fields + ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'auth_version' => 1]);
            return (int) $this->db->insertId();
        });
    }

    public function update(int $actor, int $id, string $revision, array $input): void
    {
        $fields = self::profile($input);
        $this->write($actor, function () use ($actor, $id, $revision, $fields): void {
            $row = $this->locked($id, $revision);
            $this->protectAdministrator($actor, $row, $fields['role'], $fields['is_active']);
            $this->unique($fields, $id);
            $revoke = (int) $row['auth_version'] === 0 || $row['email'] !== $fields['email'] || $row['role'] !== $fields['role'] ||
                (int) $row['is_active'] !== $fields['is_active'];
            $this->db->update('shop_users', $fields + ['auth_version' => (int) $row['auth_version'] + (int) $revoke], 'id=%i', $id);
            if ($revoke) $this->clearResets($id);
        });
    }

    public function setPassword(int $actor, int $id, string $revision, string $password): void
    {
        self::password($password);
        $this->write($actor, function () use ($id, $revision, $password): void {
            $row = $this->locked($id, $revision);
            if ($row['role'] === 'admin' && (int) $row['auth_version'] === 0) {
                $email = (new AdminUserRepository($this->db))->loginEmailFor($row);
                if ($email === null) throw new InvalidArgumentException('Nejdřív ulož přihlašovací e-mail správce.');
                $this->unique(['email' => $email, 'username' => $row['username']], $id);
                $this->db->query('UPDATE shop_users SET email=%s WHERE id=%i', $email, $id);
            }
            $this->db->query('UPDATE shop_users SET password_hash=%s, password_changed_at=UTC_TIMESTAMP(),
                auth_version=auth_version+1 WHERE id=%i', password_hash($password, PASSWORD_DEFAULT), $id);
            $this->clearResets($id);
        });
    }

    public function delete(int $actor, int $id, string $revision): void
    {
        $this->write($actor, function () use ($actor, $id, $revision): void {
            $row = $this->locked($id, $revision);
            $this->protectAdministrator($actor, $row, 'customer', 0);
            $this->clearResets($id);
            $this->db->query('DELETE FROM shop_customer_addresses WHERE user_id=%i', $id);
            // Retain order, claim, invoice and audit snapshots; detach only the account link.
            $this->db->query('UPDATE shop_orders SET user_id=NULL WHERE user_id=%i', $id);
            if ($this->tableExists('shop_after_sales_cases')) {
                $this->db->query('UPDATE shop_after_sales_cases SET user_id=NULL WHERE user_id=%i', $id);
            }
            $this->db->query('DELETE FROM shop_users WHERE id=%i', $id);
        });
    }

    public function saveAddress(int $actor, int $id, string $revision, ?int $addressId, array $input): void
    {
        $this->write($actor, function () use ($id, $revision, $addressId, $input): void {
            $this->locked($id, $revision);
            (new CustomerRepository($this->db))->saveAddress($id, $addressId, $input);
        });
    }

    public function deleteAddress(int $actor, int $id, string $revision, int $addressId): void
    {
        $this->write($actor, function () use ($id, $revision, $addressId): void {
            $this->locked($id, $revision);
            (new CustomerRepository($this->db))->removeAddress($id, $addressId);
        });
    }

    public static function profile(array $input): array
    {
        $fields = [];
        foreach (['username', 'email', 'display_name', 'phone', 'role', 'is_active'] as $key) {
            if (!is_string($input[$key] ?? null)) throw new InvalidArgumentException('Vyplň údaje účtu.');
            $fields[$key] = trim($input[$key]);
        }
        $fields['email'] = strtolower($fields['email']);
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{2,79}$/D', $fields['username']) !== 1) {
            throw new InvalidArgumentException('Identifikátor musí mít 3–80 znaků: písmena bez diakritiky, číslice, tečku, pomlčku nebo podtržítko.');
        }
        if (strlen($fields['email']) > 254 || filter_var($fields['email'], FILTER_VALIDATE_EMAIL) === false ||
            preg_match('/^.{1,120}$/usD', $fields['display_name']) !== 1 ||
            preg_match('/^.{0,40}$/usD', $fields['phone']) !== 1 ||
            preg_match('/[\x00-\x1f\x7f]/', $fields['display_name'] . $fields['phone'])) {
            throw new InvalidArgumentException('Zkontroluj e-mail, jméno a telefon.');
        }
        if (!in_array($fields['role'], ['admin', 'customer'], true) || !in_array($fields['is_active'], ['0', '1'], true)) {
            throw new InvalidArgumentException('Vyber platnou roli a stav účtu.');
        }
        $fields['is_active'] = (int) $fields['is_active'];
        return $fields;
    }

    public static function password(string $password): void
    {
        if (str_contains($password, "\0") || preg_match('/^.{10,}$/usD', $password) !== 1 || strlen($password) > 72) {
            throw new InvalidArgumentException('Heslo musí mít alespoň 10 znaků a nejvýše 72 bajtů.');
        }
    }

    private static function revision(array $row): string
    {
        $values = [];
        foreach (['id', 'username', 'email', 'display_name', 'phone', 'role', 'is_active', 'password_hash', 'auth_version'] as $key) {
            $values[] = (string) ($row[$key] ?? '');
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function locked(int $id, string $revision): array
    {
        $row = $this->db->queryFirstRow('SELECT * FROM shop_users WHERE id=%i FOR UPDATE', $id);
        if ($row === null) throw new InvalidArgumentException('Účet neexistuje.');
        if (!hash_equals(self::revision($row), $revision)) {
            throw new InvalidArgumentException('Účet mezitím někdo změnil. Obnov jeho detail a zkontroluj aktuální údaje.');
        }
        return $row;
    }

    private function protectAdministrator(int $actor, array $row, string $role, int $active): void
    {
        if ($role === 'admin' && $active === 1) return;
        if ((int) $row['id'] === $actor) {
            throw new InvalidArgumentException('Vlastní účet nelze smazat, zablokovat ani mu odebrat roli správce. Použij jiného správce.');
        }
        if ($row['role'] === 'admin' && (int) $row['is_active'] === 1 &&
            (int) $this->db->queryFirstField('SELECT COUNT(*) FROM shop_users WHERE role=%s AND is_active=1', 'admin') <= 1) {
            throw new InvalidArgumentException('Musí zůstat alespoň jeden aktivní správce.');
        }
    }

    private function unique(array $fields, int $id): void
    {
        if ($this->db->queryFirstRow('SELECT id FROM shop_users WHERE id<>%i AND (email=%s OR username=%s) LIMIT 1',
            $id, $fields['email'], $fields['username']) !== null) {
            throw new InvalidArgumentException('Tento e-mail nebo identifikátor už používá jiný účet.');
        }
        $legacy = (new AdminUserRepository($this->db))->findAdminByEmail($fields['email']);
        if ($legacy !== null && (int) $legacy['id'] !== $id) {
            throw new InvalidArgumentException('Tento e-mail používá původní správce. Nejdřív uprav jeho účet.');
        }
    }

    private function clearResets(int $id): void
    {
        if ($this->tableExists('shop_password_resets')) $this->db->query('DELETE FROM shop_password_resets WHERE user_id=%i', $id);
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table) > 0;
    }

    private function write(int $actor, callable $operation): mixed
    {
        if (!$this->installed()) throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        $lock = 'simple-store-users:' . sha1((string) $this->db->queryFirstField('SELECT DATABASE()'));
        if ((int) $this->db->queryFirstField('SELECT GET_LOCK(%s, %i)', $lock, 10) !== 1) {
            throw new RuntimeException('Právě se ukládá jiný účet. Zkus to znovu.');
        }
        try {
            $this->db->startTransaction();
            try {
                $admin = $this->db->queryFirstRow('SELECT id FROM shop_users WHERE id=%i AND role=%s AND is_active=1 FOR UPDATE', $actor, 'admin');
                if ($admin === null) throw new InvalidArgumentException('Pro správu účtů je potřeba aktivní správce.');
                $result = $operation();
                $this->db->commit();
                return $result;
            } catch (Throwable $error) {
                $this->db->rollback();
                // A concurrent public registration can win a unique email constraint.
                if ($error instanceof \MeekroDBException && (int) $error->getCode() === 1062) {
                    throw new InvalidArgumentException('Tento e-mail nebo identifikátor už používá jiný účet.');
                }
                throw $error;
            }
        } finally {
            $this->db->queryFirstField('SELECT RELEASE_LOCK(%s)', $lock);
        }
    }
}
