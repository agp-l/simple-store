<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

/** A development checkout is available only to the machine running the web server. */
final class LocalCheckoutPreview
{
    public static function available(array $server, bool $debug, bool $enabled = true): bool
    {
        if (!$debug || !$enabled ||
            !in_array($server['REMOTE_ADDR'] ?? null, ['127.0.0.1', '::1'], true)) {
            return false;
        }
        $host = $server['HTTP_HOST'] ?? null;
        return is_string($host) &&
            preg_match('/^(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]{1,5})?$/iD', $host) === 1;
    }
}
