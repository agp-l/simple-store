<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

/** Published monthly amount for a known year; unknown years never inherit an old rate. */
final class FlatTaxRateSchedule
{
    private const MONTHLY = [
        // Financial Administration, as amended retroactively from 1 January 2026.
        2026 => [1 => 9162, 2 => 16745, 3 => 27139],
    ];

    public static function monthly(int $year, int $band): ?int
    {
        return self::MONTHLY[$year][$band] ?? null;
    }
}
