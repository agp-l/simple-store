<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** One domestic delivery estimate for all in-stock products, independent of stock and pricing. */
final class DeliveryEstimate
{
    public const FORMATS = [
        'relative' => 'Za tři až čtyři dny u vás / Zítra u vás / Pozítří u vás',
        'within' => 'U vás do tří dnů',
        'date' => 'U vás 13. 7. / U vás 13. 7. – 14. 7.',
    ];

    public static function defaults(): array
    {
        return ['enabled' => false, 'format' => 'relative', 'min_days' => 3,
            'max_days' => 4, 'day_type' => 'business', 'cutoff' => '12:00'];
    }

    /** Broken or legacy configuration must not make the storefront unavailable. */
    public static function configuration(mixed $value): array
    {
        if (!is_array($value)) return self::defaults();
        try { return self::validate(array_replace(self::defaults(), $value)); }
        catch (InvalidArgumentException $error) { return self::defaults(); }
    }

    public static function validate(mixed $input): array
    {
        if (!is_array($input)) throw new InvalidArgumentException('Zkontroluj nastavení odhadu doručení.');
        $result = self::defaults();
        $enabled = $input['enabled'] ?? false;
        if (!in_array($enabled, [true, false, '1', '0'], true)) {
            throw new InvalidArgumentException('Zkontroluj zapnutí odhadu doručení.');
        }
        $result['enabled'] = $enabled === true || $enabled === '1';
        foreach (['min_days', 'max_days'] as $field) {
            $raw = $input[$field] ?? null;
            if ((!is_int($raw) && !is_string($raw)) || !preg_match('/^[1-9][0-9]?$/D', (string) $raw) || (int) $raw > 30) {
                throw new InvalidArgumentException('Odhad doručení musí být celé číslo od 1 do 30 dnů.');
            }
            $result[$field] = (int) $raw;
        }
        if ($result['min_days'] > $result['max_days']) {
            throw new InvalidArgumentException('Nejpozdější doručení nesmí být dříve než nejbližší doručení.');
        }
        foreach (['format' => array_keys(self::FORMATS), 'day_type' => ['business', 'calendar']] as $field => $allowed) {
            if (!is_string($input[$field] ?? null) || !in_array($input[$field], $allowed, true)) {
                throw new InvalidArgumentException('Vyber platný způsob zobrazení a počítání dnů doručení.');
            }
            $result[$field] = $input[$field];
        }
        $cutoff = $input['cutoff'] ?? '';
        if (!is_string($cutoff) || ($cutoff !== '' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $cutoff))) {
            throw new InvalidArgumentException('Uzávěrku objednávek zadej ve tvaru HH:MM, nebo ji nech prázdnou.');
        }
        $result['cutoff'] = $cutoff;
        return $result;
    }

    /** Calculate once per response; reuse the same result on every card and selected variant. */
    public static function present(mixed $configuration, ?DateTimeImmutable $now = null): ?array
    {
        $settings = self::configuration($configuration);
        if (!$settings['enabled']) return null;
        $zone = new DateTimeZone('Europe/Prague');
        $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
        $today = $now->setTime(0, 0);
        $base = $today;
        $business = $settings['day_type'] === 'business';
        if ($settings['cutoff'] !== '' && $now->format('H:i') >= $settings['cutoff']) {
            $base = $base->modify('+1 day');
        }
        if ($business) {
            while (!self::isBusinessDay($base)) $base = $base->modify('+1 day');
        }
        $from = self::addDays($base, $settings['min_days'], $business);
        $to = self::addDays($base, $settings['max_days'], $business);
        // Relative wording includes any delay caused by the cutoff or a non-working order date.
        $min = self::distance($today, $from, $business);
        $max = self::distance($today, $to, $business);
        $calendarDays = (int) $today->diff($from)->format('%a');
        if ($settings['format'] === 'date') {
            $withYear = $today->format('Y') !== $to->format('Y');
            $pattern = $withYear ? 'j. n. Y' : 'j. n.';
            $text = 'U vás ' . $from->format($pattern) . ($from == $to ? '' : ' – ' . $to->format($pattern));
        } elseif ($settings['format'] === 'within') {
            $text = 'U vás do ' . self::number($max, true) . ($business ? ' pracovních' : '') . ($max === 1 ? ' dne' : ' dnů');
            if ($business && $max === 1) $text = 'U vás do jednoho pracovního dne';
        } elseif ($from == $to && $calendarDays <= 2) {
            $text = ($calendarDays === 1 ? 'Zítra' : 'Pozítří') . ' u vás';
        } else {
            $days = $max === 1 ? 'den' : ($max <= 4 ? 'dny' : 'dnů');
            $adjective = $business ? ($max <= 4 ? ' pracovní ' : ' pracovních ') : ' ';
            $text = 'Za ' . self::number($min) . ($min === $max ? '' : ' až ' . self::number($max)) . $adjective . $days . ' u vás';
        }
        return ['text' => $text, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
            'note' => 'Odhad doručení po ČR',
            'title' => 'Orientační doručení při objednání a zaplacení nyní. Při pozdější platbě se termín posune.'];
    }

    private static function number(int $days, bool $genitive = false): string
    {
        $words = $genitive ? [1 => 'jednoho', 'dvou', 'tří', 'čtyř', 'pěti', 'šesti', 'sedmi']
            : [1 => 'jeden', 'dva', 'tři', 'čtyři', 'pět', 'šest', 'sedm'];
        return $words[$days] ?? (string) $days;
    }

    private static function addDays(DateTimeImmutable $date, int $days, bool $business): DateTimeImmutable
    {
        while ($days > 0) {
            $date = $date->modify('+1 day');
            if (!$business || self::isBusinessDay($date)) $days--;
        }
        return $date;
    }

    private static function distance(DateTimeImmutable $from, DateTimeImmutable $to, bool $business): int
    {
        $days = 0;
        while ($from < $to) {
            $from = $from->modify('+1 day');
            if (!$business || self::isBusinessDay($from)) $days++;
        }
        return $days;
    }

    private static function isBusinessDay(DateTimeImmutable $date): bool
    {
        if ((int) $date->format('N') > 5 || in_array($date->format('m-d'), [
            '01-01', '05-01', '05-08', '07-05', '07-06', '09-28', '10-28', '11-17', '12-24', '12-25', '12-26',
        ], true)) return false;
        // Gregorian computus; no dependency on PHP's optional calendar extension or server timezone.
        $year = (int) $date->format('Y');
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
        $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $easter = $date->setDate($year, intdiv($h + $l - 7 * $m + 114, 31), ($h + $l - 7 * $m + 114) % 31 + 1);
        return $date->format('Y-m-d') !== $easter->modify('-2 days')->format('Y-m-d')
            && $date->format('Y-m-d') !== $easter->modify('+1 day')->format('Y-m-d');
    }
}
