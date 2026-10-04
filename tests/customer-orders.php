<?php
declare(strict_types=1);

class MeekroDB
{
    public array $users = [];
    public array $orders = [];
    public function startTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function queryFirstField(string $sql, mixed ...$args): int { return 0; }

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        if (str_contains($sql, 'FROM users')) {
            foreach ($this->users as $row) {
                if (str_contains($sql, 'WHERE id=%i') && $row['id'] === $args[0] &&
                    $row['role'] === $args[1] && $row['is_active'] === 1) return $row;
                if (str_contains($sql, 'WHERE email=%s') && $row['email'] === $args[0]) return $row;
            }
        }
        if (str_contains($sql, 'FROM shop_orders')) {
            foreach ($this->orders as $row) {
                if (str_contains($sql, 'WHERE user_id=%i AND id=%i') &&
                    $row['user_id'] === $args[0] && $row['id'] === $args[1]) return $row;
                if (str_contains($sql, 'WHERE order_token=%s') && $row['order_token'] === $args[0]) return $row;
            }
        }
        return null;
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, 'UPDATE users SET email=')) {
            $this->users[$args[1]]['email'] = $args[0];
            return [];
        }
        if (str_contains($sql, 'UPDATE shop_orders SET user_id=')) {
            $id = $args[1];
            if ($this->orders[$id]['user_id'] === null && $this->orders[$id]['order_token'] === $args[2]) {
                $this->orders[$id]['user_id'] = $args[0];
            }
            return [];
        }
        if (str_contains($sql, 'FROM shop_orders')) {
            $filtered = array_filter($this->orders, static fn (array $row): bool =>
                $row['user_id'] === $args[0] &&
                in_array($row['status'], ['completed', 'cancelled', 'test'], true) === str_contains($sql, 'status IN'));
            usort($filtered, static fn (array $left, array $right): int => $right['id'] <=> $left['id']);
            return array_slice($filtered, $args[5], $args[4]);
        }
        throw new RuntimeException('Unexpected SQL in customer-order test.');
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Customer\CustomerRepository;

$db = new MeekroDB();
$db->users[1] = ['id' => 1, 'email' => 'eva@example.org', 'role' => 'customer',
    'is_active' => 1, 'password_hash' => password_hash('correct-password', PASSWORD_DEFAULT)];
$db->users[2] = ['id' => 2, 'email' => 'other@example.org', 'role' => 'customer',
    'is_active' => 1, 'password_hash' => password_hash('another-password', PASSWORD_DEFAULT)];
$token = str_repeat('a', 64);
foreach ([
    1 => [1, 'new', 'eva@example.org', str_repeat('b', 64)],
    2 => [1, 'completed', 'eva@example.org', str_repeat('c', 64)],
    3 => [2, 'shipped', 'other@example.org', str_repeat('d', 64)],
    4 => [null, 'new', 'eva@example.org', $token],
    5 => [1, 'shipped', 'eva@example.org', str_repeat('e', 64)],
] as $id => [$owner, $status, $email, $orderToken]) {
    $db->orders[$id] = [
        'id' => $id, 'user_id' => $owner, 'status' => $status,
        'customer_email' => $email, 'order_token' => $orderToken,
        'order_number' => 'DB-' . $id, 'created_at' => '2026-09-29',
        'total_czk' => 100, 'subtotal_czk' => 90, 'shipping_czk' => 10,
        'payment_status' => 'pending', 'payment_method' => 'bank_transfer',
        'items_json' => '[{"name":"Batoh","quantity":1,"unit_price_czk":90}]',
        'shipping_json' => '{"recipient":"Eva","city":"Brno"}',
        'variable_symbol' => '1234567890', 'payment_due_at' => null,
    ];
}
$repo = new CustomerRepository($db);
$active = $repo->orderPage(1, false, 0, 1);
if (array_column($active['items'], 'id') !== [5] || $active['nextOffset'] !== 1 ||
    array_column($repo->orderPage(1, false, 1)['items'], 'id') !== [1] ||
    array_column($repo->orderPage(1, true)['items'], 'id') !== [2] ||
    $repo->order(1, 3) !== null || $repo->order(2, 1) !== null ||
    $repo->order(1, 1)['items'][0]['name'] !== 'Batoh') {
    throw new RuntimeException('Customer order paging, history, detail or isolation failed.');
}
foreach ([['changeEmail', [1, 'wrong-password', 'new@example.org']],
    ['changeEmail', [1, 'correct-password', 'other@example.org']],
    ['claimGuestOrder', [2, $token]], ['claimGuestOrder', [1, str_repeat('f', 64)]]] as [$method, $params]) {
    try {
        $repo->$method(...$params);
        throw new RuntimeException('Unauthorized account operation succeeded: ' . $method);
    } catch (InvalidArgumentException $expected) {
    }
}
$repo->claimGuestOrder(1, $token);
if ($db->orders[4]['user_id'] !== 1 || array_column($repo->orderPage(1, false)['items'], 'id') !== [5, 4, 1]) {
    throw new RuntimeException('Guest order claiming failed.');
}
$repo->changeEmail(1, 'correct-password', 'NEW@Example.org');
if ($db->users[1]['email'] !== 'new@example.org') throw new RuntimeException('Email change failed.');
echo "Customer order tests passed.\n";
