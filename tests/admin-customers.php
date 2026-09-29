<?php
declare(strict_types=1);

class MeekroDB
{
    public array $users = [];

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        foreach ($this->users as $row) {
            if (str_contains($sql, 'WHERE id=%i') && $row['id'] === $args[0] &&
                $row['role'] === $args[1]) return $row + ['order_count' => 2, 'address_count' => 1];
            if (str_contains($sql, 'WHERE email=%s') && $row['email'] === $args[0]) return $row;
        }
        return null;
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, 'FROM shop_orders WHERE user_id=%i ORDER BY id DESC')) return [];
        if (str_contains($sql, 'SELECT u.id')) {
            $rows = array_values(array_filter($this->users, static fn (array $row): bool =>
                $row['role'] === $args[0] &&
                (count($args) === 3 || str_contains(strtolower($row['email'] . $row['display_name']), $args[1]))));
            usort($rows, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
            $start = $args[count($args) - 1];
            $length = $args[count($args) - 2];
            return array_map(static fn (array $row): array => $row + ['order_count' => 2],
                array_slice($rows, $start, $length));
        }
        if (str_contains($sql, 'UPDATE users SET email=')) {
            $id = $args[3];
            if ($this->users[$id]['role'] === $args[4]) {
                [$this->users[$id]['email'], $this->users[$id]['display_name'], $this->users[$id]['phone']] =
                    array_slice($args, 0, 3);
            }
        } elseif (str_contains($sql, 'UPDATE users SET is_active=')) {
            if ($this->users[$args[1]]['role'] === $args[2]) {
                $this->users[$args[1]]['is_active'] = $args[0];
            }
        } elseif (str_contains($sql, 'UPDATE users SET password_hash=')) {
            if ($this->users[$args[1]]['role'] === $args[2]) {
                $this->users[$args[1]]['password_hash'] = $args[0];
            }
        } else {
            throw new RuntimeException('Unexpected customer admin SQL.');
        }
        return [];
    }

    public function insert(string $table, array $fields): void
    {
        $id = max(array_keys($this->users)) + 1;
        $this->users[$id] = ['id' => $id] + $fields;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Admin\CustomerManagementRepository;

$db = new MeekroDB();
$db->users[1] = ['id' => 1, 'role' => 'admin', 'email' => 'admin@example.org',
    'display_name' => 'Správce', 'phone' => '', 'is_active' => 1];
$db->users[2] = ['id' => 2, 'role' => 'customer', 'email' => 'eva@example.org',
    'display_name' => 'Eva', 'phone' => '', 'is_active' => 1,
    'password_hash' => password_hash('old-password-123', PASSWORD_DEFAULT)];
$manager = new CustomerManagementRepository($db);
if ($manager->find(1) !== null || count($manager->page('', 0)['items']) !== 1 ||
    $manager->page('missing', 0)['items'] !== []) {
    throw new RuntimeException('Admin user list included administrators or ignored search.');
}
foreach (['update', 'setActive', 'setPassword'] as $action) {
    try {
        $params = match ($action) {
            'update' => [1, 'new@example.org', 'Cizí', ''],
            'setActive' => [1, false], 'setPassword' => [1, 'new-password-123'],
        };
        $manager->$action(...$params);
        throw new RuntimeException('Administrator account modified by customer manager.');
    } catch (InvalidArgumentException $expected) {
    }
}
$manager->update(2, 'EVA.NEW@example.org', 'Eva Nová', '+420 111');
$manager->setPassword(2, 'new-password-456');
$manager->setActive(2, false);
if ($db->users[2]['email'] !== 'eva.new@example.org' ||
    $db->users[2]['display_name'] !== 'Eva Nová' || $db->users[2]['is_active'] !== 0 ||
    !password_verify('new-password-456', $db->users[2]['password_hash'])) {
    throw new RuntimeException('Customer editing, blocking or password reset failed.');
}
$manager->create('third@example.org', 'Třetí', 'new-password-789');
if ($db->users[3]['role'] !== 'customer' || $db->users[1]['is_active'] !== 1) {
    throw new RuntimeException('Creating a customer changed another role.');
}
echo "Admin customer tests passed.\n";
