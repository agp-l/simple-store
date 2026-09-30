<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use Throwable;

/** One durable record per GoPay transaction; payment is accepted only after GET status. */
final class GoPayPaymentService
{
    private GoPayApiClient $client;
    private string $goid;
    private bool $enabled;
    private bool $test;
    private string $returnBaseUrl;

    public function __construct(private MeekroDB $db, array $settings, ?GoPayApiClient $client = null)
    {
        $this->goid = trim((string) ($settings['goid'] ?? ''));
        $clientId = trim((string) ($settings['client_id'] ?? ''));
        $clientSecret = (string) ($settings['client_secret'] ?? '');
        $this->enabled = ($settings['enabled'] ?? false) === true;
        $this->test = ($settings['test'] ?? true) === true;
        $this->returnBaseUrl = rtrim(trim((string) ($settings['return_base_url'] ?? '')), '/');
        if ($this->goid === '' || $clientId === '' || $clientSecret === '') {
            throw new RuntimeException('GoPay není nastavena.');
        }
        $this->client = $client ?? new GoPayApiClient($this->goid, $clientId, $clientSecret, $this->test);
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_gopay_payments'
        ) === 1;
    }

    public function canInitiate(): bool
    {
        return $this->enabled && $this->validReturnBaseUrl() && $this->installed();
    }

    public function state(int $orderId): ?array
    {
        if ($orderId < 1 || !$this->installed()) return null;
        return $this->db->queryFirstRow(
            'SELECT id, status, payment_id, redirect_url, last_error, test_mode, created_at, updated_at
             FROM shop_gopay_payments WHERE order_id=%i ORDER BY id DESC LIMIT 1', $orderId
        );
    }

    /** Confirmation mail links use the configured shop URL, never an untrusted Host header. */
    public function receiptUrl(array $order, string $language): string
    {
        if (!$this->validReturnBaseUrl() || preg_match('/^[a-z]{2}$/D', $language) !== 1 ||
            preg_match('/^[a-f0-9]{64}$/D', (string) ($order['order_token'] ?? '')) !== 1) {
            throw new InvalidArgumentException('Neplatný odkaz na objednávku.');
        }
        return $this->returnBaseUrl . '/' . $language . '/objednavka/' . $order['order_token'];
    }

    /** Reserve the attempt before contacting GoPay to prevent parallel duplicate charges. */
    public function initiate(array $order): string
    {
        $this->assertOrder($order);
        if (!$this->enabled) throw new RuntimeException('Nové platby GoPay jsou vypnuté.');
        if (!$this->installed()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v administraci.');
        if (!$this->validReturnBaseUrl()) {
            throw new RuntimeException('Nastav veřejnou HTTPS adresu obchodu pro návrat z GoPay.');
        }
        $orderId = (int) $order['id'];
        $this->db->startTransaction();
        try {
            $current = $this->db->queryFirstRow(
                'SELECT payment_method, payment_status, status, total_czk, order_number,
                        customer_email, items_json, shipping_json
                 FROM shop_orders WHERE id=%i FOR UPDATE', $orderId
            );
            if ($current === null || $current['payment_method'] !== 'gopay' ||
                $current['payment_status'] !== 'pending' || $current['status'] === 'cancelled') {
                throw new RuntimeException('Tuto objednávku už nelze zaplatit přes GoPay.');
            }
            if ((int) $current['total_czk'] !== (int) $order['total_czk'] ||
                $current['order_number'] !== $order['order_number'] ||
                $current['customer_email'] !== $order['customer_email'] ||
                (isset($order['items_json']) && $current['items_json'] !== $order['items_json']) ||
                (isset($order['shipping_json']) && $current['shipping_json'] !== $order['shipping_json'])) {
                throw new RuntimeException('Objednávka se mezitím změnila. Obnov stránku.');
            }
            $last = $this->db->queryFirstRow(
                'SELECT * FROM shop_gopay_payments WHERE order_id=%i ORDER BY id DESC LIMIT 1 FOR UPDATE', $orderId
            );
            if ($last !== null && in_array($last['status'],
                    ['created', 'payment_method_chosen', 'authorized'], true)) {
                if (!is_string($last['redirect_url']) ||
                    !$this->redirectValid($last['redirect_url'], (int) $last['test_mode'] === 1)) {
                    throw new RuntimeException('GoPay nevrátila bezpečný odkaz na platbu.');
                }
                $this->db->commit();
                return $last['redirect_url'];
            }
            if ($last !== null && in_array($last['status'], ['creating', 'uncertain'], true)) {
                throw new RuntimeException('Založení platby GoPay není jisté. Prověř transakci před opakováním.');
            }
            if ($last !== null && in_array($last['status'], ['paid', 'refunded', 'partially_refunded'], true)) {
                throw new RuntimeException('Objednávka již má přijatou platbu GoPay.');
            }
            // A definite 4xx create rejection is safe to retry after fixing settings.
            // Remote transactions are retried only after CANCELED or TIMEOUTED.
            $token = bin2hex(random_bytes(32));
            $this->db->insert('shop_gopay_payments', [
                'order_id' => $orderId,
                'status' => 'creating',
                'goid' => $this->goid,
                'test_mode' => $this->test ? 1 : 0,
                'return_token' => $token,
            ]);
            $attemptId = (int) $this->db->insertId();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }

        $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
        $contact = $this->contact($order, $shipping);
        $items = $this->items($order);
        $payload = [
            'target' => ['type' => 'ACCOUNT', 'goid' => $this->goid],
            'amount' => (int) $order['total_czk'] * 100,
            'currency' => 'CZK',
            'order_number' => (string) $order['order_number'],
            'order_description' => 'Objednavka ' . (string) $order['order_number'],
            'payer' => ['contact' => $contact],
            'callback' => [
                'return_url' => $this->returnBaseUrl . '/gopay-return.php?token=' . $token,
                'notification_url' => $this->returnBaseUrl . '/gopay-callback.php',
            ],
        ];
        if ($items !== []) $payload['items'] = $items;
        try {
            $created = $this->client->forMode($this->test)->create($payload);
        } catch (GoPayApiRejectedException $error) {
            $this->setAttempt($attemptId, 'rejected', $error->getMessage());
            throw $error;
        } catch (Throwable $error) {
            $this->setAttempt($attemptId, 'uncertain', 'Nedostali jsme jistou odpověď od GoPay. Prověř číslo objednávky.');
            throw new RuntimeException('Založení platby GoPay není jisté. Objednávka je uložena; prověř GoPay.', 0, $error);
        }
        if (!$this->matches($created, $order, $this->goid) ||
            !in_array(($created['state'] ?? null), ['CREATED', 'PAYMENT_METHOD_CHOSEN'], true) ||
            !is_string($created['gw_url'] ?? null) ||
            !$this->redirectValid($created['gw_url'], $this->test)) {
            $this->setAttempt($attemptId, 'uncertain', 'Odpověď GoPay nesouhlasí s objednávkou.');
            throw new RuntimeException('GoPay nevrátila ověřitelný odkaz platby. Prověř transakci u GoPay.');
        }
        $paymentId = (string) $created['id'];
        $redirect = $created['gw_url'];
        $status = strtolower($created['state']);
        $this->db->startTransaction();
        try {
            $this->db->queryFirstRow('SELECT id FROM shop_orders WHERE id=%i FOR UPDATE', $orderId);
            $this->db->query(
                'UPDATE shop_gopay_payments SET status=%s, payment_id=%s, redirect_url=%s,
                 last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=%i AND status=%s',
                $status, $paymentId, $redirect, $attemptId, 'creating'
            );
            $this->db->query(
                'UPDATE shop_orders SET provider_reference=%s WHERE id=%i AND payment_status=%s',
                $paymentId, $orderId, 'pending'
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            $this->setAttempt($attemptId, 'uncertain', 'Platba vznikla u GoPay, ale lokální uložení selhalo.');
            throw new RuntimeException('Platba vznikla u GoPay, ale nebyla uložena. Prověř GoPay.', 0, $error);
        }
        return $redirect;
    }

    /** Never treat the browser redirect as proof of payment. */
    public function refresh(array $order): array
    {
        $this->assertOrder($order);
        if (!$this->installed()) return $order;
        $attempts = $this->db->query(
            'SELECT * FROM shop_gopay_payments WHERE order_id=%i AND payment_id IS NOT NULL ORDER BY id DESC',
            (int) $order['id']
        );
        foreach ($attempts as $attempt) $this->reconcile($attempt);
        return (new OrderRepository($this->db))->findById((int) $order['id']) ?? $order;
    }

    /** GoPay GET ?id contains no trustworthy state; authenticate via API status. */
    public function notify(string $id): void
    {
        if (preg_match('/^[0-9]{1,30}$/D', $id) !== 1 || !$this->installed()) {
            throw new InvalidArgumentException('Neplatná notifikace GoPay.');
        }
        $attempt = $this->db->queryFirstRow(
            'SELECT * FROM shop_gopay_payments WHERE payment_id=%s LIMIT 1', $id
        );
        if ($attempt === null) throw new RuntimeException('Transakce GoPay ještě není uložena.');
        $this->reconcile($attempt);
    }

    public function returnOrder(string $token, string $id): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 ||
            preg_match('/^[0-9]{1,30}$/D', $id) !== 1 || !$this->installed()) return null;
        $attempt = $this->db->queryFirstRow(
            'SELECT * FROM shop_gopay_payments WHERE return_token=%s AND payment_id=%s LIMIT 1',
            $token, $id
        );
        if ($attempt === null) return null;
        $order = (new OrderRepository($this->db))->findById((int) $attempt['order_id']);
        if ($order === null) return null;
        try {
            $this->reconcile($attempt);
        } catch (Throwable $error) {
            error_log('GoPay return verification failed for order ' . (int) $order['id'] . ': ' . $error->getMessage());
        }
        return (new OrderRepository($this->db))->findById((int) $attempt['order_id']) ?? $order;
    }

    private function reconcile(array $attempt): void
    {
        if ($attempt['payment_id'] === null) return;
        if ((string) $attempt['goid'] !== $this->goid) {
            throw new RuntimeException('GoPay ID obchodníka se změnilo. Pro ověření obnov původní nastavení.');
        }
        $id = (string) $attempt['payment_id'];
        $response = $this->client->forMode((int) $attempt['test_mode'] === 1)->status($id);
        $order = $this->db->queryFirstRow('SELECT * FROM shop_orders WHERE id=%i', $attempt['order_id']);
        if ($order === null || !$this->matches($response, $order, (string) $attempt['goid']) ||
            (string) $response['id'] !== $id || $order['payment_method'] !== 'gopay') {
            throw new RuntimeException('Ověřená transakce GoPay nesouhlasí s objednávkou.');
        }
        $remote = $response['state'] ?? null;
        if (!in_array($remote, ['CREATED', 'PAYMENT_METHOD_CHOSEN', 'AUTHORIZED', 'PAID',
            'CANCELED', 'TIMEOUTED', 'REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
            throw new RuntimeException('GoPay vrátila neznámý stav platby.');
        }
        $this->db->startTransaction();
        try {
            $currentOrder = $this->db->queryFirstRow(
                'SELECT payment_method, payment_status, provider_reference FROM shop_orders WHERE id=%i FOR UPDATE',
                $attempt['order_id']
            );
            $currentAttempt = $this->db->queryFirstRow(
                'SELECT status, payment_id FROM shop_gopay_payments WHERE id=%i FOR UPDATE', $attempt['id']
            );
            if ($currentOrder === null || $currentOrder['payment_method'] !== 'gopay' ||
                $currentAttempt === null || (string) $currentAttempt['payment_id'] !== $id) {
                throw new RuntimeException('Transakce GoPay se mezitím změnila.');
            }
            if ($remote === 'PAID') {
                if ($currentOrder['payment_status'] === 'pending') {
                    $this->db->query(
                        'UPDATE shop_orders SET payment_status=%s, payment_paid_at=UTC_TIMESTAMP(),
                         payment_verified_by=NULL, provider_reference=%s WHERE id=%i AND payment_status=%s',
                        'paid', $id, $attempt['order_id'], 'pending'
                    );
                } elseif ($currentOrder['payment_status'] === 'paid' &&
                    (string) $currentOrder['provider_reference'] !== $id) {
                    throw new RuntimeException('Jiná platba již uhradila tuto objednávku. Prověř možné dvojí stržení.');
                }
            }
            $local = strtolower($remote);
            if (in_array($currentAttempt['status'], ['paid', 'refunded', 'partially_refunded'], true) &&
                !in_array($remote, ['PAID', 'REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
                $local = $currentAttempt['status']; // A late stale state must never erase a settlement.
            }
            $this->db->query(
                'UPDATE shop_gopay_payments SET status=%s, last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=%i',
                $local, $attempt['id']
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function matches(array $data, array $order, string $goid): bool
    {
        return isset($data['id']) && preg_match('/^[0-9]{1,30}$/D', (string) $data['id']) === 1 &&
            (string) ($data['amount'] ?? '') === (string) ((int) $order['total_czk'] * 100) &&
            ($data['currency'] ?? null) === 'CZK' &&
            ($data['order_number'] ?? null) === $order['order_number'] &&
            is_array($data['target'] ?? null) &&
            ($data['target']['type'] ?? null) === 'ACCOUNT' &&
            (string) ($data['target']['goid'] ?? '') === $goid;
    }

    private function assertOrder(array $order): void
    {
        if ((int) ($order['id'] ?? 0) < 1 || ($order['payment_method'] ?? '') !== 'gopay' ||
            !isset($order['total_czk'], $order['order_number'], $order['customer_email']) ||
            (int) $order['total_czk'] < 1 || (int) $order['total_czk'] > 9999999 ||
            strlen((string) $order['customer_email']) > 128) {
            throw new InvalidArgumentException('Neplatná objednávka pro GoPay.');
        }
    }

    /** Optional personal data is included only when present and in the provider's format. */
    private function contact(array $order, array $shipping): array
    {
        $contact = ['email' => (string) $order['customer_email']];
        $name = trim((string) ($shipping['recipient'] ?? $shipping['name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/u', $name, 2);
            if (is_array($parts)) {
                $contact['first_name'] = $parts[0];
                if (isset($parts[1])) $contact['last_name'] = $parts[1];
            }
        }
        $phone = preg_replace('/[\s()\-]+/', '', (string) ($shipping['phone'] ?? ''));
        if (is_string($phone) && preg_match('/^[0-9]{9}$/D', $phone) === 1) $phone = '+420' . $phone;
        if (is_string($phone) && preg_match('/^\+[1-9][0-9]{6,14}$/D', $phone) === 1) {
            $contact['phone_number'] = $phone;
        }
        foreach (['city' => 128, 'street' => 128, 'postal_code' => 16] as $key => $max) {
            $value = trim((string) ($shipping[$key] ?? ''));
            if ($value !== '' && strlen($value) <= $max) $contact[$key] = $value;
        }
        if (($shipping['country'] ?? null) === 'CZ') $contact['country_code'] = 'CZE';
        return $contact;
    }

    /** GoPay Item.amount is the total for the line, including its count. */
    private function items(array $order): array
    {
        $snapshots = $order['items'] ?? [];
        if (!is_array($snapshots)) return [];
        $items = [];
        foreach ($snapshots as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) ||
                (int) ($item['quantity'] ?? 0) < 1 || (int) ($item['unit_price_czk'] ?? 0) < 1) {
                return []; // Leave the item list optional rather than send mismatched amounts.
            }
            $items[] = [
                'type' => 'ITEM',
                'name' => $item['name'],
                'amount' => (int) $item['quantity'] * (int) $item['unit_price_czk'] * 100,
                'count' => (int) $item['quantity'],
            ];
        }
        $shipping = (int) ($order['shipping_czk'] ?? 0);
        if ($shipping > 0) {
            $items[] = ['type' => 'DELIVERY', 'name' => (string) ($order['shipping']['label'] ?? 'Doprava'),
                'amount' => $shipping * 100, 'count' => 1];
        }
        return $items;
    }

    private function setAttempt(int $id, string $status, string $error): void
    {
        $this->db->query(
            'UPDATE shop_gopay_payments SET status=%s, last_error=%s, updated_at=UTC_TIMESTAMP()
             WHERE id=%i AND status=%s', $status, $error, $id, 'creating'
        );
    }

    private function validReturnBaseUrl(): bool
    {
        $parts = parse_url($this->returnBaseUrl);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' &&
            is_string($parts['host'] ?? null) && $parts['host'] !== '' &&
            !isset($parts['user']) && !isset($parts['pass']) &&
            !isset($parts['query']) && !isset($parts['fragment']);
    }

    private function redirectValid(string $url, bool $test): bool
    {
        if (strlen($url) > 2048 || strpbrk($url, "\r\n") !== false) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) ||
            !str_starts_with((string) ($parts['path'] ?? ''), '/gw/')) return false;
        return in_array(strtolower((string) ($parts['host'] ?? '')), $test
            ? ['gw.sandbox.gopay.com', 'testgw.gopay.cz'] : ['gate.gopay.cz'], true);
    }
}
