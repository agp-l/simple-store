<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use RuntimeException;

/** The SMTP DATA payload was submitted, but its acceptance could not be verified. */
final class MailDeliveryUncertainException extends RuntimeException
{
}
