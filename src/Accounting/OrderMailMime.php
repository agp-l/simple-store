<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

/** Keep the readable HTML/text message separate from an immutable terms attachment. */
final class OrderMailMime
{
    /** @return array{headers: string, body: string} */
    public static function compose(string $text, string $html, string $terms = ''): array
    {
        $alternative = 'simple-store-' . bin2hex(random_bytes(12));
        $body = "--{$alternative}\r\nContent-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: base64\r\n\r\n" .
            chunk_split(base64_encode($text), 76, "\r\n") .
            "\r\n--{$alternative}\r\nContent-Type: text/html; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: base64\r\n\r\n" .
            chunk_split(base64_encode($html), 76, "\r\n") .
            "\r\n--{$alternative}--\r\n";
        if ($terms === '') {
            return ['headers' => 'MIME-Version: 1.0' . "\r\n" .
                'Content-Type: multipart/alternative; boundary="' . $alternative . '"',
                'body' => $body];
        }
        $mixed = 'simple-store-' . bin2hex(random_bytes(12));
        return ['headers' => 'MIME-Version: 1.0' . "\r\n" .
            'Content-Type: multipart/mixed; boundary="' . $mixed . '"',
            'body' => "--{$mixed}\r\nContent-Type: multipart/alternative; boundary=\"{$alternative}\"\r\n\r\n" .
                $body . "\r\n--{$mixed}\r\n" .
                'Content-Type: text/plain; charset=UTF-8; name="obchodni-podminky.txt"' . "\r\n" .
                'Content-Disposition: attachment; filename="obchodni-podminky.txt"' . "\r\n" .
                "Content-Transfer-Encoding: base64\r\n\r\n" .
                chunk_split(base64_encode($terms), 76, "\r\n") .
                "\r\n--{$mixed}--\r\n"];
    }
}
