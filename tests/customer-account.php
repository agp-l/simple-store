<?php
declare(strict_types=1);

class MeekroDB
{
    public array $users = [];
    public array $addresses = [];
    public array $orders = [];
    private int $nextId = 1;

    public function queryFirstField(string $sql, mixed ...$args): int { return 1; }

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        if (str_contains($sql, 'SELECT id, label, recipient')) {
            foreach ($this->addresses as $address) {
                if ($address['user_id'] === $args[0] && $address['id'] === $args[1]) return $address;
            }
            return null;
        }
        foreach ($this->users as $user) {
            if (str_contains($sql, 'WHERE email=%s') && $user['email'] === $args[0] &&
                (!str_contains($sql, 'role=%s') || $user['role'] === $args[1])) return $user;
            if (str_contains($sql, 'WHERE id=%i') && $user['id'] === $args[0] &&
                $user['role'] === $args[1] && $user['is_active'] === 1) return $user;
        }
        return null;
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, 'SELECT id, label, recipient')) {
            return array_values(array_filter($this->addresses, static fn (array $row): bool =>
                $row['user_id'] === $args[0]));
        }
        if (str_contains($sql, 'FROM shop_orders')) {
            return array_values(array_filter($this->orders, static fn (array $row): bool =>
                $row['user_id'] === $args[0]));
        }
        if (str_contains($sql, 'SET display_name=')) {
            $this->users[$args[2]]['display_name'] = $args[0];
            $this->users[$args[2]]['phone'] = $args[1];
        }
        if (str_contains($sql, 'SET password_hash=')) {
            $this->users[$args[1]]['password_hash'] = $args[0];
        }
        if (str_contains($sql, 'UPDATE shop_customer_addresses')) {
            $this->addresses[$args[8]] = ['id' => $args[8], 'user_id' => $args[9]] +
                array_combine(['label', 'recipient', 'company', 'street', 'city', 'postal_code', 'country', 'phone'],
                    array_slice($args, 0, 8));
        }
        if (str_contains($sql, 'DELETE FROM shop_customer_addresses')) unset($this->addresses[$args[0]]);
        return [];
    }

    public function insert(string $table, array $fields): void
    {
        $id = $this->nextId++;
        if ($table === 'shop_users') $this->users[$id] = ['id' => $id] + $fields;
        if ($table === 'shop_customer_addresses') $this->addresses[$id] = ['id' => $id] + $fields;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Customer\CustomerAuth;
use SimpleStore\Customer\CustomerRepository;

$db = new MeekroDB();
$customers = new CustomerRepository($db);
$customers->register('TEST@Example.org', 'long-password-123', 'První zákazník');
$first = $customers->byEmail('test@example.org');
if (!$customers->installed() || $first === null || $first['email'] !== 'test@example.org' ||
    $customers->byEmail('missing@example.org') !== null) {
    throw new RuntimeException('Customer registration or email normalization failed.');
}
$db->users[999] = ['id' => 999, 'email' => 'admin@example.org', 'role' => 'admin',
    'is_active' => 1, 'password_hash' => password_hash('secret', PASSWORD_DEFAULT)];
if ($customers->byEmail('admin@example.org') !== null) {
    throw new RuntimeException('Administrator accounts must never log in as customers.');
}
$auth = new CustomerAuth($customers, '/simple-store/');
if ($auth->signedIn() || $auth->signIn('test@example.org', 'wrong') ||
    !$auth->validToken($auth->token()) || !$auth->signIn('test@example.org', 'long-password-123') ||
    !$auth->signedIn()) {
    throw new RuntimeException('Customer login and CSRF failed.');
}
$customers->register('second@example.org', 'long-password-456', 'Druhý zákazník');
$second = $customers->byEmail('second@example.org');
$customers->updateProfile($first['id'], 'Nové jméno', '+420 111 222 333');
$customers->saveAddress($first['id'], null, [
    'label' => 'Domů', 'recipient' => 'Nové jméno', 'company' => 'Naše & Cesty s.r.o.', 'street' => 'Polní 1',
    'city' => 'Praha', 'postal_code' => '110 00', 'country' => 'CZ', 'phone' => '',
]);
$id = $customers->addresses($first['id'])[0]['id'];
if ($customers->address($first['id'], $id)['company'] !== 'Naše & Cesty s.r.o.') {
    throw new RuntimeException('Saved company was not retained with the customer address.');
}
$customers->saveAddress($first['id'], $id, [
    'label' => 'Domů', 'recipient' => 'Nové jméno', 'company' => 'Nová firma s.r.o.',
    'street' => 'Polní 1', 'city' => 'Praha', 'postal_code' => '110 00',
    'country' => 'CZ', 'phone' => '',
]);
if ($customers->address($first['id'], $id)['company'] !== 'Nová firma s.r.o.') {
    throw new RuntimeException('Company changes were not retained on the saved address.');
}
if ($customers->addresses($second['id']) !== [] ||
    $customers->address($second['id'], $id) !== null) {
    throw new RuntimeException('An address leaked across customers.');
}
try {
    $customers->removeAddress($second['id'], $id);
    throw new RuntimeException('Another customer removed an address.');
} catch (InvalidArgumentException $expected) {
}
try {
    $customers->saveAddress($second['id'], $id, [
        'label' => 'Cizí', 'recipient' => 'Druhý zákazník', 'street' => 'U lesa 2',
        'city' => 'Brno', 'postal_code' => '602 00', 'country' => 'CZ', 'phone' => '',
    ]);
    throw new RuntimeException('Another customer edited an address.');
} catch (InvalidArgumentException $expected) {
}
$customers->changePassword($first['id'], 'long-password-123', 'new-long-password-456');
if ($auth->signedIn() || $auth->signIn('test@example.org', 'long-password-123') ||
    !$auth->signIn('test@example.org', 'new-long-password-456')) {
    throw new RuntimeException('Changing a password must invalidate the old session.');
}
$db->orders[] = ['user_id' => $first['id'], 'order_number' => 'DB-001'];
if (count($customers->orders($first['id'])) !== 1 || $customers->orders($second['id']) !== []) {
    throw new RuntimeException('Orders are not scoped to their customer.');
}
$customers->removeAddress($first['id'], $id);
if ($customers->addresses($first['id']) !== []) throw new RuntimeException('Address removal failed.');
$auth->signOut();
if ($auth->signedIn()) throw new RuntimeException('Customer logout failed.');
echo "Customer account tests passed.\n";
