<?php
declare(strict_types=1);

namespace SimpleStore\Rendering;

/** Database timestamps stay in UTC; Czech admin screens use local civil time. */
final class LocalDateTime
{
    public static function czech(?string $utc): string
    {
        if ($utc === null || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $utc) !== 1) {
            return '—';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $utc) {
            return '—';
        }
        return $date->setTimezone(new \DateTimeZone('Europe/Prague'))->format('j. n. Y H:i');
    }
}
