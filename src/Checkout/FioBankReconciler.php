<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use SimpleStore\Accounting\OrderMailQueue;
use Throwable;

/** Periodic Fio statement import. A unique movement may settle only one pending bank order. */
final class FioBankReconciler
{
    private ?bool $hasFinancialEvents = null;

    public function __construct(
        private MeekroDB $db,
        private array $config,
        private ?FioStatementClient $client = null,
        private ?OrderMailQueue $mail = null,
        private string $sender = ''
    ) {
    }

    public function installed(): bool
    {
        foreach (['shop_fio_requests', 'shop_fio_matches'] as $table) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table) === 0) return false;
        }
        return true;
    }

    public function sync(): array
    {
        if (empty($this->config['enabled'])) throw new InvalidArgumentException('Ověřování přes Fio je vypnuté.');
        $token = (string) ($this->config['token'] ?? '');
        $account = (string) ($this->config['account_display'] ?? '');
        if ($token === '' || FioTransferMatcher::canonicalAccount($account) === '') {
            throw new InvalidArgumentException('Vyplň token a účet Fio v nastavení plateb.');
        }
        if (!$this->installed()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v administraci.');

        $oldest = $this->db->queryFirstField(
            'SELECT MIN(created_at) FROM shop_orders
             WHERE payment_method=%s AND payment_status=%s AND status=%s',
            'bank_transfer', 'pending', 'new');
        $result = ['checked' => 0, 'matched' => 0, 'ignored' => 0];
        if ($oldest === null) return $result;

        // The order timestamp is stored in UTC. Using its UTC date also covers payments
        // made around midnight in Prague. Fio permits the last 90 days without SCA.
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague'));
        $earliest = $today->modify('-89 days')->format('Y-m-d');
        $from = max($earliest, substr((string) $oldest, 0, 10));
        $fingerprint = hash('sha256', $token);
        $this->reserveRequest($fingerprint);
        // Period export does not advance Fio's server-side last-download cursor. The overlapping
        // window permits retries after errors and unique match IDs make repeated imports safe.
        $statement = ($this->client ?? new FioStatementClient())->fetch($token,
            $from, $today->format('Y-m-d'));
        $account = FioTransferMatcher::account($statement['info'], $account);
        foreach ($statement['transactions'] as $row) {
            $result['checked']++;
            $movement = is_array($row) ? FioTransferMatcher::movement($row) : null;
            if ($movement === null || !$this->settle($movement, $account)) {
                $result['ignored']++;
                continue;
            }
            $result['matched']++;
        }
        return $result;
    }

    private function reserveRequest(string $fingerprint): void
    {
        $this->db->startTransaction();
        try {
            $this->db->query('INSERT IGNORE INTO shop_fio_requests (token_hash, last_requested_at)
                VALUES (%s, NULL)', $fingerprint);
            $row = $this->db->queryFirstRow(
                'SELECT TIMESTAMPDIFF(SECOND, last_requested_at, UTC_TIMESTAMP()) AS elapsed
                 FROM shop_fio_requests WHERE token_hash=CONVERT(%s USING ascii) COLLATE ascii_bin
                 LIMIT 1 FOR UPDATE', $fingerprint);
            if ($row === null || $row['elapsed'] !== null && (int) $row['elapsed'] < 35) {
                throw new InvalidArgumentException('Další kontrolu Fio lze spustit za 35 sekund.');
            }
            $this->db->query('UPDATE shop_fio_requests SET last_requested_at=UTC_TIMESTAMP()
                WHERE token_hash=CONVERT(%s USING ascii) COLLATE ascii_bin', $fingerprint);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function settle(array $movement, string $account): bool
    {
        $this->db->startTransaction();
        try {
            $existing = $this->db->queryFirstRow(
                'SELECT order_id FROM shop_fio_matches
                 WHERE account_key=CONVERT(%s USING ascii) COLLATE ascii_bin
                 AND movement_id=CONVERT(%s USING ascii) COLLATE ascii_bin LIMIT 1',
                $account, $movement['id']);
            if ($existing !== null) {
                $this->db->commit();
                return false;
            }
            $order = $this->db->queryFirstRow(
                'SELECT id, status, payment_method, payment_status, variable_symbol,
                    total_czk, payment_details_json FROM shop_orders
                 WHERE variable_symbol=CONVERT(%s USING ascii) COLLATE ascii_bin
                 LIMIT 1 FOR UPDATE', $movement['vs']);
            if ($order === null || !FioTransferMatcher::matchesOrder($order, $movement, $account)) {
                $this->db->commit();
                return false;
            }
            $orderId = (int) $order['id'];
            // A manual correction must not be undone by a second transfer with the same VS.
            if ($this->db->queryFirstRow(
                'SELECT movement_id FROM shop_fio_matches WHERE order_id=%i LIMIT 1', $orderId
            ) !== null) {
                $this->db->commit();
                return false;
            }
            // A correction is an explicit instruction to leave this order unpaid.
            // A historical positive movement may still be in the 89-day window,
            // even after a bank reversal or a mistaken manual confirmation.
            if ($this->hasFinancialEvents() && $this->db->queryFirstRow(
                'SELECT id FROM shop_order_financial_events
                 WHERE order_id=%i AND action=%s LIMIT 1', $orderId, 'payment_correction'
            ) !== null) {
                $this->db->commit();
                return false;
            }
            $this->db->query('INSERT INTO shop_fio_matches
                (account_key, movement_id, order_id, amount_czk)
                VALUES (%s, %s, %i, %i)', $account, $movement['id'], $orderId, $movement['amount']);
            $this->db->query('UPDATE shop_orders SET payment_status=%s, payment_paid_at=UTC_TIMESTAMP(),
                payment_verified_by=NULL, provider_reference=%s
                WHERE id=%i AND payment_status=%s', 'paid', 'Fio:' . $movement['id'], $orderId, 'pending');
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        if ($this->mail !== null) {
            try { $this->mail->notifyStage($orderId, 'paid', $this->sender); }
            catch (Throwable $error) { error_log('Fio payment email for order ' . $orderId . ' failed.'); }
        }
        return true;
    }

    private function hasFinancialEvents(): bool
    {
        if ($this->hasFinancialEvents === null) {
            $this->hasFinancialEvents = (int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                'shop_order_financial_events'
            ) === 1;
        }
        return $this->hasFinancialEvents;
    }
}
