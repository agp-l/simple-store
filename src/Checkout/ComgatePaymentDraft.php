<?php
declare(strict_types=1);
namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** Fields required by Comgate REST v2; values come from the stored order snapshot. */
final class ComgatePaymentDraft
{
    public static function fromOrder(array $order, bool $test): array
    {
        $amount = filter_var($order['total_czk'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $shipping = $order['shipping'] ?? [];
        $name = trim((string) ($shipping['recipient'] ?? $shipping['name'] ?? ''));
        if ($amount === false || $name === '' || !filter_var($order['customer_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Comgate vyžaduje jméno, e-mail a částku od 1 do 1 000 000 Kč.');
        }
        $payload = ['test' => $test, 'country' => 'CZ', 'price' => $amount * 100, 'curr' => 'CZK',
            'label' => substr('Objednavka ' . $order['id'], 0, 16),
            'refId' => (string) $order['order_number'], 'method' => 'ALL',
            'email' => $order['customer_email'], 'fullName' => $name,
            'delivery' => ShippingPolicy::isPickup((string) ($shipping['method'] ?? '')) ? 'PICKUP' : 'HOME_DELIVERY',
            'category' => AssistedPurchase::has($order['items'] ?? []) ? 'OTHER' : 'PHYSICAL_GOODS_ONLY', 'lang' => 'cs'];
        $phone = preg_replace('/[\s()-]+/', '', (string) ($shipping['phone'] ?? ''));
        if (is_string($phone) && preg_match('/^\+[1-9][0-9]{6,14}$/D', $phone)) $payload['phone'] = $phone;
        if ($payload['delivery'] === 'HOME_DELIVERY') {
            $payload += ['homeDeliveryCity' => (string) ($shipping['city'] ?? ''),
                'homeDeliveryStreet' => (string) ($shipping['street'] ?? ''),
                'homeDeliveryPostalCode' => (string) ($shipping['postal_code'] ?? ''), 'homeDeliveryCountry' => 'CZ'];
        }
        return $payload;
    }
}
