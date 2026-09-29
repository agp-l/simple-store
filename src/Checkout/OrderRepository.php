<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use Throwable;

/** Persists a complete checkout snapshot; callers must price items and shipping on the server. */
final class OrderRepository
{
    private const MAX_TOTAL_CZK = 9999999;

    public function __construct(
        private MeekroDB $db,
        private ?BankTransferPayment $bank = null,
        private int $dueDays = 7
    ) {
        if ($dueDays < 1 || $dueDays > 60) {
            throw new InvalidArgumentException('Splatnost platby musí být mezi 1 a 60 dny.');
        }
    }

    /** The checkout migration is required before accepting an order. */
    public function installed(): bool
    {
        foreach (['order_token', 'customer_email', 'subtotal_czk', 'shipping_czk',
            'payment_method', 'payment_status', 'payment_details_json', 'payment_due_at',
            'payment_paid_at', 'payment_verified_by',
            'variable_symbol', 'idempotency_key'] as $column) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
                'shop_orders', $column
            ) === 0) {
                return false;
            }
        }
        if ((int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
             AND TABLE_NAME=%s AND COLUMN_NAME=%s AND IS_NULLABLE=%s',
            'shop_orders', 'user_id', 'YES'
        ) === 0) {
            return false;
        }
        foreach (['orders_token', 'orders_variable_symbol', 'orders_idempotency_key'] as $index) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s
                   AND INDEX_NAME=%s AND NON_UNIQUE=0', 'shop_orders', $index
            ) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Save exactly one order per random checkout submission key, including parallel POSTs.
     * Never pass client supplied prices or shipping fees to this method.
     *
     * @param array<array<string, mixed>> $items Product snapshots with product_key, language,
     *        slug, name, quantity, unit_price_czk, image_path and optional options.
     * @param array<string, mixed> $shipping Chosen configured method and validated contact/address.
     */
    public function create(
        ?int $userId,
        string $email,
        array $items,
        array $shipping,
        int $shippingCzk,
        string $idempotencyKey
    ): array {
        if ($this->bank === null) {
            throw new RuntimeException('Platba převodem není nastavena.');
        }
        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('Neplatný zákaznický účet.');
        }
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Zadej platnou e-mailovou adresu.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Neplatný identifikátor objednávky.');
        }
        if (count($items) < 1 || count($items) > 40 || $shippingCzk < 0 ||
            $shippingCzk > self::MAX_TOTAL_CZK) {
            throw new InvalidArgumentException('Neplatné položky nebo cena dopravy.');
        }
        $method = $shipping['method'] ?? null;
        $recipient = $shipping['recipient'] ?? $shipping['name'] ?? null;
        if (!is_string($method) || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $method) !== 1 ||
            !is_string($shipping['label'] ?? null) ||
            preg_match('/^.{1,120}$/usD', trim($shipping['label'])) !== 1 ||
            !is_string($recipient) || preg_match('/^.{1,120}$/usD', trim($recipient)) !== 1) {
            throw new InvalidArgumentException('Neplatná doprava nebo příjemce.');
        }
        $shipping['label'] = trim($shipping['label']);
        $shipping['recipient'] = trim($recipient);
        $subtotal = 0;
        $units = 0;
        $snapshots = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('Neplatná položka košíku.');
            }
            $key = $item['product_key'] ?? null;
            $language = $item['language'] ?? null;
            $slug = $item['slug'] ?? null;
            $name = $item['name'] ?? null;
            $image = $item['image_path'] ?? '';
            $quantity = $item['quantity'] ?? null;
            $price = $item['unit_price_czk'] ?? null;
            $options = $item['options'] ?? [];
            if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
                !is_string($language) || preg_match('/^[a-z]{2}$/D', $language) !== 1 ||
                !is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 ||
                !is_string($name) || preg_match('/^.{1,255}$/usD', trim($name)) !== 1 ||
                !is_string($image) || strlen($image) > 1000 ||
                !is_int($quantity) || $quantity < 1 || $quantity > 99 ||
                !is_int($price) || $price < 1 || $price > self::MAX_TOTAL_CZK ||
                !is_array($options)) {
                throw new InvalidArgumentException('Neplatná položka košíku.');
            }
            $line = $price * $quantity;
            if ($line > self::MAX_TOTAL_CZK - $subtotal - $shippingCzk) {
                throw new InvalidArgumentException('Celková částka objednávky je příliš vysoká.');
            }
            $units += $quantity;
            if ($units > 200) {
                throw new InvalidArgumentException('Košík může obsahovat nejvýše 200 kusů.');
            }
            $subtotal += $line;
            $snapshots[] = [
                'product_key' => $key,
                'language' => $language,
                'slug' => $slug,
                'name' => trim($name),
                'quantity' => $quantity,
                'unit_price_czk' => $price,
                'image_path' => $image,
                'options' => $options,
            ];
        }
        $itemsJson = self::json($snapshots, 262144);
        $shippingJson = self::json($shipping, 16384);
        $request = [
            'user_id' => $userId,
            'customer_email' => $email,
            'items_json' => $itemsJson,
            'shipping_json' => $shippingJson,
            'shipping_czk' => $shippingCzk,
        ];

        $this->db->startTransaction();
        try {
            $existing = $this->byIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                $this->assertSameRequest($existing, $request);
                $this->db->commit();
                return self::hydrate($existing);
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $this->db->insert('shop_orders', [
                'user_id' => $userId,
                'order_number' => 'DB-' . $now->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5))),
                'order_token' => bin2hex(random_bytes(32)),
                'status' => 'new',
                'customer_email' => $email,
                'subtotal_czk' => $subtotal,
                'shipping_czk' => $shippingCzk,
                'total_czk' => $subtotal + $shippingCzk,
                'items_json' => $itemsJson,
                'shipping_json' => $shippingJson,
                'payment_method' => 'bank_transfer',
                'payment_status' => 'pending',
                'payment_details_json' => self::json($this->bank->snapshot(), 2048),
                'payment_due_at' => $now->modify('+' . $this->dueDays . ' days')->format('Y-m-d H:i:s'),
                'payment_paid_at' => null,
                'payment_verified_by' => null,
                'provider_reference' => null,
                'variable_symbol' => (string) random_int(1000000000, 9999999999),
                'idempotency_key' => $idempotencyKey,
            ]);
            $saved = $this->byIdempotencyKey($idempotencyKey);
            if ($saved === null) {
                throw new RuntimeException('Uloženou objednávku se nepodařilo načíst.');
            }
            $this->db->commit();
            return self::hydrate($saved);
        } catch (Throwable $error) {
            $this->db->rollback();
            // A concurrent request may have won the unique idempotency constraint.
            $existing = $this->byIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                $this->assertSameRequest($existing, $request);
                return self::hydrate($existing);
            }
            throw $error;
        }
    }

    /** Token is a random bearer secret; confirmation pages must set no-store and noindex. */
    public function findByToken(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }
        $row = $this->db->queryFirstRow(
            'SELECT * FROM shop_orders WHERE order_token=%s LIMIT 1', $token
        );
        return $row === null ? null : self::hydrate($row);
    }

    public function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = $this->db->queryFirstRow('SELECT * FROM shop_orders WHERE id=%i LIMIT 1', $id);
        return $row === null ? null : self::hydrate($row);
    }

    /** Bounded admin list with a single extra row for the next-page link. */
    public function managementPage(int $offset = 0, int $limit = 20, ?string $paymentStatus = null): array
    {
        if ($offset < 0 || $offset > 100000 || $limit < 1 || $limit > 100 ||
            !in_array($paymentStatus, [null, 'pending', 'paid'], true)) {
            throw new InvalidArgumentException('Neplatný filtr objednávek.');
        }
        $fields = 'SELECT id, order_number, status, customer_email, subtotal_czk, shipping_czk,
                          total_czk, payment_method, payment_status, variable_symbol, created_at
                   FROM shop_orders';
        $rows = $paymentStatus === null
            ? $this->db->query($fields . ' ORDER BY id DESC LIMIT %i OFFSET %i', $limit + 1, $offset)
            : $this->db->query($fields . ' WHERE payment_status=%s ORDER BY id DESC LIMIT %i OFFSET %i',
                $paymentStatus, $limit + 1, $offset);
        return [
            'items' => array_slice($rows, 0, $limit),
            'nextOffset' => count($rows) > $limit ? $offset + $limit : null,
        ];
    }

    /** Record manual bank reconciliation; fulfillment status is a separate concern. */
    public function markPaid(int $id, int $adminId): void
    {
        if ($id < 1 || $adminId < 1) {
            throw new InvalidArgumentException('Neplatná objednávka.');
        }
        $this->db->startTransaction();
        try {
            $row = $this->db->queryFirstRow(
                'SELECT payment_method, payment_status, order_token, variable_symbol, payment_details_json
                 FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $id
            );
            if ($row === null || $row['payment_method'] !== 'bank_transfer' ||
                !in_array($row['payment_status'], ['pending', 'paid'], true) ||
                !is_string($row['order_token']) ||
                preg_match('/^[a-f0-9]{64}$/D', $row['order_token']) !== 1 ||
                !is_string($row['variable_symbol']) ||
                preg_match('/^[0-9]{1,10}$/D', $row['variable_symbol']) !== 1 ||
                !is_string($row['payment_details_json']) || $row['payment_details_json'] === '') {
                throw new InvalidArgumentException('Platbu této objednávky nelze potvrdit.');
            }
            BankTransferPayment::fromOrder([
                'payment_details' => json_decode($row['payment_details_json'], true, 512, JSON_THROW_ON_ERROR),
            ]);
            if ($row['payment_status'] === 'pending') {
                $this->db->query(
                    'UPDATE shop_orders SET payment_status=%s, payment_paid_at=UTC_TIMESTAMP(),
                     payment_verified_by=%i WHERE id=%i AND payment_status=%s',
                    'paid', $adminId, $id, 'pending'
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function byIdempotencyKey(string $key): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM shop_orders WHERE idempotency_key=%s LIMIT 1', $key
        );
    }

    private function assertSameRequest(array $row, array $request): void
    {
        foreach ($request as $field => $value) {
            if ($field === 'shipping_czk' || $field === 'user_id') {
                $saved = $row[$field] === null ? null : (int) $row[$field];
            } else {
                $saved = $row[$field];
            }
            if ($saved !== $value) {
                throw new InvalidArgumentException('Objednávka už byla odeslána s jinými údaji.');
            }
        }
    }

    private static function hydrate(array $row): array
    {
        $row['items'] = json_decode((string) $row['items_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['shipping'] = json_decode((string) $row['shipping_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['payment_details'] = $row['payment_details_json'] === null ? [] :
            json_decode((string) $row['payment_details_json'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    private static function json(array $value, int $maxBytes): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > $maxBytes) {
            throw new InvalidArgumentException('Objednávka obsahuje příliš mnoho údajů.');
        }
        return $json;
    }
}
