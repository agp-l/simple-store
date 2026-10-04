<?php
declare(strict_types=1);

// Deterministic queue exercise without a mail server or MySQL.
class MeekroDB
{
    public int $now = 1000000;
    public array $rows = [];
    public array $orders = [];
    private ?array $snapshot = null;

    public function startTransaction(): void { $this->snapshot = $this->rows; }
    public function commit(): void { $this->snapshot = null; }
    public function rollback(): void
    {
        if ($this->snapshot !== null) $this->rows = $this->snapshot;
        $this->snapshot = null;
    }

    public function queryFirstField(string $sql, mixed ...$args): mixed
    {
        if (str_contains($sql, 'information_schema.TABLES')) return $args[0] === 'shop_mail_outbox' ? 1 : 0;
        if (str_contains($sql, 'information_schema.COLUMNS')) return 1;
        if (str_contains($sql, 'SELECT state FROM shop_mail_outbox')) return $this->rows[$args[0]]['state'] ?? null;
        throw new RuntimeException('Unexpected field query: ' . $sql);
    }

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        if (str_contains($sql, 'FROM shop_orders WHERE id=')) {
            return $this->orders[$args[0]] ?? null;
        }
        if (!str_contains($sql, 'FROM shop_mail_outbox WHERE id=')) {
            throw new RuntimeException('Unexpected row query: ' . $sql);
        }
        $row = $this->rows[$args[0]] ?? null;
        if ($row === null) return null;
        $row['retry_due'] = $row['next_attempt_at'] === null || $row['next_attempt_at'] <= $this->now ? 1 : 0;
        $row['stale_sending'] = $row['attempted_at'] !== null && $row['attempted_at'] <= $this->now - 600 ? 1 : 0;
        return $row;
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, 'SELECT id FROM shop_mail_outbox')) {
            return array_slice(array_values(array_map(
                static fn (array $row): array => ['id' => $row['id']],
                array_filter($this->rows, fn (array $row): bool =>
                    in_array($row['state'], [$args[0], $args[1]], true) &&
                    $row['attempts'] < $args[2] &&
                    ($row['next_attempt_at'] === null || $row['next_attempt_at'] <= $this->now))
            )), 0, $args[3]);
        }
        if (str_contains($sql, 'UPDATE shop_mail_outbox SET state=%s,') &&
            str_contains($sql, 'next_attempt_at=NULL, last_error=%s')) {
            $this->rows[$args[2]]['state'] = $args[0];
            $this->rows[$args[2]]['last_error'] = $args[1];
            $this->rows[$args[2]]['next_attempt_at'] = null;
            return [];
        }
        if (str_contains($sql, 'UPDATE shop_mail_outbox SET state=%s, attempts=')) {
            $this->rows[$args[1]]['state'] = $args[0];
            $this->rows[$args[1]]['attempts']++;
            $this->rows[$args[1]]['attempted_at'] = $this->now;
            $this->rows[$args[1]]['next_attempt_at'] = null;
            return [];
        }
        if (str_contains($sql, 'UPDATE shop_mail_outbox SET state=%s, last_error=%s,')) {
            $id = $args[5];
            $this->rows[$id]['state'] = $args[0];
            $this->rows[$id]['last_error'] = $args[1];
            $this->rows[$id]['next_attempt_at'] = $args[3] === 1 ? null : $this->now + $args[4];
            return [];
        }
        if (str_contains($sql, 'UPDATE shop_mail_outbox SET state=%s, sent_at=')) {
            $this->rows[$args[1]]['state'] = 'sent';
            $this->rows[$args[1]]['next_attempt_at'] = null;
            return [];
        }
        if (str_contains($sql, 'UPDATE shop_mail_outbox SET state=%s,') &&
            str_contains($sql, 'Ručně ověřeno') === false) {
            $this->rows[$args[3]]['state'] = 'failed';
            $this->rows[$args[3]]['next_attempt_at'] = $this->now + $args[2];
            return [];
        }
        throw new RuntimeException('Unexpected SQL: ' . $sql);
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Accounting\MailDeliveryUncertainException;
use SimpleStore\Accounting\OrderMailQueue;

function expectMail(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new MeekroDB();
$guard = new OrderMailQueue($db);
expectMail($guard->enqueueOrder(['payment_method' => 'bank_transfer', 'status' => 'cancelled']) === null,
    'A cancelled order must not enqueue bank payment instructions on an idempotent retry.');
$make = static function (int $id) use ($db): void {
    $db->rows[$id] = ['id' => $id, 'event_key' => 'test:' . $id,
        'recipient_email' => 'customer@example.test', 'subject' => 'Test', 'body_text' => 'Body',
        'body_html' => '<p>Body</p>', 'state' => 'queued', 'attempts' => 0,
        'attempted_at' => null, 'next_attempt_at' => null, 'last_error' => null];
};
$make(1);
$fail = new OrderMailQueue($db, static fn (): bool => false);
expectMail($fail->dispatchDue(1, 'shop@example.test')['failed'] === 1, 'Initial queue attempt failed.');
expectMail($db->rows[1]['next_attempt_at'] === $db->now + 300, 'First retry is not five minutes later.');
expectMail($fail->dispatchDue(1, 'shop@example.test')['selected'] === 0, 'Backoff was ignored.');
for ($attempt = 2; $attempt <= OrderMailQueue::MAX_AUTO_ATTEMPTS; $attempt++) {
    $db->now = $db->rows[1]['next_attempt_at'];
    expectMail($fail->dispatchDue(1, 'shop@example.test')['failed'] === 1, 'Due message was skipped.');
    expectMail($db->rows[1]['attempts'] === $attempt, 'Incorrect attempt count.');
    expectMail($db->rows[1]['next_attempt_at'] === ($attempt === OrderMailQueue::MAX_AUTO_ATTEMPTS
        ? null : $db->now + 300 * 2 ** ($attempt - 1)), 'Backoff/cap is wrong.');
}
expectMail($fail->dispatchDue(1, 'shop@example.test')['selected'] === 0, 'Attempt cap was ignored.');
$accept = new OrderMailQueue($db, static fn (): bool => true);
expectMail($accept->dispatch(1, 'shop@example.test') && $db->rows[1]['attempts'] === 6,
    'Manual retry should still work after automatic cap.');

$make(2);
$unknown = new OrderMailQueue($db, static function (): never {
    throw new MailDeliveryUncertainException('lost final SMTP response');
});
expectMail($unknown->dispatchDue(1, 'shop@example.test')['skipped'] === 1 &&
    $db->rows[2]['state'] === 'sending', 'Ambiguous transport must stay sending.');
expectMail($unknown->dispatchDue(1, 'shop@example.test')['selected'] === 0,
    'Ambiguous transport was automatically retried.');
try {
    $unknown->reconcileSending(2, 'accepted');
    throw new RuntimeException('Fresh sending message was reconciled.');
} catch (InvalidArgumentException $expected) {}
$db->now += 601;
$unknown->reconcileSending(2, 'accepted');
expectMail($db->rows[2]['state'] === 'sent', 'Verified acceptance was not recorded.');

$make(3);
$db->rows[3]['state'] = 'sending';
$db->rows[3]['attempted_at'] = $db->now - 601;
$db->rows[3]['attempts'] = 1;
$unknown->reconcileSending(3, 'not_accepted');
expectMail($db->rows[3]['state'] === 'failed' && $db->rows[3]['next_attempt_at'] === $db->now + 300,
    'Verified rejection did not back off.');
expectMail($accept->dispatch(3, 'shop@example.test'), 'Manual retry after reconciliation failed.');

// A failed checkout confirmation must not ask for payment after settlement or cancellation.
$make(4);
$db->rows[4]['event_key'] = 'order:40';
$db->orders[40] = ['status' => 'new', 'payment_status' => 'paid'];
expectMail($accept->dispatchDue(1, 'shop@example.test')['skipped'] === 1 &&
    $db->rows[4]['state'] === 'suppressed' && $db->rows[4]['attempts'] === 0,
    'The worker sent old payment instructions for a paid order.');
$make(5);
$db->rows[5]['event_key'] = 'order:50';
$db->orders[50] = ['status' => 'cancelled', 'payment_status' => 'pending'];
expectMail($accept->dispatchDue(1, 'shop@example.test')['skipped'] === 1 &&
    $db->rows[5]['state'] === 'suppressed',
    'The worker sent payment instructions after cancellation.');
$make(6);
$db->rows[6]['event_key'] = 'order:60';
expectMail($accept->dispatchDue(1, 'shop@example.test')['skipped'] === 1 &&
    $db->rows[6]['state'] === 'suppressed',
    'The worker sent an order confirmation after deletion.');
$make(7);
$db->rows[7]['event_key'] = 'order:70';
$db->orders[70] = ['status' => 'new', 'payment_status' => 'pending'];
expectMail($accept->dispatchDue(1, 'shop@example.test')['sent'] === 1 &&
    $db->rows[7]['state'] === 'sent',
    'An active unpaid order confirmation was suppressed.');
echo "Bounded mail worker and ambiguous SMTP state OK\n";
