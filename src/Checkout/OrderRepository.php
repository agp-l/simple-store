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
        string $idempotencyKey,
        bool $testOrder = false
    ): array {
        if (!$testOrder && $this->bank === null) {
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
            'payment_method' => $testOrder ? 'test' : 'bank_transfer',
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
            // The payment reference stays stable and immediately visible in new order numbers.
            // Existing orders keep their original numbers and payment references.
            $variableSymbol = $testOrder ? null : (string) random_int(1000000000, 9999999999);
            $orderNumber = $testOrder
                ? 'TEST-' . $now->format('y') . '-' . strtoupper(bin2hex(random_bytes(4)))
                : 'DB-' . $now->format('y') . '-' . $variableSymbol;
            $this->db->insert('shop_orders', [
                'user_id' => $userId,
                'order_number' => $orderNumber,
                'order_token' => bin2hex(random_bytes(32)),
                'status' => $testOrder ? 'test' : 'new',
                'customer_email' => $email,
                'subtotal_czk' => $subtotal,
                'shipping_czk' => $shippingCzk,
                'total_czk' => $subtotal + $shippingCzk,
                'items_json' => $itemsJson,
                'shipping_json' => $shippingJson,
                'payment_method' => $request['payment_method'],
                'payment_status' => $testOrder ? 'test' : 'pending',
                'payment_details_json' => $testOrder ? null : self::json($this->bank->snapshot(), 2048),
                'payment_due_at' => $testOrder ? null :
                    $now->modify('+' . $this->dueDays . ' days')->format('Y-m-d H:i:s'),
                'payment_paid_at' => null,
                'payment_verified_by' => null,
                'provider_reference' => null,
                'variable_symbol' => $variableSymbol,
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

    public function fulfillmentSourceInstalled(): bool
    {
        foreach (['fulfillment_source', 'fulfillment_note'] as $column) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
                'shop_orders', $column) === 0) return false;
        }
        return true;
    }

    /** Bounded admin list with a single extra row for the next-page link. */
    public function managementPage(int $offset = 0, int $limit = 20, ?string $filter = null): array
    {
        if ($offset < 0 || $offset > 100000 || $limit < 1 || $limit > 100 ||
            !in_array($filter, [null, 'pending', 'paid', 'test', 'processing', 'ready_to_ship',
                'shipped', 'completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Neplatný filtr objednávek.');
        }
        $parcelField = (new PacketaShipmentRepository($this->db))->installed()
            ? '(SELECT status FROM shop_packeta_shipments WHERE order_id=shop_orders.id)'
            : 'NULL';
        $fulfillmentField = $this->fulfillmentSourceInstalled() ? 'fulfillment_source' : "'own'";
        $fields = 'SELECT id, order_number, status, customer_email, subtotal_czk, shipping_czk,
                          total_czk, payment_method, payment_status, variable_symbol, created_at,
                          ' . $parcelField . ' AS shipment_status,
                          ' . $fulfillmentField . ' AS fulfillment_source FROM shop_orders';
        $rows = $filter === null
            ? $this->db->query($fields . ' ORDER BY id DESC LIMIT %i OFFSET %i', $limit + 1, $offset)
            : $this->db->query($fields . (in_array($filter, ['pending', 'paid', 'test'], true)
                ? ' WHERE payment_status=%s' : ' WHERE status=%s') .
                ' ORDER BY id DESC LIMIT %i OFFSET %i', $filter, $limit + 1, $offset);
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
                'SELECT status, payment_method, payment_status, order_token, variable_symbol, payment_details_json
                 FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $id
            );
            if ($row === null || in_array($row['status'], ['cancelled', 'test'], true) ||
                $row['payment_method'] !== 'bank_transfer' ||
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

    /** Manual fulfillment state; a bank transfer must be verified before shipping. */
    public function setFulfillmentStatus(int $id, string $status, string $source = 'own', string $note = ''): void
    {
        if ($id < 1 || !in_array($status,
            ['processing', 'ready_to_ship', 'shipped', 'completed', 'cancelled'], true) ||
            !in_array($source, ['own', 'external'], true)) {
            throw new InvalidArgumentException('Neplatný stav objednávky.');
        }
        $note = trim($note);
        if (strlen($note) > 190 || preg_match('//u', $note) !== 1 ||
            preg_match('/[\x00-\x1f\x7f]/', $note)) {
            throw new InvalidArgumentException('Poznámka k expedici je příliš dlouhá nebo obsahuje nepovolené znaky.');
        }
        if ($source === 'own') $note = '';
        $hasSource = $this->fulfillmentSourceInstalled();
        if (!$hasSource && $source === 'external') {
            throw new InvalidArgumentException('Pro expedici dodavatelem nejdřív aktualizuj SQL tabulky v administraci.');
        }
        $this->db->startTransaction();
        try {
            $row = $this->db->queryFirstRow(
                'SELECT * FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $id
            );
            $allowed = match ($row['status'] ?? '') {
                'new' => ['processing', 'ready_to_ship', 'shipped', 'cancelled'],
                'processing' => ['processing', 'ready_to_ship', 'shipped'],
                'ready_to_ship' => ['ready_to_ship', 'processing', 'shipped'],
                'shipped' => ['shipped', 'completed'],
                default => [],
            };
            if ($row === null || in_array($row['status'], ['completed', 'cancelled', 'test'], true) ||
                !in_array($status, $allowed, true) ||
                ($status === 'cancelled' && $row['payment_status'] === 'paid') ||
                ($status !== 'cancelled' && $row['payment_status'] !== 'paid')) {
                throw new InvalidArgumentException('Tento přechod stavu není možný. Zaplacenou objednávku před zrušením nejprve vyřeš individuálně.');
            }
            if (in_array($row['status'], ['shipped'], true) &&
                $source !== ($row['fulfillment_source'] ?? 'own')) {
                throw new InvalidArgumentException('U odeslané objednávky už nelze změnit způsob expedice.');
            }
            $shipping = json_decode((string) ($row['shipping_json'] ?? ''), true);
            if (in_array($shipping['method'] ?? '', ['zasilkovna_pickup', 'zasilkovna_home'], true)) {
                if ((int) $this->db->queryFirstField(
                    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                    'shop_packeta_shipments') === 0) {
                    if ($source === 'own' && in_array($status, ['ready_to_ship', 'shipped'], true)) {
                        throw new InvalidArgumentException('Aktualizuj SQL tabulky v sekci Databáze.');
                    }
                    $shipment = null;
                } else {
                    $shipment = $this->db->queryFirstRow(
                        'SELECT status FROM shop_packeta_shipments WHERE order_id=%i LIMIT 1', $id);
                }
                if ($source === 'own' && in_array($status, ['ready_to_ship', 'shipped'], true) &&
                    ($shipment === null || $shipment['status'] !== 'created')) {
                    throw new InvalidArgumentException('Nejdřív vytvoř aktivní zásilku u Zásilkovny. Storno či nejasný výsledek nelze označit jako připravené nebo odeslané.');
                }
                if ($source === 'external' && $shipment !== null &&
                    in_array($shipment['status'], ['created', 'submitting', 'uncertain',
                        'cancelling', 'cancel_uncertain'], true)) {
                    throw new InvalidArgumentException('Nejdřív vyřeš nebo stornuj zásilku vytvořenou v tomto obchodě. Potom může objednávku převzít dodavatel.');
                }
            }
            if ($hasSource) {
                $this->db->query('UPDATE shop_orders SET status=%s, fulfillment_source=%s,
                    fulfillment_note=%s WHERE id=%i AND status=%s',
                    $status, $source, $note === '' ? null : $note, $id, $row['status']);
            } else {
                $this->db->query('UPDATE shop_orders SET status=%s WHERE id=%i AND status=%s',
                    $status, $id, $row['status']);
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
