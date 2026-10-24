<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

/** Old unpaid orders must not keep the bank polling forever. Timestamps are UTC. */
final class FioPollingPolicy
{
    public static function interval(array $order, \DateTimeImmutable $now): ?int
    {
        $utc = new \DateTimeZone('UTC');
        $due = !empty($order['payment_due_at'])
            ? new \DateTimeImmutable($order['payment_due_at'], $utc)
            : (new \DateTimeImmutable($order['created_at'], $utc))->modify('+7 days');
        if ($now <= $due) return 600;
        return $now <= $due->modify('+7 days') ? 3600 : null;
    }
}
