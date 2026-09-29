<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** A durable API reservation is committed before sending createPacket. */
final class PacketaShipmentRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_packeta_shipments') > 0;
    }

    public function find(int $orderId): ?array
    {
        return $this->db->queryFirstRow(
            'SELECT * FROM shop_packeta_shipments WHERE order_id=%i LIMIT 1', $orderId);
    }

    public function reserve(int $orderId, int $adminId, array $draft): void
    {
        if ($orderId < 1 || $adminId < 1) throw new InvalidArgumentException('Neplatná objednávka.');
        $this->db->startTransaction();
        try {
            $order = $this->db->queryFirstRow(
                'SELECT payment_method, payment_status, status, shipping_json
                 FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId);
            $shipping = is_array($order) ? json_decode((string) $order['shipping_json'], true) : null;
            if ($order === null || $order['payment_method'] !== 'bank_transfer' ||
                $order['payment_status'] !== 'paid' ||
                in_array($order['status'], ['cancelled', 'completed', 'test'], true) ||
                !is_array($shipping) || ($shipping['method'] ?? '') !== $draft['method']) {
                throw new InvalidArgumentException('Objednávka není připravená k podání. Obnov stránku.');
            }
            $current = $this->find($orderId);
            if ($current !== null && $current['status'] !== 'rejected') {
                throw new InvalidArgumentException('Tato objednávka už má pokus o podání. Zkontroluj stav zásilky.');
            }
            $attributes = $draft['attributes'];
            $snapshot = json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($snapshot) > 16000) throw new InvalidArgumentException('Údaje zásilky jsou příliš dlouhé.');
            if ($current === null) {
                $this->db->insert('shop_packeta_shipments', ['order_id' => $orderId,
                    'status' => 'submitting', 'method' => $draft['method'],
                    'weight_kg' => $attributes['weight'], 'submitted_json' => $snapshot,
                    'created_by' => $adminId, 'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s')]);
            } else {
                $this->db->query('UPDATE shop_packeta_shipments SET status=%s, method=%s,
                    weight_kg=%s, submitted_json=%s, last_error=NULL, created_by=%i,
                    updated_at=UTC_TIMESTAMP() WHERE order_id=%i AND status=%s',
                    'submitting', $draft['method'], $attributes['weight'], $snapshot,
                    $adminId, $orderId, 'rejected');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function complete(int $orderId, array $packet): void
    {
        $this->db->query('UPDATE shop_packeta_shipments SET status=%s, packet_id=%s,
            barcode=%s, barcode_text=%s, last_error=NULL, updated_at=UTC_TIMESTAMP()
            WHERE order_id=%i AND status=%s', 'created', $packet['id'], $packet['barcode'],
            $packet['barcode_text'], $orderId, 'submitting');
    }

    public function failed(int $orderId, string $message, bool $rejected): void
    {
        $this->db->query('UPDATE shop_packeta_shipments SET status=%s, last_error=%s,
            updated_at=UTC_TIMESTAMP() WHERE order_id=%i AND status=%s',
            $rejected ? 'rejected' : 'uncertain', strlen($message) <= 500 ? $message :
                'Zkontroluj zásilku v klientské sekci.', $orderId, 'submitting');
    }

    /** A human has found the matching packet in the Packeta client section. */
    public function reconcile(int $orderId, string $barcode): void
    {
        if (preg_match('/^Z([0-9]{1,20})$/D', $barcode, $match) !== 1) {
            throw new InvalidArgumentException('Zadej číslo zásilky ve tvaru Z1234567890.');
        }
        $this->db->query('UPDATE shop_packeta_shipments SET status=%s, packet_id=%s,
            barcode=%s, barcode_text=%s, last_error=NULL, updated_at=UTC_TIMESTAMP()
            WHERE order_id=%i AND status IN (%s, %s)', 'created', $match[1], $barcode, $barcode,
            $orderId, 'uncertain', 'submitting');
    }

    /** Only after explicitly checking that no parcel was created upstream. */
    public function allowRetry(int $orderId): void
    {
        $this->db->query('UPDATE shop_packeta_shipments SET status=%s,
            last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE order_id=%i
            AND status IN (%s, %s) AND updated_at < UTC_TIMESTAMP() - INTERVAL 60 SECOND',
            'rejected', $orderId, 'uncertain', 'submitting');
    }

    public function storeCourierNumber(int $orderId, string $barcode, string $number): void
    {
        $this->db->query('UPDATE shop_packeta_shipments SET courier_number=%s,
            updated_at=UTC_TIMESTAMP() WHERE order_id=%i AND barcode=%s AND status=%s
            AND courier_number IS NULL', $number, $orderId, $barcode, 'created');
    }
}
