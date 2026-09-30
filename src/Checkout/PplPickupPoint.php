<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** PPL Widget 2.0 selection snapshot. Its browser response has no documented server validation endpoint. */
final class PplPickupPoint
{
    public const SCRIPT_URL = 'https://www.ppl.cz/accesspointwidget/loader.js';

    public function __construct(private string $apiKey = '')
    {
        if ($apiKey !== '' && preg_match('/^[\x21-\x7e]{8,512}$/D', $apiKey) !== 1) {
            throw new InvalidArgumentException('Klíč widgetu PPL musí mít 8 až 512 znaků bez mezer.');
        }
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /** Validate the selected KM code and complete display address before storing the snapshot. */
    public function selection(string $code, string $name, string $address, string $country): array
    {
        if (!$this->isConfigured()) {
            throw new InvalidArgumentException('Mapa PPL není nastavená. Vyber jiné doručení nebo požádej obchod o nastavení.');
        }
        if (preg_match('/^KM[0-9]{7}$/D', $code) !== 1 || strtoupper($country) !== 'CZ' ||
            !self::validText($name, 190) || !self::validText($address, 190)) {
            throw new InvalidArgumentException('Vyber české výdejní místo přímo v mapě PPL.');
        }
        return ['pickup_code' => $code, 'pickup_point' => trim($name),
            'pickup_address' => trim($address)];
    }

    private static function validText(string $value, int $limit): bool
    {
        $value = trim($value);
        return $value !== '' && strlen($value) <= $limit && preg_match('//u', $value) === 1 &&
            preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }
}
