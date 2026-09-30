<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use Throwable;

/** Durable Comgate attempts and verified, idempotent payment reconciliation. */
final class ComgatePaymentService
{
    private ComgateApiClient $client;
    private string $merchant;
    private string $secret;
    private bool $test;
    private bool $enabled;
    private string $returnBaseUrl;

    public function __construct(private MeekroDB $db, array $settings, ?ComgateApiClient $client = null)
    {
        $this->merchant = trim((string) ($settings['merchant'] ?? ''));
        $this->secret = (string) ($settings['secret'] ?? '');
        $this->test = ($settings['test'] ?? true) === true;
        $this->enabled = ($settings['enabled'] ?? false) === true;
        $this->returnBaseUrl = rtrim(trim((string) ($settings['return_base_url'] ?? '')), '/');
        if ($this->merchant === '' || $this->secret === '') {
            throw new RuntimeException('Comgate není nastavena.');
        }
        $this->client = $client ?? new ComgateApiClient($this->merchant, $this->secret);
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_comgate_payments'
        ) === 1;
    }

    public function canInitiate(): bool
    {
        return $this->enabled && $this->returnUrlValid() && $this->installed();
    }

    public function state(int $orderId): ?array
    {
        if ($orderId < 1 || !$this->installed()) return null;
        return $this->db->queryFirstRow(
            'SELECT id, status, trans_id, redirect_url, last_error, test_mode, created_at, updated_at
             FROM shop_comgate_payments WHERE order_id=%i ORDER BY id DESC LIMIT 1', $orderId
        );
    }

    /** Canonical private order link for the confirmation mail, independent of Host headers. */
    public function receiptUrl(array $order, string $language): string
    {
        if (!$this->returnUrlValid() || preg_match('/^[a-z]{2}$/D', $language) !== 1 ||
            preg_match('/^[a-f0-9]{64}$/D', (string) ($order['order_token'] ?? '')) !== 1) {
            throw new InvalidArgumentException('Neplatný odkaz na objednávku.');
        }
        return $this->returnBaseUrl . '/' . $language . '/objednavka/' . $order['order_token'];
    }

    /** A network failure after sending create is uncertain; it must not be retried blindly. */
    public function initiate(array $order): string
    {
        $this->assertOrder($order);
        if (!$this->enabled) throw new RuntimeException('Nové platby Comgate jsou vypnuté.');
        if (!$this->installed()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v administraci.');
        if (!$this->returnUrlValid()) throw new RuntimeException('Nastav veřejnou HTTPS adresu obchodu pro návrat z Comgate.');
        $id = (int) $order['id'];
        $this->db->startTransaction();
        try {
            $current = $this->db->queryFirstRow(
                'SELECT payment_method, payment_status, status FROM shop_orders WHERE id=%i FOR UPDATE', $id
            );
            if ($current === null || $current['payment_method'] !== 'comgate' ||
                $current['payment_status'] !== 'pending' || $current['status'] === 'cancelled') {
                throw new RuntimeException('Tuto objednávku už nelze zaplatit přes Comgate.');
            }
            $last = $this->db->queryFirstRow(
                'SELECT * FROM shop_comgate_payments WHERE order_id=%i ORDER BY id DESC LIMIT 1 FOR UPDATE', $id
            );
            if ($last !== null && in_array($last['status'], ['pending', 'authorized'], true) &&
                is_string($last['redirect_url']) && $last['redirect_url'] !== '') {
                $this->db->commit();
                return $last['redirect_url'];
            }
            if ($last !== null && in_array($last['status'], ['creating', 'uncertain'], true)) {
                throw new RuntimeException('Výsledek založení platby není jistý. Zkontroluj transakci u Comgate podle čísla objednávky.');
            }
            if ($last !== null && $last['status'] === 'paid') {
                throw new RuntimeException('Platba již byla přijata.');
            }
            $token = bin2hex(random_bytes(32));
            $this->db->insert('shop_comgate_payments', [
                'order_id' => $id, 'status' => 'creating', 'merchant' => $this->merchant,
                'test_mode' => $this->test ? 1 : 0, 'return_token' => $token,
            ]);
            $attemptId = (int) $this->db->insertId();
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }

        $shipping = $order['shipping'] ?? [];
        $return = $this->returnBaseUrl . '/comgate-return.php?token=' . $token;
        $payload = [
            'test' => $this->test, 'country' => 'CZ',
            'price' => (int) $order['total_czk'] * 100, 'curr' => 'CZK',
            'label' => substr('Objednavka ' . $id, 0, 16),
            'refId' => (string) $order['order_number'], 'method' => 'ALL',
            'email' => (string) $order['customer_email'],
            'fullName' => (string) ($shipping['recipient'] ?? $shipping['name'] ?? ''),
            'delivery' => ShippingPolicy::isPickup((string) ($shipping['method'] ?? '')) ? 'PICKUP' : 'HOME_DELIVERY',
            'category' => 'PHYSICAL_GOODS_ONLY', 'lang' => 'cs',
            'url_paid' => $return, 'url_cancelled' => $return, 'url_pending' => $return,
        ];
        if (is_string($shipping['phone'] ?? null) && $shipping['phone'] !== '') {
            $payload['phone'] = $shipping['phone'];
        }
        if ($payload['delivery'] === 'HOME_DELIVERY') {
            $payload['homeDeliveryCity'] = (string) ($shipping['city'] ?? '');
            $payload['homeDeliveryStreet'] = (string) ($shipping['street'] ?? '');
            $payload['homeDeliveryPostalCode'] = (string) ($shipping['postal_code'] ?? '');
            $payload['homeDeliveryCountry'] = 'CZ';
        }
        try {
            $created = $this->client->create($payload);
        } catch (Throwable $error) {
            $this->setAttempt($attemptId, 'uncertain', 'Odpověď Comgate nedorazila. V klientském portálu prověř číslo objednávky.');
            throw new RuntimeException('Výsledek založení platby není jistý. Objednávka je uložena; před opakováním zkontroluj Comgate.', 0, $error);
        }
        if ((string) ($created['code'] ?? '') !== '0') {
            $code = (string) ($created['code'] ?? '?');
            $this->setAttempt($attemptId, 'rejected', 'Comgate odmítla založení platby (kód ' . substr($code, 0, 16) . ').');
            throw new RuntimeException('Comgate odmítla založení platby (kód ' . substr($code, 0, 16) . ').');
        }
        $transId = $created['transId'] ?? null;
        $redirect = $created['redirect'] ?? null;
        if (!is_string($transId) || preg_match('/^[A-Za-z0-9-]{3,100}$/D', $transId) !== 1 ||
            !is_string($redirect) || !$this->redirectValid($redirect)) {
            $this->setAttempt($attemptId, 'uncertain', 'Comgate nevrátila platné ID nebo adresu platby.');
            throw new RuntimeException('Comgate nevrátila platnou adresu platby. Zkontroluj transakci v klientském portálu.');
        }
        $this->db->startTransaction();
        try {
            $this->db->queryFirstRow('SELECT id FROM shop_orders WHERE id=%i FOR UPDATE', $id);
            $this->db->query('UPDATE shop_comgate_payments SET status=%s, trans_id=%s, redirect_url=%s,
                last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=%i AND status=%s',
                'pending', $transId, $redirect, $attemptId, 'creating');
            $this->db->query('UPDATE shop_orders SET provider_reference=%s WHERE id=%i AND payment_status=%s',
                $transId, $id, 'pending');
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            // The remote transaction exists; keep the attempt uncertain for manual reconciliation.
            $this->setAttempt($attemptId, 'uncertain', 'Transakce vznikla u Comgate, ale místní uložení selhalo.');
            throw $error;
        }
        return $redirect;
    }

    /** Query the source of truth; the browser return is never evidence of payment. */
    public function refresh(array $order): array
    {
        $this->assertOrder($order);
        if (!$this->installed()) return $order;
        $attempt = $this->db->queryFirstRow(
            'SELECT * FROM shop_comgate_payments WHERE order_id=%i AND trans_id IS NOT NULL
             ORDER BY id DESC LIMIT 1', (int) $order['id']
        );
        if ($attempt === null) return $order;
        $this->reconcile($attempt);
        return (new OrderRepository($this->db))->findById((int) $order['id']) ?? $order;
    }

    /** The REST v2 webhook is JSON. Acknowledgement follows a committed, verified state. */
    public function notify(array $payload): void
    {
        $transId = $payload['transId'] ?? null;
        if (!is_string($transId) || preg_match('/^[A-Za-z0-9-]{3,100}$/D', $transId) !== 1 ||
            !is_string($payload['secret'] ?? null) || !hash_equals($this->secret, $payload['secret']) ||
            (string) ($payload['merchant'] ?? '') !== $this->merchant || !$this->installed()) {
            throw new InvalidArgumentException('Neplatná notifikace Comgate.');
        }
        $attempt = $this->db->queryFirstRow(
            'SELECT * FROM shop_comgate_payments WHERE trans_id=%s LIMIT 1', $transId
        );
        if ($attempt === null) throw new RuntimeException('Transakce ještě není uložena.');
        $order = $this->db->queryFirstRow('SELECT * FROM shop_orders WHERE id=%i', $attempt['order_id']);
        if ($order === null || $attempt['merchant'] !== $this->merchant ||
            !$this->matches($payload, $order, $attempt)) {
            throw new InvalidArgumentException('Notifikace neodpovídá objednávce.');
        }
        $this->reconcile($attempt);
    }

    public function returnOrder(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 || !$this->installed()) return null;
        $attempt = $this->db->queryFirstRow(
            'SELECT * FROM shop_comgate_payments WHERE return_token=%s LIMIT 1', $token
        );
        if ($attempt === null) return null;
        $order = (new OrderRepository($this->db))->findById((int) $attempt['order_id']);
        if ($order === null) return null;
        try {
            if ($attempt['trans_id'] !== null) $this->reconcile($attempt);
        } catch (Throwable $error) {
            error_log('Comgate return verification failed for order ' . (int) $order['id'] . ': ' . $error->getMessage());
        }
        return (new OrderRepository($this->db))->findById((int) $attempt['order_id']) ?? $order;
    }

    private function reconcile(array $attempt): void
    {
        if ($attempt['trans_id'] === null) return;
        $status = $this->client->status((string) $attempt['trans_id']);
        $order = $this->db->queryFirstRow('SELECT * FROM shop_orders WHERE id=%i', $attempt['order_id']);
        if ($order === null || (string) ($status['code'] ?? '') !== '0' ||
            (string) ($status['transId'] ?? '') !== (string) $attempt['trans_id'] ||
            !$this->matches($status, $order, $attempt)) {
            throw new RuntimeException('Ověření platby Comgate nesouhlasí s objednávkou.');
        }
        $remote = $status['status'] ?? null;
        if (!in_array($remote, ['PENDING', 'PAID', 'CANCELLED', 'AUTHORIZED'], true)) {
            throw new RuntimeException('Comgate vrátila neznámý stav platby.');
        }
        $this->db->startTransaction();
        try {
            $currentOrder = $this->db->queryFirstRow(
                'SELECT payment_status, payment_method, provider_reference FROM shop_orders WHERE id=%i FOR UPDATE',
                $attempt['order_id']
            );
            $currentAttempt = $this->db->queryFirstRow(
                'SELECT status, trans_id FROM shop_comgate_payments WHERE id=%i FOR UPDATE', $attempt['id']
            );
            if ($currentOrder === null || $currentOrder['payment_method'] !== 'comgate' ||
                $currentAttempt === null || $currentAttempt['trans_id'] !== $attempt['trans_id']) {
                throw new RuntimeException('Transakce se mezitím změnila.');
            }
            if ($remote === 'PAID') {
                if ($currentOrder['payment_status'] === 'pending') {
                    $this->db->query('UPDATE shop_orders SET payment_status=%s, payment_paid_at=UTC_TIMESTAMP(),
                        payment_verified_by=NULL, provider_reference=%s WHERE id=%i AND payment_status=%s',
                        'paid', $attempt['trans_id'], $attempt['order_id'], 'pending');
                } elseif ($currentOrder['payment_status'] === 'paid' &&
                    $currentOrder['provider_reference'] !== $attempt['trans_id']) {
                    // Never silently settle two separate charges against one order.
                    throw new RuntimeException('Objednávka má jinou uhrazenou transakci. Prověř možné dvojí stržení.');
                }
            }
            if ($currentAttempt['status'] !== 'paid' || $remote === 'PAID') {
                $local = strtolower($remote);
                if ($currentAttempt['status'] === 'paid') $local = 'paid';
                $this->db->query('UPDATE shop_comgate_payments SET status=%s, last_error=NULL,
                    updated_at=UTC_TIMESTAMP() WHERE id=%i', $local, $attempt['id']);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function matches(array $data, array $order, array $attempt): bool
    {
        $test = $data['test'] ?? null;
        $testString = is_bool($test) ? ($test ? 'true' : 'false') : (string) $test;
        return (string) ($data['transId'] ?? '') === (string) ($attempt['trans_id'] ?? '') &&
            $testString === ((int) $attempt['test_mode'] === 1 ? 'true' : 'false') &&
            (string) ($data['price'] ?? '') === (string) ((int) $order['total_czk'] * 100) &&
            ($data['curr'] ?? null) === 'CZK' &&
            ($data['refId'] ?? null) === $order['order_number'];
    }

    private function assertOrder(array $order): void
    {
        if ((int) ($order['id'] ?? 0) < 1 || ($order['payment_method'] ?? '') !== 'comgate' ||
            !isset($order['total_czk'], $order['order_number'], $order['customer_email'])) {
            throw new InvalidArgumentException('Neplatná objednávka pro Comgate.');
        }
    }

    private function setAttempt(int $id, string $status, string $error): void
    {
        $this->db->query('UPDATE shop_comgate_payments SET status=%s, last_error=%s,
            updated_at=UTC_TIMESTAMP() WHERE id=%i AND status=%s',
            $status, $error, $id, 'creating');
    }

    private function returnUrlValid(): bool
    {
        $url = parse_url($this->returnBaseUrl);
        return is_array($url) && ($url['scheme'] ?? '') === 'https' &&
            is_string($url['host'] ?? null) && $url['host'] !== '' &&
            !isset($url['user']) && !isset($url['pass']) &&
            !isset($url['query']) && !isset($url['fragment']);
    }

    private function redirectValid(string $url): bool
    {
        if (strlen($url) > 2048 || strpbrk($url, "\r\n") !== false) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' ||
            isset($parts['user']) || isset($parts['pass'])) return false;
        $host = strtolower((string) ($parts['host'] ?? ''));
        return $host === 'payments.comgate.cz' || preg_match('/^pay[1-9][0-9]*\.comgate\.cz$/D', $host) === 1;
    }
}
