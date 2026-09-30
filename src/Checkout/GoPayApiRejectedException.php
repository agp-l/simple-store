<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use RuntimeException;

/** A definite validation or authorization rejection creates no remote payment. */
final class GoPayApiRejectedException extends RuntimeException
{
}
