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
        foreach (['shop_users', 'shop_customer_addresses', 'shop_orders'] as $table) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            ) === 0) return false;
        }
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', 'shop_users', 'email'
        ) > 0;
    }

    public function byEmail(string $email): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT id, email, display_name, phone, password_hash FROM shop_users
             WHERE email=%s AND role=%s AND is_active=1 LIMIT 1', self::email($email), 'customer'
        );
    }

    public function byId(int $id): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT id, email, display_name, phone, password_hash FROM shop_users
             WHERE id=%i AND role=%s AND is_active=1 LIMIT 1', $id, 'customer'
        );
    }

    public function register(string $email, string $password, string $name): void
    {
        $email = self::email($email);
        $name = self::shortText($name, 120, 'Jméno');
        self::password($password);
        if ($this->db->queryFirstRow('SELECT id FROM shop_users WHERE email=%s LIMIT 1', $email) !== null) {
            throw new InvalidArgumentException('Tento e-mail je již zaregistrovaný.');
        }
        $this->db->insert('shop_users', [
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
            'UPDATE shop_users SET display_name=%s, phone=%s WHERE id=%i AND role=%s AND is_active=1',
            $name, $phone, $userId, 'customer'
        );
    }

    /** Reconfirm the account password before changing its login identifier. */
    public function changeEmail(int $userId, string $currentPassword, string $replacement): void
    {
        $user = $this->byId($userId);
        if ($user === null || !password_verify($currentPassword, $user['password_hash'])) {
            throw new InvalidArgumentException('Současné heslo není správné.');
        }
        $replacement = self::email($replacement);
        if ($replacement === $user['email']) return;
        if ($this->db->queryFirstRow('SELECT id FROM shop_users WHERE email=%s LIMIT 1', $replacement) !== null) {
            throw new InvalidArgumentException('Tento e-mail už používá jiný účet.');
        }
        $this->db->query(
            'UPDATE shop_users SET email=%s WHERE id=%i AND role=%s AND is_active=1',
            $replacement, $userId, 'customer'
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
            'UPDATE shop_users SET password_hash=%s, password_changed_at=CURRENT_TIMESTAMP
             WHERE id=%i AND role=%s AND is_active=1',
            password_hash($replacement, PASSWORD_DEFAULT), $userId, 'customer'
        );
    }

    public function addresses(int $userId): array
    {
        return $this->db->query(
            'SELECT id, label, recipient, company, street, city, postal_code, country, phone
             FROM shop_customer_addresses WHERE user_id=%i ORDER BY id DESC', $userId
        );
    }

    public function address(int $userId, int $id): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT id, label, recipient, company, street, city, postal_code, country, phone
             FROM shop_customer_addresses WHERE user_id=%i AND id=%i LIMIT 1', $userId, $id
        );
    }

    public function saveAddress(int $userId, ?int $id, array $input): void
    {
        $fields = [
            'label' => self::shortText((string) ($input['label'] ?? ''), 60, 'Označení'),
            'recipient' => self::shortText((string) ($input['recipient'] ?? ''), 120, 'Příjemce'),
            'company' => self::shortText((string) ($input['company'] ?? ''), 120, 'Firma', true),
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
                'UPDATE shop_customer_addresses SET label=%s, recipient=%s, company=%s, street=%s, city=%s,
                 postal_code=%s, country=%s, phone=%s WHERE id=%i AND user_id=%i',
                ...array_merge(array_values($fields), [$id, $userId])
            );
            return;
        }
        if (count($this->addresses($userId)) >= 20) {
            throw new InvalidArgumentException('Můžeš mít nejvýše 20 uložených adres.');
        }
        $this->db->insert('shop_customer_addresses', ['user_id' => $userId] + $fields);
    }

    public function removeAddress(int $userId, int $id): void
    {
        if ($id < 1 || $this->address($userId, $id) === null) {
            throw new InvalidArgumentException('Adresa neexistuje.');
        }
        $this->db->query('DELETE FROM shop_customer_addresses WHERE id=%i AND user_id=%i', $id, $userId);
    }

    public function orders(int $userId): array
    {
        $checkoutColumns = (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME IN (%s, %s)',
            'shop_orders', 'order_token', 'payment_status'
        ) === 2;
        return $this->db->query(
            'SELECT id, order_number, status, total_czk, created_at' .
            ($checkoutColumns ? ', order_token, payment_status' : '') . ' FROM shop_orders
             WHERE user_id=%i ORDER BY id DESC LIMIT 50', $userId
        );
    }

    /** Pages stay scoped to the signed-in user, including old and active orders. */
    public function orderPage(int $userId, bool $history, int $offset = 0, int $limit = 20): array
    {
        if ($userId < 1 || $offset < 0 || $offset > 100000 || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Neplatná stránka objednávek.');
        }
        $condition = $history ? 'IN' : 'NOT IN';
        $rows = $this->db->query(
            'SELECT id, order_number, status, total_czk, created_at, order_token, payment_status
             FROM shop_orders WHERE user_id=%i AND status ' . $condition .
            ' (%s, %s, %s) ORDER BY id DESC LIMIT %i OFFSET %i',
            $userId, 'completed', 'cancelled', 'test', $limit + 1, $offset
        );
        return ['items' => array_slice($rows, 0, $limit),
            'nextOffset' => count($rows) > $limit ? $offset + $limit : null];
    }

    public function order(int $userId, int $id): ?array
    {
        if ($userId < 1 || $id < 1) return null;
        $row = $this->db->queryFirstRow(
            'SELECT id, order_number, status, created_at, total_czk, subtotal_czk,
                    shipping_czk, items_json, shipping_json, payment_method, payment_status,
                    order_token, variable_symbol, payment_due_at
             FROM shop_orders WHERE user_id=%i AND id=%i LIMIT 1', $userId, $id
        );
        if ($row === null) return null;
        $row['items'] = json_decode((string) $row['items_json'], true);
        $row['shipping'] = json_decode((string) $row['shipping_json'], true);
        $row['items'] = is_array($row['items']) ? $row['items'] : [];
        $row['shipping'] = is_array($row['shipping']) ? $row['shipping'] : [];
        unset($row['items_json'], $row['shipping_json']);
        return $row;
    }

    /** A guest order requires possession of its private receipt token and the matching email. */
    public function claimGuestOrder(int $userId, string $token): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Zadej platný soukromý odkaz na objednávku.');
        }
        $this->db->startTransaction();
        try {
            $user = $this->byId($userId);
            $row = $this->db->queryFirstRow(
                'SELECT id, user_id, customer_email FROM shop_orders WHERE order_token=%s LIMIT 1 FOR UPDATE', $token
            );
            if ($user === null || $row === null || $row['user_id'] !== null ||
                strtolower((string) $row['customer_email']) !== $user['email']) {
                throw new InvalidArgumentException('Objednávku nelze přiřadit. Zkontroluj její odkaz a e-mail.');
            }
            $this->db->query(
                'UPDATE shop_orders SET user_id=%i WHERE id=%i AND user_id IS NULL AND order_token=%s',
                $userId, (int) $row['id'], $token
            );
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                'shop_after_sales_cases'
            ) > 0) {
                $this->db->query('UPDATE shop_after_sales_cases SET user_id=%i
                    WHERE order_id=%i AND user_id IS NULL AND customer_email=%s',
                    $userId, (int) $row['id'], $user['email']);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
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
        if (preg_match('/^.{10,}$/usD', $password) !== 1 || strlen($password) > 72) {
            throw new InvalidArgumentException('Heslo musí mít alespoň 10 znaků a nesmí být příliš dlouhé.');
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
