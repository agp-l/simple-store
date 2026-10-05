<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Customer\CustomerRepository;

/** All queries exclude administrator identities. */
final class CustomerManagementRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function page(string $search, int $offset, int $limit = 25): array
    {
        $search = trim($search);
        if (preg_match('/^.{0,100}$/usD', $search) !== 1 || $offset < 0 || $offset > 100000 ||
            $limit < 1 || $limit > 50) throw new InvalidArgumentException('Neplatné hledání zákazníků.');
        $sql = 'SELECT u.id, u.email, u.display_name, u.phone, u.is_active, u.created_at,
                       (SELECT COUNT(*) FROM shop_orders o WHERE o.user_id=u.id) AS order_count
                FROM users u WHERE u.role=%s';
        $rows = $search === ''
            ? $this->db->query($sql . ' ORDER BY u.id DESC LIMIT %i OFFSET %i', 'customer', $limit + 1, $offset)
            : $this->db->query($sql . ' AND (LOCATE(%s, LOWER(u.email)) > 0 OR
                LOCATE(%s, LOWER(u.display_name)) > 0) ORDER BY u.id DESC LIMIT %i OFFSET %i',
                'customer', strtolower($search), strtolower($search), $limit + 1, $offset);
        return ['items' => array_slice($rows, 0, $limit),
            'nextOffset' => count($rows) > $limit ? $offset + $limit : null];
    }

    public function find(int $id): ?array
    {
        if ($id < 1) return null;
        return $this->db->queryFirstRow('SELECT id, email, display_name, phone, is_active, created_at,
            (SELECT COUNT(*) FROM shop_orders WHERE user_id=users.id) AS order_count,
            (SELECT COUNT(*) FROM customer_addresses WHERE user_id=users.id) AS address_count
            FROM users WHERE id=%i AND role=%s LIMIT 1', $id, 'customer');
    }

    public function recentOrders(int $id): array
    {
        if ($this->find($id) === null) return [];
        return $this->db->query('SELECT id, order_number, total_czk, status, payment_status, created_at
            FROM shop_orders WHERE user_id=%i ORDER BY id DESC LIMIT 10', $id);
    }

    public function create(string $email, string $name, string $password): void
    {
        (new CustomerRepository($this->db))->register($email, $password, $name);
    }

    public function update(int $id, string $email, string $name, string $phone): void
    {
        if ($this->find($id) === null) throw new InvalidArgumentException('Zákazník neexistuje.');
        $email = strtolower(trim($email));
        $name = trim($name);
        $phone = trim($phone);
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            preg_match('/^.{1,120}$/usD', $name) !== 1 ||
            preg_match('/^.{0,40}$/usD', $phone) !== 1 ||
            preg_match('/[\x00-\x1f\x7f]/', $name . $phone)) {
            throw new InvalidArgumentException('Zkontroluj e-mail, jméno a telefon.');
        }
        $existing = $this->db->queryFirstRow('SELECT id FROM users WHERE email=%s LIMIT 1', $email);
        if ($existing !== null && (int) $existing['id'] !== $id) {
            throw new InvalidArgumentException('E-mail už používá jiný účet.');
        }
        $this->db->query('UPDATE users SET email=%s, display_name=%s, phone=%s
            WHERE id=%i AND role=%s', $email, $name, $phone, $id, 'customer');
    }

    public function setActive(int $id, bool $active): void
    {
        if ($this->find($id) === null) throw new InvalidArgumentException('Zákazník neexistuje.');
        $this->db->query('UPDATE users SET is_active=%i WHERE id=%i AND role=%s',
            $active ? 1 : 0, $id, 'customer');
    }

    public function setPassword(int $id, string $password): void
    {
        if ($this->find($id) === null) throw new InvalidArgumentException('Zákazník neexistuje.');
        if (preg_match('/^.{10,}$/usD', $password) !== 1 || strlen($password) > 72) {
            throw new InvalidArgumentException('Heslo musí mít alespoň 10 znaků a nesmí být příliš dlouhé.');
        }
        $this->db->query('UPDATE users SET password_hash=%s, password_changed_at=CURRENT_TIMESTAMP
            WHERE id=%i AND role=%s', password_hash($password, PASSWORD_DEFAULT), $id, 'customer');
    }
}
