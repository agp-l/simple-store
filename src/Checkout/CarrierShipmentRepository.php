<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Local preparation only: the carrier assigns the real tracking number later. */
final class CarrierShipmentRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_carrier_shipments') > 0;
    }

    public function find(int $orderId): ?array
    {
        if ($orderId < 1 || !$this->installed()) return null;
        $row = $this->db->queryFirstRow(
            'SELECT * FROM shop_carrier_shipments WHERE order_id=%i LIMIT 1', $orderId);
        if ($row !== null) {
            $row['draft'] = json_decode((string) $row['draft_json'], true, 512, JSON_THROW_ON_ERROR);
        }
        return $row;
    }

    public function save(int $orderId, int $adminId, array $draft): void
    {
        if ($orderId < 1 || $adminId < 1 ||
            !in_array($draft['method'] ?? '', ['balikovna_pickup', 'gls_pickup', 'gls_home'], true)) {
            throw new InvalidArgumentException('Neplatné podklady zásilky.');
        }
        // GLS drafts must be exportable; Balíkovna's consumer portal has no CSV import.
        if ($draft['method'] !== 'balikovna_pickup') CarrierShipmentCsv::export($draft);
        $this->db->startTransaction();
        try {
            $order = $this->db->queryFirstRow(
                'SELECT status, payment_status, payment_method, fulfillment_source, order_number, shipping_json
                 FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId);
            $shipping = $order === null ? null : json_decode((string) $order['shipping_json'], true);
            if ($order === null || !is_array($shipping) ||
                $order['payment_method'] !== 'bank_transfer' || $order['payment_status'] !== 'paid' ||
                ($order['fulfillment_source'] ?? 'own') !== 'own' ||
                in_array($order['status'], ['shipped', 'completed', 'cancelled', 'test'], true) ||
                $shipping['method'] !== $draft['method'] ||
                (string) $order['order_number'] !== $draft['order_number'] ||
                (string) ($shipping['pickup_code'] ?? '') !== $draft['pickup_code']) {
                throw new InvalidArgumentException('Objednávka se změnila. Obnov její detail a znovu ověř podklady.');
            }
            $row = $this->db->queryFirstRow(
                'SELECT status FROM shop_carrier_shipments WHERE order_id=%i LIMIT 1 FOR UPDATE', $orderId);
            if ($row !== null && $row['status'] !== 'draft') {
                throw new InvalidArgumentException('Zásilka už má číslo od dopravce. Podklady nelze přepsat.');
            }
            $json = json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($row === null) {
                $this->db->insert('shop_carrier_shipments', [
                    'order_id' => $orderId, 'status' => 'draft', 'method' => $draft['method'],
                    'draft_json' => $json, 'created_by' => $adminId, 'updated_by' => $adminId,
                ]);
            } else {
                $this->db->query('UPDATE shop_carrier_shipments SET draft_json=%s, updated_by=%i
                    WHERE order_id=%i AND status=%s', $json, $adminId, $orderId, 'draft');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function register(int $orderId, int $adminId, string $trackingNumber): void
    {
        $trackingNumber = trim($trackingNumber);
        if ($orderId < 1 || $adminId < 1 ||
            preg_match('/^[A-Za-z0-9][A-Za-z0-9\/-]{5,49}$/D', $trackingNumber) !== 1) {
            throw new InvalidArgumentException('Opiš skutečné číslo zásilky přidělené dopravcem (6 až 50 znaků).');
        }
        $this->db->startTransaction();
        try {
            $order = $this->db->queryFirstRow(
                'SELECT status, payment_status, fulfillment_source FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE',
                $orderId);
            $row = $this->db->queryFirstRow(
                'SELECT status FROM shop_carrier_shipments WHERE order_id=%i LIMIT 1 FOR UPDATE', $orderId);
            if ($order === null || $row === null || $row['status'] !== 'draft' ||
                $order['payment_status'] !== 'paid' ||
                ($order['fulfillment_source'] ?? 'own') !== 'own' ||
                in_array($order['status'], ['cancelled', 'test'], true)) {
                throw new InvalidArgumentException('Zásilku nelze zapsat. Obnov detail objednávky.');
            }
            $this->db->query('UPDATE shop_carrier_shipments SET status=%s, tracking_number=%s,
                updated_by=%i WHERE order_id=%i AND status=%s',
                'registered', $trackingNumber, $adminId, $orderId, 'draft');
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }
}
