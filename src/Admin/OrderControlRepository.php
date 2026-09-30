<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Exceptional administrator actions, deliberately separate from everyday order transitions. */
final class OrderControlRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        return $this->tableExists('shop_order_admin_events');
    }

    public function eventsForOrder(int $orderId): array
    {
        if ($orderId < 1 || !$this->installed()) return [];
        return $this->db->query(
            'SELECT action, old_status, new_status, reason, admin_id, created_at
             FROM shop_order_admin_events WHERE order_id=%i ORDER BY id DESC', $orderId
        );
    }

    /** Deleted orders cannot be opened, but their minimal action log remains visible. */
    public function recentDeletions(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 50 || !$this->installed()) return [];
        return $this->db->query(
            'SELECT order_number, reason, admin_id, created_at
             FROM shop_order_admin_events WHERE action=%s ORDER BY id DESC LIMIT %i',
            'order_deleted', $limit
        );
    }

    /** Correct an accidentally advanced state without changing payment or fulfillment source. */
    public function correctFulfillment(
        int $orderId,
        string $target,
        int $adminId,
        string $reason,
        string $confirmation
    ): void {
        self::assertActorAndReason($orderId, $adminId, $reason);
        if (!$this->installed()) {
            throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        }
        $this->db->startTransaction();
        try {
            $order = $this->db->queryFirstRow(
                'SELECT * FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId
            );
            if ($order === null || !in_array($order['fulfillment_source'] ?? 'own', ['own', 'external'], true)) {
                throw new InvalidArgumentException('Objednávka pro opravu neexistuje.');
            }
            $old = (string) $order['status'];
            $allowed = match ($old) {
                'shipped' => $confirmation === 'not_handed' &&
                    in_array($target, ['processing', 'ready_to_ship'], true),
                'completed' => ($confirmation === 'not_handed' &&
                    in_array($target, ['processing', 'ready_to_ship'], true)) ||
                    ($confirmation === 'not_delivered' && $target === 'shipped'),
                'cancelled' => $confirmation === 'reopen' && $target === 'new',
                default => false,
            };
            if (!$allowed || $old === $target ||
                ($old === 'cancelled' && !self::unpaidBankTransfer($order)) ||
                ($old !== 'cancelled' && ($order['payment_status'] ?? '') !== 'paid')) {
                throw new InvalidArgumentException('Stav objednávky nelze tímto způsobem opravit.');
            }
            $this->assertParcelCompatible($orderId, $order, $target);

            $this->db->query(
                'UPDATE shop_orders SET status=%s WHERE id=%i AND status=%s
                 AND fulfillment_source=%s AND payment_status=%s',
                $target, $orderId, $old, $order['fulfillment_source'] ?? 'own', $order['payment_status']
            );
            $this->db->insert('shop_order_admin_events', [
                'order_id' => $orderId,
                'order_number' => $order['order_number'],
                'action' => 'fulfillment_correction',
                'old_status' => $old,
                'new_status' => $target,
                'reason' => trim($reason),
                'admin_id' => $adminId,
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    /** Purge an unfulfilled unpaid order; retain an audit entry without customer details. */
    public function deleteOrder(int $orderId, string $typedNumber, int $adminId, string $reason): void
    {
        self::assertActorAndReason($orderId, $adminId, $reason);
        if (!$this->installed()) {
            throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        }
        $this->db->startTransaction();
        try {
            $order = $this->db->queryFirstRow(
                'SELECT * FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId
            );
            if ($order === null || $typedNumber === '' ||
                !hash_equals((string) $order['order_number'], $typedNumber)) {
                throw new InvalidArgumentException('Pro smazání opiš přesné číslo objednávky.');
            }
            $test = ($order['payment_method'] ?? '') === 'test' &&
                ($order['payment_status'] ?? '') === 'test' && ($order['status'] ?? '') === 'test';
            $unpaid = self::unpaidBankTransfer($order) &&
                in_array($order['status'], ['new', 'cancelled'], true);
            if ((!$test && !$unpaid) ||
                $order['payment_paid_at'] !== null || $order['payment_verified_by'] !== null ||
                ($order['provider_reference'] ?? null) !== null ||
                $this->hasRelatedRow('shop_packeta_shipments', $orderId) ||
                $this->hasRelatedRow('shop_packeta_cancelled_shipments', $orderId) ||
                $this->hasRelatedRow('shop_documents', $orderId)) {
                throw new InvalidArgumentException('Objednávku s platbou, dokladem nebo zásilkou nelze smazat.');
            }

            $this->db->insert('shop_order_admin_events', [
                'order_id' => $orderId,
                'order_number' => $order['order_number'],
                'action' => 'order_deleted',
                'old_status' => $order['status'],
                'new_status' => 'deleted',
                'reason' => trim($reason),
                'admin_id' => $adminId,
            ]);
            $this->db->query(
                'DELETE FROM shop_orders WHERE id=%i AND order_number=%s AND status=%s
                 AND payment_method=%s AND payment_status=%s AND payment_paid_at IS NULL
                 AND payment_verified_by IS NULL AND provider_reference IS NULL',
                $orderId, $order['order_number'], $order['status'], $order['payment_method'],
                $order['payment_status']
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function assertParcelCompatible(int $orderId, array $order, string $target): void
    {
        $shipping = json_decode((string) ($order['shipping_json'] ?? ''), true);
        if (!is_array($shipping) || !is_string($shipping['method'] ?? null)) {
            throw new InvalidArgumentException('Dopravu objednávky nelze ověřit.');
        }
        if (!in_array($shipping['method'], ['zasilkovna_pickup', 'zasilkovna_home'], true)) return;
        if (!$this->tableExists('shop_packeta_shipments')) {
            if (($order['fulfillment_source'] ?? 'own') === 'own' &&
                in_array($target, ['ready_to_ship', 'shipped'], true)) {
                throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
            }
            return;
        }

        $shipment = $this->db->queryFirstRow(
            'SELECT status FROM shop_packeta_shipments WHERE order_id=%i LIMIT 1 FOR UPDATE', $orderId
        );
        $active = $shipment !== null && in_array($shipment['status'], [
            'created', 'submitting', 'uncertain', 'cancelling', 'cancel_uncertain',
        ], true);
        if ($target === 'new' && $active) {
            throw new InvalidArgumentException('Nejdřív dořeš aktivní zásilku Zásilkovny.');
        }
        if (($order['fulfillment_source'] ?? 'own') === 'own' &&
            in_array($target, ['ready_to_ship', 'shipped'], true) &&
            ($shipment['status'] ?? '') !== 'created') {
            throw new InvalidArgumentException('Pro tento stav musí být aktivní zásilka Zásilkovny.');
        }
        if (($order['fulfillment_source'] ?? 'own') === 'external' && $active) {
            throw new InvalidArgumentException('Nejdřív dořeš zásilku vytvořenou v tomto obchodě.');
        }
    }

    private static function unpaidBankTransfer(array $order): bool
    {
        return ($order['payment_method'] ?? '') === 'bank_transfer' &&
            ($order['payment_status'] ?? '') === 'pending' &&
            ($order['payment_paid_at'] ?? null) === null &&
            ($order['payment_verified_by'] ?? null) === null &&
            ($order['provider_reference'] ?? null) === null;
    }

    private static function assertActorAndReason(int $orderId, int $adminId, string $reason): void
    {
        $reason = trim($reason);
        if ($orderId < 1 || $adminId < 1 ||
            preg_match('/^.{8,190}$/usD', $reason) !== 1 ||
            preg_match('/\p{C}/u', $reason) !== 0) {
            throw new InvalidArgumentException('Uveď důvod (8 až 190 znaků, bez řídicích znaků).');
        }
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $table
        ) > 0;
    }

    private function hasRelatedRow(string $table, int $orderId): bool
    {
        if (!$this->tableExists($table)) return false;
        // The table names are fixed strings supplied only by this class.
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE order_id=%i', $orderId
        ) > 0;
    }
}
