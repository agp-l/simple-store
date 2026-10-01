<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;

/** Check the exact BTCPay invoice linked to an order before dispatch. */
final class BTCPayPaidOrderGuard
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function assertPaid(int $orderId, string $reference): void
    {
        if ($orderId < 1 || $reference === '' || strlen($reference) > 190 ||
            preg_match('/[\x00-\x1f\x7f]/', $reference)) {
            throw new InvalidArgumentException('Objednávka nemá ověřenou fakturu BTCPay. Obnov stav platby.');
        }
        $invoice = $this->db->queryFirstRow(
            'SELECT status FROM shop_btcpay_payments
             WHERE order_id=%i AND invoice_id=%s LIMIT 1 FOR UPDATE', $orderId, $reference
        );
        if ($invoice === null || $invoice['status'] !== 'settled') {
            throw new InvalidArgumentException('Platba BTCPay není potvrzená jako uhrazená. Ověř fakturu u brány.');
        }
    }
}
