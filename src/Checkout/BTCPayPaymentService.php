<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use SimpleStore\Accounting\OrderMailQueue;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use Throwable;

/** Persistent BTCPay invoice attempts, reconciled against authenticated Greenfield reads. */
final class BTCPayPaymentService
{
    private BTCPayApiClient $client;
    private string $storeId;
    private string $serverUrl;
    private string $returnBaseUrl;
    private bool $enabled;
    private string $webhookSecret;

    public function __construct(private MeekroDB $db, array $settings, ?BTCPayApiClient $client = null)
    {
        $this->serverUrl = rtrim(trim((string) ($settings['server_url'] ?? '')), '/');
        $this->storeId = trim((string) ($settings['store_id'] ?? ''));
        $this->returnBaseUrl = rtrim(trim((string) ($settings['return_base_url'] ?? '')), '/');
        $this->webhookSecret = (string) ($settings['webhook_secret'] ?? '');
        $this->enabled = ($settings['enabled'] ?? false) === true;
        $key = (string) ($settings['api_key'] ?? '');
        $this->client = $client ?? new BTCPayApiClient($this->serverUrl, $this->storeId, $key);
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_btcpay_payments'
        ) === 1;
    }

    public function canInitiate(): bool
    {
        return $this->enabled && $this->validReturnBaseUrl() && $this->webhookSecret !== '' && $this->installed();
    }

    public function state(int $orderId): ?array
    {
        if ($orderId < 1 || !$this->installed()) return null;
        return $this->db->queryFirstRow(
            'SELECT id, status, invoice_id, redirect_url, last_error, created_at, updated_at
             FROM shop_btcpay_payments WHERE order_id=%i ORDER BY id DESC LIMIT 1', $orderId
        );
    }

    public function receiptUrl(array $order, string $language): string
    {
        if (!$this->validReturnBaseUrl() || preg_match('/^[a-z]{2}$/D', $language) !== 1 ||
            preg_match('/^[a-f0-9]{64}$/D', (string) ($order['order_token'] ?? '')) !== 1) {
            throw new InvalidArgumentException('Neplatný odkaz na objednávku.');
        }
        return $this->returnBaseUrl . '/' . $language . '/objednavka/' . $order['order_token'];
    }

    public function initiate(array $order): string
    {
        $this->assertOrder($order);
        if (!$this->enabled) throw new RuntimeException('Nové platby BTCPay jsou vypnuté.');
        if (!$this->installed()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v administraci.');
        if (!$this->validReturnBaseUrl() || $this->webhookSecret === '') {
            throw new RuntimeException('Nastav veřejnou HTTPS adresu a webhook BTCPay.');
        }
        $orderId = (int) $order['id'];
        $this->db->startTransaction();
        try {
            $current = $this->db->queryFirstRow(
                'SELECT payment_method, payment_status, status, total_czk, order_number, customer_email,
                        items_json, shipping_json FROM shop_orders WHERE id=%i FOR UPDATE', $orderId
            );
            if ($current === null || $current['payment_method'] !== 'btcpay' ||
                $current['payment_status'] !== 'pending' || $current['status'] === 'cancelled') {
                throw new RuntimeException('Tuto objednávku už nelze zaplatit přes BTCPay.');
            }
            if ((int) $current['total_czk'] !== (int) $order['total_czk'] ||
                $current['order_number'] !== $order['order_number'] ||
                $current['customer_email'] !== $order['customer_email'] ||
                (isset($order['items_json']) && $current['items_json'] !== $order['items_json']) ||
                (isset($order['shipping_json']) && $current['shipping_json'] !== $order['shipping_json'])) {
                throw new RuntimeException('Objednávka se mezitím změnila. Obnov stránku.');
            }
            $last = $this->db->queryFirstRow(
                'SELECT * FROM shop_btcpay_payments WHERE order_id=%i ORDER BY id DESC LIMIT 1 FOR UPDATE', $orderId
            );
            if ($last !== null && in_array($last['status'], ['new', 'processing'], true)) {
                if (!is_string($last['redirect_url']) || !$this->redirectValid($last['redirect_url'])) {
                    throw new RuntimeException('BTCPay nevrátil bezpečný odkaz na platbu.');
                }
                $this->db->commit();
                return $last['redirect_url'];
            }
            if ($last !== null && in_array($last['status'], ['creating', 'uncertain'], true)) {
                throw new RuntimeException('Založení faktury BTCPay není jisté. Prověř ji před opakováním.');
            }
            if ($last !== null && $last['status'] === 'settled') {
                throw new RuntimeException('Objednávka již má zaplacenou fakturu BTCPay.');
            }
            $token = bin2hex(random_bytes(32));
            $this->db->insert('shop_btcpay_payments', [
                'order_id' => $orderId, 'order_number' => $current['order_number'],
                'total_czk' => (int) $current['total_czk'], 'status' => 'creating',
                'store_id' => $this->storeId, 'return_token' => $token,
            ]);
            $attemptId = (int) $this->db->insertId();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }

        $payload = [
            'amount' => (string) (int) $order['total_czk'] . '.00',
            'currency' => 'CZK',
            'metadata' => ['orderId' => (string) $order['order_number']],
            'checkout' => ['redirectURL' => $this->returnBaseUrl . '/btcpay-return.php?token=' . $token,
                'redirectAutomatically' => true],
        ];
        try {
            $created = $this->client->create($payload);
        } catch (BTCPayApiRejectedException $error) {
            $this->setAttempt($attemptId, 'rejected', $error->getMessage());
            throw $error;
        } catch (Throwable $error) {
            $this->setAttempt($attemptId, 'uncertain', 'Není jisté, zda BTCPay fakturu vytvořil. Prověř objednávku.');
            throw new RuntimeException('Vznik faktury BTCPay není jistý. Objednávka je uložená.', 0, $error);
        }
        if (!$this->matches($created, $order) ||
            !in_array($created['status'] ?? null, ['New', 'Processing'], true) ||
            !is_string($created['checkoutLink'] ?? null) || !$this->redirectValid($created['checkoutLink'])) {
            $this->setAttempt($attemptId, 'uncertain', 'Odpověď BTCPay nesouhlasí s objednávkou.');
            throw new RuntimeException('BTCPay nevrátil ověřitelný odkaz na platbu. Prověř fakturu.');
        }
        $invoiceId = (string) $created['id'];
        $redirect = $created['checkoutLink'];
        $this->db->startTransaction();
        try {
            $this->db->queryFirstRow('SELECT id FROM shop_orders WHERE id=%i FOR UPDATE', $orderId);
            $this->db->query(
                'UPDATE shop_btcpay_payments SET status=%s, invoice_id=%s, redirect_url=%s,
                 last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=%i AND status=%s',
                strtolower($created['status']), $invoiceId, $redirect, $attemptId, 'creating'
            );
            $this->db->query(
                'UPDATE shop_orders SET provider_reference=%s WHERE id=%i AND payment_status=%s',
                $invoiceId, $orderId, 'pending'
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            $this->setAttempt($attemptId, 'uncertain', 'BTCPay fakturu vytvořil, ale místní uložení selhalo.');
            throw new RuntimeException('BTCPay fakturu vytvořil, ale nebyla místně uložena.', 0, $error);
        }
        return $redirect;
    }

    /** Neither a browser redirect nor a signed notification reports trusted payment state. */
    public function refresh(array $order): array
    {
        $this->assertOrder($order);
        if (!$this->installed()) return $order;
        $attempts = $this->db->query(
            'SELECT * FROM shop_btcpay_payments WHERE order_id=%i AND invoice_id IS NOT NULL ORDER BY id DESC',
            (int) $order['id']
        );
        foreach ($attempts as $attempt) $this->reconcile($attempt);
        return (new OrderRepository($this->db))->findById((int) $order['id']) ?? $order;
    }

    public function notify(string $invoiceId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $invoiceId) !== 1 || !$this->installed()) {
            throw new InvalidArgumentException('Neplatná notifikace BTCPay.');
        }
        $attempt = $this->db->queryFirstRow(
            'SELECT * FROM shop_btcpay_payments WHERE invoice_id=%s LIMIT 1', $invoiceId
        );
        if ($attempt === null) throw new RuntimeException('Faktura BTCPay ještě není uložena.');
        $this->reconcile($attempt);
    }

    public function returnOrder(string $token, string $invoiceId = ''): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 || !$this->installed()) return null;
        $attempt = $this->db->queryFirstRow(
            'SELECT * FROM shop_btcpay_payments WHERE return_token=%s LIMIT 1', $token
        );
        if ($attempt === null || $attempt['order_id'] === null ||
            ($invoiceId !== '' && $invoiceId !== (string) $attempt['invoice_id'])) return null;
        $order = (new OrderRepository($this->db))->findById((int) $attempt['order_id']);
        if ($order === null) return null;
        try {
            if ($attempt['invoice_id'] !== null) $this->reconcile($attempt);
        } catch (Throwable $error) {
            error_log('BTCPay return verification failed for order ' . (int) $order['id'] . ': ' . $error->getMessage());
        }
        return (new OrderRepository($this->db))->findById((int) $attempt['order_id']) ?? $order;
    }

    public static function verifySignature(string $rawBody, string $header, string $secret): bool
    {
        if ($secret === '' || preg_match('/^sha256=([a-f0-9]{64})$/D', $header, $matches) !== 1) return false;
        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $matches[1]);
    }

    private function reconcile(array $attempt): void
    {
        if ($attempt['invoice_id'] === null) return;
        if ($attempt['store_id'] !== $this->storeId) {
            throw new RuntimeException('ID obchodu BTCPay se změnilo. Obnov původní nastavení.');
        }
        $id = (string) $attempt['invoice_id'];
        $remote = $this->client->status($id);
        if (!in_array($remote['status'] ?? null, ['New', 'Processing', 'Settled', 'Expired', 'Invalid'], true) ||
            !$this->matches($remote, $this->snapshot($attempt)) ||
            ($remote['id'] ?? null) !== $id) {
            throw new RuntimeException('Ověřená faktura BTCPay nesouhlasí s objednávkou.');
        }
        $state = strtolower($remote['status']);
        if ($state === 'settled' &&
            !in_array($remote['additionalStatus'] ?? 'None', ['None', 'PaidOver', 'PaidLate'], true)) {
            throw new RuntimeException('BTCPay hlásí neobvyklé vypořádání. Prověř fakturu před potvrzením.');
        }
        $this->db->startTransaction();
        try {
            // Lock order before attempt, matching the administrator deletion path.
            $order = $attempt['order_id'] === null ? null : $this->db->queryFirstRow(
                'SELECT * FROM shop_orders WHERE id=%i FOR UPDATE', $attempt['order_id']
            );
            $current = $this->db->queryFirstRow(
                'SELECT * FROM shop_btcpay_payments WHERE id=%i FOR UPDATE', $attempt['id']
            );
            if ($current === null || (string) $current['invoice_id'] !== $id ||
                $current['store_id'] !== $this->storeId || !$this->matches($remote, $this->snapshot($current))) {
                throw new RuntimeException('Faktura BTCPay se mezitím změnila.');
            }
            if ($current['order_id'] !== null && ($order === null ||
                $order['payment_method'] !== 'btcpay' ||
                (int) $order['id'] !== (int) $current['order_id'] ||
                (int) $order['total_czk'] !== (int) $current['total_czk'] ||
                $order['order_number'] !== $current['order_number'])) {
                throw new RuntimeException('Faktura BTCPay nepatří k této objednávce.');
            }
            if ($current['status'] === 'settled') $state = 'settled';
            if ($order === null && $state === 'settled' && $current['status'] !== 'settled') {
                $this->db->insert('shop_order_financial_events', [
                    'order_id' => 0, 'order_number' => $current['order_number'], 'variable_symbol' => null,
                    'action' => 'provider_payment_after_delete', 'payment_status_before' => $current['status'],
                    'payment_paid_at' => gmdate('Y-m-d H:i:s'), 'payment_verified_by' => null,
                    'total_czk' => (int) $current['total_czk'],
                    'reason' => 'BTCPay ' . $id . ': faktura zaplacena po smazání objednávky.',
                    'admin_id' => 0,
                ]);
            }
            $newlyPaid = false;
            $paidAfterCancellation = false;
            if ($order !== null && $state === 'settled') {
                if ($order['payment_status'] === 'pending') {
                    $paidAfterCancellation = $order['status'] === 'cancelled';
                    $this->db->query(
                        'UPDATE shop_orders SET payment_status=%s, payment_paid_at=UTC_TIMESTAMP(),
                         payment_verified_by=NULL, provider_reference=%s WHERE id=%i AND payment_status=%s',
                        'paid', $id, $order['id'], 'pending'
                    );
                    if ($paidAfterCancellation) $this->recordPaymentAfterCancellation($order, $id);
                    $newlyPaid = true;
                } elseif ($order['payment_status'] === 'paid' && $order['provider_reference'] !== $id) {
                    throw new RuntimeException('Objednávka již má jinou platbu. Prověř možné dvojí zaplacení.');
                }
            }
            $this->db->query(
                'UPDATE shop_btcpay_payments SET status=%s, last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=%i',
                $state, $current['id']
            );
            $this->db->commit();
            if ($newlyPaid && !$paidAfterCancellation) {
                try { (new OrderMailQueue($this->db))->notifyStage((int) $order['id'], 'paid'); }
                catch (Throwable $mailError) { error_log('BTCPay payment email: ' . $mailError->getMessage()); }
            }
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function snapshot(array $attempt): array
    {
        if (!is_string($attempt['order_number'] ?? null) || $attempt['order_number'] === '' ||
            (int) ($attempt['total_czk'] ?? 0) < 1) {
            throw new RuntimeException('Chybí původní údaje faktury BTCPay.');
        }
        return ['order_number' => $attempt['order_number'], 'total_czk' => (int) $attempt['total_czk']];
    }

    /** Expired invoices may settle late, after cancellation has released inventory. */
    private function recordPaymentAfterCancellation(array $order, string $invoiceId): void
    {
        if ((int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_order_financial_events'
        ) === 0) return;
        $this->db->insert('shop_order_financial_events', [
            'order_id' => (int) $order['id'], 'order_number' => $order['order_number'],
            'variable_symbol' => $order['variable_symbol'] ?? null,
            'action' => 'provider_payment_after_cancel', 'payment_status_before' => 'pending',
            'payment_paid_at' => gmdate('Y-m-d H:i:s'), 'payment_verified_by' => null,
            'total_czk' => (int) $order['total_czk'],
            'reason' => 'BTCPay ' . $invoiceId . ': paid after cancellation; reconcile and refund.',
            'admin_id' => 0,
        ]);
    }

    private function matches(array $invoice, array $order): bool
    {
        $amount = $invoice['amount'] ?? null;
        if (!is_string($amount) || preg_match('/^([0-9]{1,7})(?:\.([0-9]{1,2}))?$/D', $amount, $parts) !== 1) {
            return false;
        }
        $minor = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        return is_string($invoice['id'] ?? null) &&
            preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $invoice['id']) === 1 &&
            ($invoice['storeId'] ?? null) === $this->storeId &&
            ($invoice['currency'] ?? null) === 'CZK' &&
            $minor === (int) $order['total_czk'] * 100 &&
            is_array($invoice['metadata'] ?? null) &&
            ($invoice['metadata']['orderId'] ?? null) === $order['order_number'];
    }

    private function assertOrder(array $order): void
    {
        if ((int) ($order['id'] ?? 0) < 1 || ($order['payment_method'] ?? '') !== 'btcpay' ||
            !isset($order['total_czk'], $order['order_number'], $order['customer_email']) ||
            (int) $order['total_czk'] < 1 || (int) $order['total_czk'] > 9999999) {
            throw new InvalidArgumentException('Neplatná objednávka pro BTCPay.');
        }
    }

    private function setAttempt(int $id, string $status, string $error): void
    {
        $this->db->query(
            'UPDATE shop_btcpay_payments SET status=%s, last_error=%s, updated_at=UTC_TIMESTAMP() WHERE id=%i',
            $status, substr($error, 0, 500), $id
        );
    }

    private function validReturnBaseUrl(): bool
    {
        $parts = parse_url($this->returnBaseUrl);
        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' &&
            isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) &&
            !isset($parts['query']) && !isset($parts['fragment']);
    }

    private function redirectValid(string $url): bool
    {
        $base = parse_url($this->serverUrl);
        $redirect = parse_url($url);
        if (!is_array($base) || !is_array($redirect) ||
            ($redirect['scheme'] ?? null) !== ($base['scheme'] ?? null) ||
            strtolower((string) ($redirect['host'] ?? '')) !== strtolower((string) ($base['host'] ?? '')) ||
            ($redirect['port'] ?? null) !== ($base['port'] ?? null) ||
            isset($redirect['user']) || isset($redirect['pass']) || isset($redirect['fragment'])) return false;
        $basePath = rtrim((string) ($base['path'] ?? ''), '/');
        return str_starts_with((string) ($redirect['path'] ?? ''), $basePath . '/i/');
    }
}
