<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use RuntimeException;

/** A definite validation/authentication rejection; no remote invoice was created. */
final class BTCPayApiRejectedException extends RuntimeException
{
}
