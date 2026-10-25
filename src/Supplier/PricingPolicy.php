<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use InvalidArgumentException;

/** A minimum service reward followed by rounding UP; all arithmetic is in whole CZK. */
final class PricingPolicy
{
    public const DEFAULTS = ['pricing_enabled'=>0, 'pricing_small_limit'=>1000,
        'pricing_small_margin'=>50, 'pricing_margin'=>100, 'pricing_step'=>100];

    public static function settings(array $input): array
    {
        $out = ['pricing_enabled'=>(int)(($input['pricing_enabled'] ?? '') === '1')];
        foreach (['pricing_small_limit'=>1000000, 'pricing_small_margin'=>1000000,
            'pricing_margin'=>1000000, 'pricing_step'=>10000] as $key=>$max) {
            $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>$max]]);
            if ($value === false) throw new InvalidArgumentException('Cenotvorba vyžaduje kladné celé částky v povoleném rozsahu.');
            $out[$key] = $value;
        }
        return $out;
    }

    public static function price(int $cost, array $settings): int
    {
        $s = $settings + self::DEFAULTS;
        $margin = (int)$s[$cost < (int)$s['pricing_small_limit'] ? 'pricing_small_margin' : 'pricing_margin'];
        $step = (int)$s['pricing_step'];
        if ($cost < 1 || $margin < 1 || $step < 1) throw new InvalidArgumentException('Neplatný výpočet prodejní ceny.');
        $price = intdiv($cost + $margin + $step - 1, $step) * $step;
        if ($price > 10000000) throw new InvalidArgumentException('Vypočtená prodejní cena je nad limitem e-shopu.');
        return $price;
    }
}
