<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use RuntimeException;

/** SMTP AUTH over verified TLS; message content is already MIME encoded by the caller. */
final class SmtpTransport
{
    public function __construct(private array $config)
    {
    }

    public function send(string $to, string $subject, string $body, string $headers): bool
    {
        $host = (string) ($this->config['smtp_host'] ?? '');
        $from = (string) ($this->config['from_email'] ?? '');
        if ($host === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false ||
            filter_var($from, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $to . $from)) {
            throw new RuntimeException('Neplatná adresa pro odeslání SMTP.');
        }
        $password = MailCredential::decrypt((string) $this->config['smtp_password_encrypted']);
        $security = (string) $this->config['smtp_security'];
        $address = ($security === 'tls' ? 'ssl://' : 'tcp://') . $host . ':' . (int) $this->config['smtp_port'];
        $socket = @stream_socket_client($address, $errno, $error, 10, STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]));
        if ($socket === false) throw new RuntimeException('Nelze se spojit se SMTP serverem (' . $errno . ').');
        try {
            stream_set_timeout($socket, 10);
            self::response($socket, [220]);
            self::command($socket, 'EHLO dobrodruzi.cz', [250]);
            if ($security === 'starttls') {
                self::command($socket, 'STARTTLS', [220]);
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new RuntimeException('Zabezpečené spojení STARTTLS selhalo.');
                }
                self::command($socket, 'EHLO dobrodruzi.cz', [250]);
            }
            self::command($socket, 'AUTH LOGIN', [334]);
            self::command($socket, base64_encode((string) $this->config['smtp_username']), [334]);
            self::command($socket, base64_encode($password), [235]);
            self::command($socket, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::command($socket, 'DATA', [354]);
            $message = "To: <{$to}>\r\nSubject: {$subject}\r\n{$headers}\r\n\r\n{$body}";
            $message = preg_replace('/\r?\n/', "\r\n", $message);
            $message = preg_replace('/^\./m', '..', $message);
            self::write($socket, $message . (str_ends_with($message, "\r\n") ? '' : "\r\n") . ".\r\n");
            self::response($socket, [250]);
            self::command($socket, 'QUIT', [221]);
            return true;
        } finally {
            fclose($socket);
        }
    }

    private static function write($socket, string $text): void
    {
        $length = strlen($text);
        for ($offset = 0; $offset < $length; $offset += $written) {
            $written = @fwrite($socket, substr($text, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Zápis na SMTP server selhal.');
        }
    }

    private static function command($socket, string $command, array $expected): void
    {
        self::write($socket, $command . "\r\n");
        self::response($socket, $expected);
    }

    private static function response($socket, array $expected): void
    {
        for ($line = 0; $line < 100; $line++) {
            $answer = @fgets($socket, 2048);
            if ($answer === false || !preg_match('/^([0-9]{3})([ -])/', $answer, $match)) {
                throw new RuntimeException('SMTP server neodpovídá.');
            }
            if ($match[2] === '-') continue;
            if (!in_array((int) $match[1], $expected, true)) {
                throw new RuntimeException('SMTP server odmítl zprávu (kód ' . $match[1] . ').');
            }
            return;
        }
        throw new RuntimeException('SMTP server poslal příliš dlouhou odpověď.');
    }
}
