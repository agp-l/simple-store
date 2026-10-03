<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

/** Shares the configured delivery method between queued mail and account recovery. */
final class MailTransport
{
    public function __construct(private array $settings)
    {
    }

    public function send(string $to, string $subject, string $body, string $headers): bool
    {
        if (($this->settings['smtp_host'] ?? '') !== '') {
            return (new SmtpTransport($this->settings))->send($to, $subject, $body, $headers);
        }
        return @mail($to, $subject, $body, $headers);
    }
}
