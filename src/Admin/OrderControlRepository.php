<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Product\ProductStockRepository;
use Throwable;

/** Exceptional administrator actions, deliberately separate from everyday order transitions. */
final class OrderControlRepository
{
    public function __construct(private MeekroDB $db, private ?ProductStockRepository $stock = null)
    {
    }

    public function installed(): bool
    {
        return $this->tableExists('shop_order_admin_events') &&
            $this->tableExists('shop_order_financial_events');
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
            if ($old === 'cancelled') $this->stock?->reopen($orderId);

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

    /** Undo only the administrator's bank-transfer confirmation; retain its old details. */
    public function correctPayment(int $orderId, int $adminId, string $reason, string $confirmation): void
    {
        self::assertActorAndReason($orderId, $adminId, $reason);
        if (!$this->installed()) {
            throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        }
        if ($confirmation !== 'not_received') {
            throw new InvalidArgumentException('Potvrď opravu chybně označené platby.');
        }
        $this->db->startTransaction();
        try {
            $order = $this->db->queryFirstRow(
                'SELECT * FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId
            );
            if ($order === null || $order['payment_method'] !== 'bank_transfer' ||
                $order['payment_status'] !== 'paid') {
                throw new InvalidArgumentException('Opravit lze pouze platbu převodem označenou jako zaplacenou.');
            }
            $this->recordFinancialEvent($order, 'payment_correction', $adminId, $reason);
            $this->db->query(
                'UPDATE shop_orders SET payment_status=%s, payment_paid_at=NULL,
                 payment_verified_by=NULL WHERE id=%i AND payment_status=%s AND payment_method=%s',
                'pending', $orderId, 'paid', 'bank_transfer'
            );
            $this->db->insert('shop_order_admin_events', [
                'order_id' => $orderId,
                'order_number' => $order['order_number'],
                'action' => 'payment_correction',
                'old_status' => 'paid',
                'new_status' => 'pending',
                'reason' => trim($reason),
                'admin_id' => $adminId,
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    /** Test orders leave no accounting trace; actual sales retain their financial evidence. */
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
            $bankTransfer = ($order['payment_method'] ?? '') === 'bank_transfer' &&
                in_array($order['payment_status'] ?? '', ['pending', 'paid'], true) &&
                ($order['provider_reference'] ?? null) === null;
            $carrier = $this->tableExists('shop_carrier_shipments') ? $this->db->queryFirstRow(
                'SELECT status FROM shop_carrier_shipments WHERE order_id=%i LIMIT 1 FOR UPDATE', $orderId
            ) : null;
            if ((!$test && !$bankTransfer) ||
                $this->hasRelatedRow('shop_packeta_shipments', $orderId) ||
                ($carrier !== null && $carrier['status'] !== 'draft') ||
                $this->hasRelatedRow('shop_packeta_cancelled_shipments', $orderId) ||
                $this->hasRelatedRow('shop_documents', $orderId) ||
                $this->hasRelatedRow('shop_invoices', $orderId)) {
                throw new InvalidArgumentException('Nejdřív vyřeš navázaný doklad nebo zásilku u dopravce; podklady bez čísla lze smazat spolu s objednávkou.');
            }
            if (!$test && $order['payment_status'] === 'paid' &&
                (!$this->tableExists('shop_sale_lines') || !$this->tableExists('shop_deleted_sale_lines'))) {
                throw new InvalidArgumentException('Před smazáním zaplacené objednávky aktualizuj SQL tabulky pro zachování položek prodeje.');
            }

            if ($bankTransfer) {
                $this->recordFinancialEvent($order, 'order_deleted', $adminId, $reason);
            }

            $soldLines = [];
            if (!$test && $order['payment_status'] === 'paid') {
                $soldLines = $this->db->query('SELECT product_key, name, quantity, unit_price_czk
                    FROM shop_sale_lines WHERE order_id=%i ORDER BY line_no', $orderId);
                if ($soldLines === []) {
                    $snapshot = json_decode((string) $order['items_json'], true);
                    if (!is_array($snapshot) || $snapshot === []) {
                        throw new InvalidArgumentException('Položky objednávky chybí. Objednávku nelze bezpečně smazat.');
                    }
                    $soldLines = $snapshot;
                }
                foreach ($soldLines as $line) {
                    $this->db->insert('shop_deleted_sale_lines', [
                        'order_number' => $order['order_number'], 'product_key' => $line['product_key'],
                        'name' => $line['name'], 'quantity' => $line['quantity'],
                        'unit_price_czk' => $line['unit_price_czk'], 'order_status' => $order['status'],
                        'order_created_at' => $order['created_at'],
                    ]);
                }
            }

            if ($test) {
                // Any earlier changes to this artificial order are also only development data.
                $this->db->query('DELETE FROM shop_order_admin_events WHERE order_id=%i', $orderId);
                $this->db->query('DELETE FROM shop_order_financial_events WHERE order_id=%i', $orderId);
                if ($this->tableExists('shop_tax_entries')) {
                    if ($this->tableExists('shop_tax_entry_events')) {
                        $this->db->query('DELETE e FROM shop_tax_entry_events e
                            JOIN shop_tax_entries t ON t.id=e.entry_id WHERE t.order_id=%i', $orderId);
                    }
                    $this->db->query('DELETE FROM shop_tax_entries WHERE order_id=%i', $orderId);
                }
                if ($this->tableExists('shop_mail_outbox')) {
                    $this->db->query('DELETE FROM shop_mail_outbox WHERE order_id=%i', $orderId);
                }
            } else {
                $this->db->insert('shop_order_admin_events', [
                    'order_id' => $orderId, 'order_number' => $order['order_number'],
                    'action' => 'order_deleted', 'old_status' => $order['status'],
                    'new_status' => 'deleted', 'reason' => trim($reason), 'admin_id' => $adminId,
                ]);
                if ($this->tableExists('shop_tax_entries')) {
                    $this->db->query('UPDATE shop_tax_entries SET order_id=NULL WHERE order_id=%i', $orderId);
                }
                if ($this->tableExists('shop_mail_outbox')) {
                    $this->db->query('DELETE FROM shop_mail_outbox WHERE order_id=%i AND state IN (%s,%s,%s)',
                        $orderId, 'queued', 'failed', 'sending');
                    $this->db->query('UPDATE shop_mail_outbox SET order_id=NULL WHERE order_id=%i', $orderId);
                }
                if (in_array($order['status'], ['shipped', 'completed'], true) &&
                    $this->tableExists('shop_sale_lines') && $this->tableExists('shop_stock_movements')) {
                    foreach ($soldLines as $line) {
                        $this->db->insert('shop_stock_movements', [
                            'product_key' => $line['product_key'],
                            'movement_date' => gmdate('Y-m-d'),
                            'quantity_change' => -(int) $line['quantity'],
                            'unit_cost_czk' => null,
                            'description' => 'Dříve odeslaná smazaná objednávka ' . $order['order_number'],
                            'reference' => (string) $order['order_number'],
                        ]);
                    }
                }
            }
            if ($carrier !== null) {
                $this->db->query('DELETE FROM shop_carrier_shipments WHERE order_id=%i AND status=%s',
                    $orderId, 'draft');
            }
            $this->stock?->release($orderId);
            $this->stock?->forget($orderId);
            $this->db->query(
                'DELETE FROM shop_orders WHERE id=%i AND order_number=%s AND status=%s
                 AND payment_method=%s AND payment_status=%s',
                $orderId, $order['order_number'], $order['status'], $order['payment_method'], $order['payment_status']
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function recordFinancialEvent(array $order, string $action, int $adminId, string $reason): void
    {
        $this->db->insert('shop_order_financial_events', [
            'order_id' => (int) $order['id'],
            'order_number' => (string) $order['order_number'],
            'variable_symbol' => $order['variable_symbol'] ?? null,
            'action' => $action,
            'payment_status_before' => (string) $order['payment_status'],
            'payment_paid_at' => $order['payment_paid_at'] ?? null,
            'payment_verified_by' => $order['payment_verified_by'] ?? null,
            'total_czk' => (int) $order['total_czk'],
            'reason' => trim($reason),
            'admin_id' => $adminId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
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
