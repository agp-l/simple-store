<?php
declare(strict_types=1);

namespace SimpleStore\Customer;

use InvalidArgumentException;
use MeekroDB;

/** Customer data is scoped by the authenticated user ID in every query. */
final class CustomerRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        foreach (['users', 'customer_addresses', 'shop_orders'] as $table) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            ) === 0) return false;
        }
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', 'users', 'email'
        ) > 0;
    }

    public function byEmail(string $email): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT id, email, display_name, phone, password_hash FROM users
             WHERE email=%s AND role=%s AND is_active=1 LIMIT 1', self::email($email), 'customer'
        );
    }

    public function byId(int $id): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT id, email, display_name, phone, password_hash FROM users
             WHERE id=%i AND role=%s AND is_active=1 LIMIT 1', $id, 'customer'
        );
    }

    public function register(string $email, string $password, string $name): void
    {
        $email = self::email($email);
        $name = self::shortText($name, 120, 'Jméno');
        self::password($password);
        if ($this->db->queryFirstRow('SELECT id FROM users WHERE email=%s LIMIT 1', $email) !== null) {
            throw new InvalidArgumentException('Tento e-mail je již zaregistrovaný.');
        }
        $this->db->insert('users', [
            'username' => 'customer_' . bin2hex(random_bytes(12)),
            'email' => $email,
            'display_name' => $name,
            'phone' => '',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'customer',
            'is_active' => 1,
        ]);
    }

    public function updateProfile(int $userId, string $name, string $phone): void
    {
        $name = self::shortText($name, 120, 'Jméno');
        $phone = self::shortText($phone, 40, 'Telefon', true);
        $this->db->query(
            'UPDATE users SET display_name=%s, phone=%s WHERE id=%i AND role=%s AND is_active=1',
            $name, $phone, $userId, 'customer'
        );
    }

    public function changePassword(int $userId, string $current, string $replacement): void
    {
        $user = $this->byId($userId);
        if ($user === null || !password_verify($current, $user['password_hash'])) {
            throw new InvalidArgumentException('Současné heslo není správné.');
        }
        self::password($replacement);
        $this->db->query(
            'UPDATE users SET password_hash=%s, password_changed_at=CURRENT_TIMESTAMP
             WHERE id=%i AND role=%s AND is_active=1',
            password_hash($replacement, PASSWORD_DEFAULT), $userId, 'customer'
        );
    }

    public function addresses(int $userId): array
    {
        return $this->db->query(
            'SELECT id, label, recipient, street, city, postal_code, country, phone
             FROM customer_addresses WHERE user_id=%i ORDER BY id DESC', $userId
        );
    }

    public function address(int $userId, int $id): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT id, label, recipient, street, city, postal_code, country, phone
             FROM customer_addresses WHERE user_id=%i AND id=%i LIMIT 1', $userId, $id
        );
    }

    public function saveAddress(int $userId, ?int $id, array $input): void
    {
        $fields = [
            'label' => self::shortText((string) ($input['label'] ?? ''), 60, 'Označení'),
            'recipient' => self::shortText((string) ($input['recipient'] ?? ''), 120, 'Příjemce'),
            'street' => self::shortText((string) ($input['street'] ?? ''), 190, 'Ulice a číslo'),
            'city' => self::shortText((string) ($input['city'] ?? ''), 120, 'Město'),
            'postal_code' => self::shortText((string) ($input['postal_code'] ?? ''), 20, 'PSČ'),
            'country' => strtoupper(trim((string) ($input['country'] ?? 'CZ'))),
            'phone' => self::shortText((string) ($input['phone'] ?? ''), 40, 'Telefon', true),
        ];
        if (preg_match('/^[A-Z]{2}$/D', $fields['country']) !== 1) {
            throw new InvalidArgumentException('Zadej dvoupísmenný kód země, například CZ.');
        }
        if ($id !== null) {
            if ($id < 1 || $this->address($userId, $id) === null) {
                throw new InvalidArgumentException('Adresa neexistuje.');
            }
            $this->db->query(
                'UPDATE customer_addresses SET label=%s, recipient=%s, street=%s, city=%s,
                 postal_code=%s, country=%s, phone=%s WHERE id=%i AND user_id=%i',
                ...array_merge(array_values($fields), [$id, $userId])
            );
            return;
        }
        if (count($this->addresses($userId)) >= 20) {
            throw new InvalidArgumentException('Můžeš mít nejvýše 20 uložených adres.');
        }
        $this->db->insert('customer_addresses', ['user_id' => $userId] + $fields);
    }

    public function removeAddress(int $userId, int $id): void
    {
        if ($id < 1 || $this->address($userId, $id) === null) {
            throw new InvalidArgumentException('Adresa neexistuje.');
        }
        $this->db->query('DELETE FROM customer_addresses WHERE id=%i AND user_id=%i', $id, $userId);
    }

    public function orders(int $userId): array
    {
        $checkoutColumns = (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME IN (%s, %s)',
            'shop_orders', 'order_token', 'payment_status'
        ) === 2;
        return $this->db->query(
            'SELECT order_number, status, total_czk, created_at' .
            ($checkoutColumns ? ', order_token, payment_status' : '') . ' FROM shop_orders
             WHERE user_id=%i ORDER BY id DESC LIMIT 50', $userId
        );
    }

    private static function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Zadej platnou e-mailovou adresu.');
        }
        return $email;
    }

    private static function password(string $password): void
    {
        if (preg_match('/^.{12,}$/usD', $password) !== 1 || strlen($password) > 72) {
            throw new InvalidArgumentException('Heslo musí mít alespoň 12 znaků a nesmí být příliš dlouhé.');
        }
    }

    private static function shortText(string $value, int $max, string $label, bool $optional = false): string
    {
        $value = trim($value);
        if ((!$optional && $value === '') || preg_match('/^.{0,' . $max . '}$/usD', $value) !== 1 ||
            preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Zkontroluj pole: ' . $label . '.');
        }
        return $value;
    }
}
