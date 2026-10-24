<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

/** Compare the displayed fulfillment state with the locked row before changing it. */
final class OrderFulfillmentVersion
{
    public static function forOrder(array $order): string
    {
        return hash('sha256', json_encode([
            (int) ($order['id'] ?? 0),
            (string) ($order['status'] ?? ''),
            (string) ($order['payment_status'] ?? ''),
            (string) ($order['fulfillment_source'] ?? 'own'),
            (string) ($order['fulfillment_note'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }
}
