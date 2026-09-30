<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** Selected Balíkovna from the official Česká pošta map. */
final class BalikovnaPickupPoint
{
    public const MAP_URL = 'https://b2c.cpost.cz/locations/?type=BALIKOVNY&skipLocation=true';

    /** Keep the ZIP separately: a future shipping label must not derive it from the display address. */
    public function selection(string $id, string $name, string $address, string $zip, string $type): array
    {
        if (preg_match('/^[0-9]{1,12}$/D', $id) !== 1 || preg_match('/^[0-9]{5}$/D', $zip) !== 1 ||
            $type !== 'BALIKOVNY' || !self::validText($name) || !self::validText($address)) {
            throw new InvalidArgumentException('Vyber výdejní místo nebo box přímo v mapě Balíkovny.');
        }

        return ['pickup_code' => $id, 'pickup_point' => trim($name),
            'pickup_address' => trim($address), 'pickup_postal_code' => $zip];
    }

    private static function validText(string $value): bool
    {
        $value = trim($value);
        return $value !== '' && strlen($value) <= 190 && preg_match('//u', $value) === 1 &&
            preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }
}
