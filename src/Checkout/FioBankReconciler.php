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

    /** Manual checks include overdue orders; workers use the bounded automatic schedule. */
    public function sync(bool $manual = true, ?int $orderId = null): array
    {
        if (empty($this->config['enabled'])) throw new InvalidArgumentException('Ověřování přes Fio je vypnuté.');
        $token = (string) ($this->config['token'] ?? '');
        $account = (string) ($this->config['account_display'] ?? '');
        $accountKey = FioTransferMatcher::canonicalAccount($account);
        if (!FioStatementClient::validToken($token) || $accountKey === '') {
            throw new InvalidArgumentException('Vyplň token a účet Fio v nastavení plateb.');
        }
        if ($orderId !== null && (!$manual || $orderId < 1)) {
            throw new InvalidArgumentException('Neplatná objednávka pro kontrolu Fio.');
        }
        if (!$this->installed()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v administraci.');
        $result = ['checked' => 0, 'matched' => 0, 'ignored' => 0];
        $fingerprint = hash('sha256', $token);
        // Hold across HTTP AND settlement: a slow (40 s) request must not overlap
        // the next manual check after the 35 s token cooldown.
        $lock = 'fio:' . substr($fingerprint, 0, 60);
        if ((int) $this->db->queryFirstField('SELECT GET_LOCK(%s, 0)', $lock) !== 1) {
            if (!$manual) return $result + ['skipped' => 'busy'];
            throw new InvalidArgumentException('Kontrola Fio už probíhá. Vyčkej na její dokončení.');
        }
        try {
            $sql = 'SELECT o.id, o.created_at, o.payment_due_at, o.payment_details_json
                FROM shop_orders o WHERE o.payment_method=%s AND o.payment_status=%s AND o.status=%s
                AND NOT EXISTS (SELECT 1 FROM shop_fio_matches m WHERE m.order_id=o.id)';
            $params = ['bank_transfer', 'pending', 'new'];
            if ($this->hasFinancialEvents()) {
                $sql .= ' AND NOT EXISTS (SELECT 1 FROM shop_order_financial_events e
                    WHERE e.order_id=o.id AND e.action=%s)';
                $params[] = 'payment_correction';
            }
            if ($orderId !== null) { $sql .= ' AND o.id=%i'; $params[] = $orderId; }
            if (!$manual) {
                $sql .= ' AND COALESCE(o.payment_due_at, DATE_ADD(o.created_at, INTERVAL 7 DAY))
                    >= UTC_TIMESTAMP() - INTERVAL 7 DAY';
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $eligible = [];
            $oldest = null;
            $interval = 3600;
            foreach ($this->db->query($sql, ...$params) as $order) {
                $details = json_decode((string) $order['payment_details_json'], true);
                if (!is_array($details) || FioTransferMatcher::canonicalAccount(
                    (string) ($details['account_display'] ?? '')) !== $accountKey) continue;
                $delay = FioPollingPolicy::interval($order, $now);
                if (!$manual && $delay === null) continue;
                $eligible[(int) $order['id']] = true;
                $created = substr((string) $order['created_at'], 0, 10);
                $oldest = $oldest === null ? $created : min($oldest, $created);
                $interval = min($interval, $delay ?? 3600);
            }
            if ($oldest === null) {
                if ($orderId !== null) throw new InvalidArgumentException(
                    'Objednávku nelze spárovat: musí být nová, nezaplacená, na propojený účet a bez dřívější opravy platby.');
                return $result + ['skipped' => 'no_orders'];
            }
            if (!$this->reserveRequest($fingerprint, $manual ? 35 : $interval)) {
                if (!$manual) return $result + ['skipped' => 'interval'];
                throw new InvalidArgumentException('Další kontrolu Fio lze spustit nejdříve 35 sekund po předchozím dotazu.');
            }
            $today = $now->setTimezone(new \DateTimeZone('Europe/Prague'));
            $from = max($today->modify('-89 days')->format('Y-m-d'), $oldest);
            // Period export leaves Fio's server-side cursor untouched. Overlap
            // supports retries; only exact, previously unused movements settle.
            $statement = ($this->client ?? new FioStatementClient())->fetch($token,
                $from, $today->format('Y-m-d'));
            $account = FioTransferMatcher::account($statement['info'], $account);
            foreach ($statement['transactions'] as $row) {
                $result['checked']++;
                $movement = is_array($row) ? FioTransferMatcher::movement($row) : null;
                if ($movement === null || !$this->settle($movement, $account, $eligible)) {
                    $result['ignored']++;
                    continue;
                }
                $result['matched']++;
            }
            return $result;
        } finally {
            $this->db->queryFirstField('SELECT RELEASE_LOCK(%s)', $lock);
        }
    }

    private function reserveRequest(string $fingerprint, int $interval): bool
    {
        $this->db->startTransaction();
        try {
            $this->db->query('INSERT IGNORE INTO shop_fio_requests (token_hash, last_requested_at)
                VALUES (%s, NULL)', $fingerprint);
            $row = $this->db->queryFirstRow(
                'SELECT TIMESTAMPDIFF(SECOND, last_requested_at, UTC_TIMESTAMP()) AS elapsed
                 FROM shop_fio_requests WHERE token_hash=CONVERT(%s USING ascii) COLLATE ascii_bin
                 LIMIT 1 FOR UPDATE', $fingerprint);
            if ($row === null || $row['elapsed'] !== null && (int) $row['elapsed'] < $interval) {
                $this->db->commit();
                return false;
            }
            $this->db->query('UPDATE shop_fio_requests SET last_requested_at=UTC_TIMESTAMP()
                WHERE token_hash=CONVERT(%s USING ascii) COLLATE ascii_bin', $fingerprint);
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function settle(array $movement, string $account, array $eligible): bool
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
            if ($order === null || !isset($eligible[(int) $order['id']]) || !FioTransferMatcher::matchesOrder($order, $movement, $account)) {
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
