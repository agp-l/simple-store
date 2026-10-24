<?php
declare(strict_types=1);

/** Decode the actual text alternative delivered to the transport in tests. */
function decodedMailText(string $mime): string
{
    if (preg_match('~Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--~s', $mime, $match) !== 1) {
        throw new RuntimeException('Missing plain-text alternative.');
    }
    $text = base64_decode(preg_replace('/\s+/', '', $match[1]), true);
    if ($text === false) throw new RuntimeException('Invalid plain-text MIME encoding.');
    return $text;
}
