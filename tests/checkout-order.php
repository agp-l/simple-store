<?php
declare(strict_types=1);

class MeekroDB
{
    public array $rows = [];
    public int $transactions = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    private array $before = [];

    public function queryFirstField(string $sql, mixed ...$values): int { return 1; }

    public function startTransaction(): void
    {
        $this->transactions++;
        $this->before = $this->rows;
    }

    public function commit(): void { $this->commits++; }
    public function rollback(): void { $this->rollbacks++; $this->rows = $this->before; }

    public function insert(string $table, array $fields): void
    {
        if ($table !== 'shop_orders') throw new RuntimeException('Unexpected table.');
        foreach ($this->rows as $row) {
            if ($row['idempotency_key'] === $fields['idempotency_key'] ||
                $row['variable_symbol'] === $fields['variable_symbol'] ||
                $row['order_token'] === $fields['order_token']) {
                throw new RuntimeException('Duplicate unique key.');
            }
        }
        $this->rows[] = ['id' => count($this->rows) + 1, 'created_at' => '2026-09-29 12:00:00'] + $fields;
    }

    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        foreach ($this->rows as $row) {
            if (str_contains($sql, 'idempotency_key=%s') && $row['idempotency_key'] === $values[0] ||
                str_contains($sql, 'order_token=%s') && $row['order_token'] === $values[0] ||
                str_contains($sql, 'WHERE id=%i') && $row['id'] === $values[0]) {
                return str_contains($sql, 'SELECT payment_method, payment_status')
                    ? array_intersect_key($row, array_flip([
                        'payment_method', 'payment_status', 'order_token',
                        'variable_symbol', 'payment_details_json',
                    ]))
                    : $row;
            }
        }
        return null;
    }

    public function query(string $sql, mixed ...$values): array
    {
        if (str_contains($sql, 'UPDATE shop_orders')) {
            $hasAdmin = str_contains($sql, 'payment_verified_by=%i');
            $id = $hasAdmin ? $values[2] : $values[1];
            $expected = $hasAdmin ? $values[3] : $values[2];
            foreach ($this->rows as &$row) {
                if ($row['id'] === $id && $row['payment_status'] === $expected) {
                    $row['payment_status'] = $values[0];
                    $row['payment_paid_at'] = '2026-09-29 12:10:00';
                    $row['payment_verified_by'] = $hasAdmin ? $values[1] : null;
                }
            }
            unset($row);
            return [];
        }
        if (str_contains($sql, 'FROM shop_orders')) {
            $rows = array_reverse($this->rows);
            if (str_contains($sql, 'WHERE payment_status=%s')) {
                $rows = array_values(array_filter($rows,
                    static fn (array $row): bool => $row['payment_status'] === $values[0]));
                return array_slice($rows, $values[2], $values[1]);
            }
            return array_slice($rows, $values[1], $values[0]);
        }
        throw new RuntimeException('Unexpected SQL query.');
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\BankTransferPayment;
use SimpleStore\Checkout\OrderRepository;

$bank = new BankTransferPayment('CZ58 5500 0000 0012 6509 8001', '1265098001/5500', 'Obchod');
$db = new MeekroDB();
$repository = new OrderRepository($db, $bank, 7);
$items = [[
    'product_key' => str_repeat('a', 32), 'language' => 'cs', 'slug' => 'stan', 'name' => 'Stan',
    'quantity' => 2, 'unit_price_czk' => 500, 'image_path' => '/images/stan.webp', 'options' => [],
]];
$shipping = [
    'method' => 'home', 'label' => 'Doručení na adresu', 'name' => 'Eva Nová',
    'street' => 'Polní 1', 'city' => 'Praha', 'postal_code' => '11000', 'country' => 'CZ',
];
$key = str_repeat('b', 64);
$order = $repository->create(null, 'EVA@example.org', $items, $shipping, 100, $key);
if (count($db->rows) !== 1 || $db->commits !== 1 || $order['total_czk'] !== 1100 ||
    $order['subtotal_czk'] !== 1000 || $order['payment_status'] !== 'pending' ||
    $order['status'] !== 'new' || $order['customer_email'] !== 'eva@example.org' ||
    $order['shipping']['recipient'] !== 'Eva Nová' ||
    !preg_match('/^[0-9]{10}$/D', $order['variable_symbol']) ||
    !preg_match('/^[a-f0-9]{64}$/D', $order['order_token'])) {
    throw new RuntimeException('Order snapshot, total, token or payment state is wrong.');
}
$details = BankTransferPayment::fromOrder($order)->details($order);
if ($details['iban'] !== 'CZ5855000000001265098001' ||
    $details['spayd'] !== 'SPD*1.0*ACC:CZ5855000000001265098001*AM:1100.00*CC:CZK*X-VS:' .
        $order['variable_symbol']) {
    throw new RuntimeException('Bank transfer payload does not match the saved order.');
}
$changedSettings = new BankTransferPayment(
    'CZ5855000000001265098001', '1265098001/5500', 'Nový příjemce'
);
if ($changedSettings->details($order)['recipient'] !== 'Obchod') {
    throw new RuntimeException('Existing payment was silently retargeted after a config change.');
}
$same = $repository->create(null, 'eva@example.org', $items, $shipping, 100, $key);
if (count($db->rows) !== 1 || $same['order_token'] !== $order['order_token'] ||
    $repository->findByToken($order['order_token'])['order_number'] !== $order['order_number'] ||
    $repository->findByToken('guess') !== null || $repository->findById(0) !== null) {
    throw new RuntimeException('Idempotent checkout or private order lookup failed.');
}
try {
    $repository->create(null, 'eve@example.org', $items, $shipping, 100, $key);
    throw new RuntimeException('Reused key with different recipient was accepted.');
} catch (InvalidArgumentException $expected) {
    if (count($db->rows) !== 1) throw new RuntimeException('The old order changed.');
}
$repository->markPaid(1, 4);
$repository->markPaid(1, 9);
if ($db->rows[0]['payment_status'] !== 'paid' || $db->rows[0]['status'] !== 'new' ||
    $db->rows[0]['payment_verified_by'] !== 4 ||
    $db->rows[0]['payment_paid_at'] !== '2026-09-29 12:10:00' ||
    $repository->managementPage(0, 20, 'paid')['items'][0]['id'] !== 1 ||
    $repository->managementPage(0, 20, 'pending')['items'] !== []) {
    throw new RuntimeException('Manual reconciliation changed the wrong status or filter.');
}
$db->rows[0]['payment_method'] = 'comgate';
try {
    $repository->markPaid(1, 4);
    throw new RuntimeException('A provider payment was manually marked as bank transfer.');
} catch (InvalidArgumentException $expected) {
    if ($db->rows[0]['payment_status'] !== 'paid') throw new RuntimeException('Provider payment changed.');
}
$db->rows[0]['payment_method'] = 'bank_transfer';
$db->rows[0]['order_token'] = null;
try {
    $repository->markPaid(1, 4);
    throw new RuntimeException('A legacy order was manually confirmed.');
} catch (InvalidArgumentException $expected) {
}
foreach (['CZ5955000000001265098001', '', 'GB82WEST12345698765432'] as $invalidIban) {
    $rejected = false;
    try {
        new BankTransferPayment($invalidIban, '1265098001/5500', 'Obchod');
    } catch (InvalidArgumentException $expected) {
        $rejected = true;
    }
    if (!$rejected) throw new RuntimeException('Invalid or non-Czech IBAN was accepted.');
}
$rejected = false;
try {
    new BankTransferPayment('CZ5855000000001265098001', '1265098001/0100', 'Obchod');
} catch (InvalidArgumentException $expected) {
    $rejected = true;
}
if (!$rejected) throw new RuntimeException('Bank details mismatch was accepted.');
$rejected = false;
try {
    (new OrderRepository($db))->create(null, 'eva@example.org', $items, $shipping, 100, str_repeat('c', 64));
} catch (RuntimeException $expected) {
    $rejected = true;
}
if (!$rejected) throw new RuntimeException('Checkout without bank settings was accepted.');
echo "Checkout order and bank transfer tests passed.\n";
