<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use InvalidArgumentException;

/** Parse and calculate ledger amounts in integer haléře, never in binary floats. */
final class TaxMoney
{
    public static function cents(mixed $value, bool $allowZero = false): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException('Zadej částku v Kč, nejvýše se dvěma desetinnými místy.');
        }
        $value = str_replace(["\u{00a0}", ' ', ','], ['', '', '.'], trim((string) $value));
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/D', $value, $parts)) {
            throw new InvalidArgumentException('Zadej částku v Kč, nejvýše se dvěma desetinnými místy.');
        }
        $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if (!$allowZero && $cents === 0) throw new InvalidArgumentException('Částka musí být větší než nula.');
        return $cents;
    }

    public static function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(mixed $value): string
    {
        // Formatting only; all calculations use cents().
        return number_format((float) $value, 2, ',', ' ') . ' Kč';
    }

    public static function number(string $decimal): int|float
    {
        return str_ends_with($decimal, '.00') ? (int) $decimal : (float) $decimal;
    }
}
