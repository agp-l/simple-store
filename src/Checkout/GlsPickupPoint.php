<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** GLS ShopDeliveryService selection from the official map iframe. */
final class GlsPickupPoint
{
    public const MAP_URL = 'https://maps.gls-czech.cz/?find=1&ctrcode=CZ&lng=cs';

    /** The ID is needed for later shipment creation; the address is a display snapshot. */
    public function selection(string $id, string $name, string $address, string $country): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{3,80}$/D', $id) !== 1 || strtoupper($country) !== 'CZ' ||
            !self::validText($name) || !self::validText($address)) {
            throw new InvalidArgumentException('Vyber české výdejní místo přímo v mapě GLS.');
        }

        return ['pickup_code' => $id, 'pickup_point' => trim($name),
            'pickup_address' => trim($address)];
    }

    private static function validText(string $value): bool
    {
        $value = trim($value);
        return $value !== '' && strlen($value) <= 190 && preg_match('//u', $value) === 1 &&
            preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }
}
