<?php
declare(strict_types=1);

namespace SimpleStore\Pricing;

use MeekroDB;

/** Formats indicative BTC alongside the authoritative CZK price. */
final class BitcoinPriceDisplay
{
    public function __construct(private ?array $quote)
    {
    }

    public static function fromSettings(MeekroDB $db, array $settings): self
    {
        return new self(($settings['btc_prices_enabled'] ?? true) === true
            ? (new BitcoinRateRepository($db))->current() : null);
    }

    public function bitcoin(int $czk): ?string
    {
        if ($this->quote === null || $czk < 0) return null;
        $satoshis = (int) round($czk * 100000000 / $this->quote['rate']);
        return '≈ ' . number_format($satoshis / 100000000, 8, '.', ' ') . ' BTC';
    }

    public function format(int $czk): string
    {
        $czech = number_format($czk, 0, ',', ' ') . ' Kč';
        $bitcoin = $this->bitcoin($czk);
        return $bitcoin === null ? $czech : $czech . ' (' . $bitcoin . ')';
    }

    public function updatedAt(): ?string
    {
        return $this->quote['updated_at'] ?? null;
    }
}
