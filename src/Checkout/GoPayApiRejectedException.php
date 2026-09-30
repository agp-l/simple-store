<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use RuntimeException;

/** A non-success HTTP response proves the create request was rejected by GoPay. */
final class GoPayApiRejectedException extends RuntimeException
{
}
