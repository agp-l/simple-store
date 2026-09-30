<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;

/** Require the order's referenced GoPay attempt to remain paid. */
final class GoPayPaidOrderGuard
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function assertPaid(int $orderId, string $reference): void
    {
        if ($orderId < 1 || preg_match('/^[0-9]{1,30}$/D', $reference) !== 1) {
            throw new InvalidArgumentException('Objednávka nemá ověřenou platbu GoPay. Zkontroluj její stav u brány.');
        }
        $attempt = $this->db->queryFirstRow(
            'SELECT status FROM shop_gopay_payments
             WHERE order_id=%i AND payment_id=%s LIMIT 1 FOR UPDATE', $orderId, $reference
        );
        if ($attempt === null || $attempt['status'] !== 'paid') {
            throw new InvalidArgumentException('Platba GoPay je vrácená nebo není potvrzená. Ověř transakci a vyřeš vrácení platby.');
        }
    }
}
