<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Checkout\ShippingPolicy;
use Throwable;

/** Changes the actual dispatch carrier without rewriting the customer's purchase or invoice. */
final class OrderShippingRepository
{
    public function __construct(private MeekroDB $db, private ShippingPolicy $shipping)
    {
    }

    public function installed(): bool
    {
        if ((int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
            'shop_orders', 'dispatch_shipping_json') === 0) return false;
        foreach (['shop_order_admin_events', 'shop_packeta_shipments', 'shop_carrier_shipments'] as $table) {
            if ((int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table) === 0) return false;
        }
        return true;
    }

    /** @return array<string, string> Method codes and labels available for this order. */
    public function optionsFor(array $order): array
    {
        $original = $order['shipping_ordered'] ?? $order['shipping'] ?? null;
        $current = $order['shipping'] ?? null;
        if (!is_array($original) || !is_array($current) || !self::editable($order)) return [];

        $currentCode = (string) ($current['method'] ?? '');
        $addressAvailable = self::hasHomeAddress($current);
        if (!$addressAvailable && !ShippingPolicy::isPickup($currentCode)) return [];

        $options = [];
        foreach ($this->shipping->options() as $option) {
            if ($option['group'] === 'home' && $option['code'] !== $currentCode) {
                $options[$option['code']] = $option['label'];
            }
        }
        // An administrator may always restore the agreed delivery, even if since disabled in checkout.
        $originalCode = (string) ($original['method'] ?? '');
        if ($originalCode !== $currentCode && ShippingPolicy::known($originalCode)) {
            $options[$originalCode] = (string) ($original['label'] ?? $originalCode);
        }
        return $options;
    }

    public function needsAddress(array $order): bool
    {
        $current = $order['shipping'] ?? null;
        return is_array($current) && ShippingPolicy::isPickup((string) ($current['method'] ?? '')) &&
            !self::hasHomeAddress($current);
    }

    public function change(
        int $orderId,
        int $adminId,
        string $newMethod,
        string $expectedMethod,
        string $reason,
        bool $draftNotSubmitted = false,
        array $homeAddress = [],
        bool $addressConfirmed = false
    ): void {
        $reason = trim($reason);
        if ($orderId < 1 || $adminId < 1 ||
            preg_match('/^.{8,190}$/usD', $reason) !== 1 ||
            preg_match('/\p{C}/u', $reason) !== 0 ||
            !ShippingPolicy::known($newMethod) ||
            !ShippingPolicy::known($expectedMethod)) {
            throw new InvalidArgumentException('Vyber dopravce a uveď důvod změny (8 až 190 znaků).');
        }
        if (!$this->installed()) {
            throw new InvalidArgumentException('Pro změnu dopravce nejdřív aktualizuj SQL tabulky v administraci.');
        }
        $this->db->startTransaction();
        try {
            $row = $this->db->queryFirstRow(
                'SELECT * FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId);
            if ($row === null || !self::editable($row)) {
                throw new InvalidArgumentException('Dopravce lze změnit jen před odesláním objednávky.');
            }
            $original = json_decode((string) $row['shipping_json'], true, 512, JSON_THROW_ON_ERROR);
            $effective = !empty($row['dispatch_shipping_json']) ?
                json_decode((string) $row['dispatch_shipping_json'], true, 512, JSON_THROW_ON_ERROR) : $original;
            if (!is_array($original) || !is_array($effective)) {
                throw new InvalidArgumentException('Údaje doručení objednávky jsou poškozené.');
            }
            $oldMethod = (string) ($effective['method'] ?? '');
            if ($oldMethod !== $expectedMethod || $newMethod === $oldMethod) {
                throw new InvalidArgumentException('Doprava se mezitím změnila. Obnov detail objednávky.');
            }
            $options = $this->optionsFor([
                'shipping_ordered' => $original, 'shipping' => $effective,
                'status' => $row['status'], 'payment_method' => $row['payment_method'],
                'payment_status' => $row['payment_status'],
            ]);
            if (!isset($options[$newMethod])) {
                throw new InvalidArgumentException('Pro tuto objednávku nelze vybrat zvoleného dopravce.');
            }
            $packeta = $this->db->queryFirstRow(
                'SELECT status FROM shop_packeta_shipments WHERE order_id=%i LIMIT 1 FOR UPDATE', $orderId);
            if ($packeta !== null && !in_array($packeta['status'], ['cancelled', 'rejected'], true)) {
                throw new InvalidArgumentException('Nejdřív vyřeš nebo stornuj aktivní zásilku Zásilkovny.');
            }
            $carrier = $this->db->queryFirstRow(
                'SELECT status FROM shop_carrier_shipments WHERE order_id=%i LIMIT 1 FOR UPDATE', $orderId);
            if ($carrier !== null && $carrier['status'] !== 'draft') {
                throw new InvalidArgumentException('Zásilka už má číslo od dopravce. Dopravce nyní nelze změnit.');
            }
            if ($carrier !== null && !$draftNotSubmitted) {
                throw new InvalidArgumentException('Uloženy jsou podklady k podání. Potvrď, že nebyly importovány u dopravce; při změně se smažou.');
            }
            if ($carrier !== null) {
                $this->db->query('DELETE FROM shop_carrier_shipments WHERE order_id=%i AND status=%s',
                    $orderId, 'draft');
            }
            if ($newMethod === ($original['method'] ?? null)) {
                $dispatchJson = null;
            } else {
                // Preserve the effective address, except when a pickup order needs a verified home address.
                // The agreed price and original checkout snapshot never change.
                $dispatch = $effective;
                if (!self::hasHomeAddress($dispatch)) {
                    if (!ShippingPolicy::isPickup($oldMethod) || !$addressConfirmed) {
                        throw new InvalidArgumentException('Vyplň a potvrď ověření úplné adresy pro doručení domů.');
                    }
                    $dispatch = array_replace($dispatch, self::validatedHomeAddress($homeAddress));
                }
                foreach (['pickup_point', 'pickup_address', 'pickup_code', 'pickup_postal_code',
                    'pickup_verified', 'pickup_source'] as $field) unset($dispatch[$field]);
                $dispatch['method'] = $newMethod;
                $dispatch['label'] = $options[$newMethod];
                $dispatchJson = json_encode($dispatch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (strlen($dispatchJson) > 16384) {
                    throw new InvalidArgumentException('Údaje o doručení jsou příliš dlouhé.');
                }
            }
            $this->db->query('UPDATE shop_orders SET dispatch_shipping_json=%s WHERE id=%i',
                $dispatchJson, $orderId);
            $this->db->insert('shop_order_admin_events', [
                'order_id' => $orderId, 'order_number' => (string) $row['order_number'],
                'action' => 'shipping_changed', 'old_status' => $oldMethod, 'new_status' => $newMethod,
                'reason' => $reason, 'admin_id' => $adminId,
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private static function editable(array $order): bool
    {
        if (!in_array($order['status'] ?? '', ['new', 'processing', 'ready_to_ship', 'test'], true)) return false;
        if (($order['payment_method'] ?? '') === 'test') {
            return ($order['status'] ?? '') === 'test' && ($order['payment_status'] ?? '') === 'test';
        }
        if (($order['payment_method'] ?? '') === 'bank_transfer') {
            return in_array($order['payment_status'] ?? '', ['pending', 'paid'], true);
        }
        return in_array($order['payment_method'] ?? '', ['comgate', 'gopay'], true) &&
            ($order['payment_status'] ?? '') === 'paid';
    }

    private static function hasHomeAddress(array $shipping): bool
    {
        if (($shipping['country'] ?? null) !== 'CZ') return false;
        foreach (['street' => 190, 'city' => 120, 'postal_code' => 20] as $field => $limit) {
            if (!is_string($shipping[$field] ?? null) || trim($shipping[$field]) === '' ||
                strlen($shipping[$field]) > $limit || preg_match('/\p{C}/u', $shipping[$field]) !== 0) {
                return false;
            }
        }
        return true;
    }

    /** Explicit administrator entry when the original checkout selected only a pickup point. */
    private static function validatedHomeAddress(array $input): array
    {
        if (($input['country'] ?? 'CZ') !== 'CZ') {
            throw new InvalidArgumentException('Doručení lze přesměrovat jen na adresu v České republice.');
        }
        $result = ['country' => 'CZ'];
        foreach (['street' => 190, 'city' => 120, 'postal_code' => 20] as $field => $limit) {
            $value = $input[$field] ?? null;
            if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $limit ||
                preg_match('/\p{C}/u', $value) !== 0) {
                throw new InvalidArgumentException('Doplň celou ověřenou adresu pro doručení domů.');
            }
            $result[$field] = trim($value);
        }
        if (preg_match('/^[0-9 ]{5,6}$/D', $result['postal_code']) !== 1 ||
            strlen(str_replace(' ', '', $result['postal_code'])) !== 5) {
            throw new InvalidArgumentException('PSČ doručení musí obsahovat pět číslic.');
        }
        if (preg_match('/^.+\s+\d+[a-zA-Z]?(?:\/\d+[a-zA-Z]?)?$/uD', $result['street']) !== 1) {
            throw new InvalidArgumentException('Uveď ulici včetně čísla domu.');
        }
        return $result;
    }
}
