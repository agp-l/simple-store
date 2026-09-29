<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use RuntimeException;

/** The API explicitly rejected the request; no packet was created. */
final class PacketaRejectedException extends RuntimeException
{
}
