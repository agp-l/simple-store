<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

/** Bind the displayed offer to a checkout attempt, for own, assisted and mixed carts. */
final class CheckoutReview
{
    public static function token(array $summary, array $delivery, ?int $shippingCzk, string $payment,
        string $legalVersion, string $secret, string $shippingLabel = ''): string
    {
        return hash_hmac('sha256', json_encode(['checkout-review-v1', $summary['items'], $delivery,
            $shippingCzk, $shippingLabel, $payment, $legalVersion], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $secret);
    }
}
