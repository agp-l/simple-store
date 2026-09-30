<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** Validates the order snapshot and the packed parcel before creating an API packet. */
final class PacketaShipmentDraft
{
    public static function fromOrder(array $order, array $input, string $sender): array
    {
        $shipping = $order['shipping'] ?? null;
        if (!is_array($shipping) || !in_array($order['payment_method'] ?? '', ['bank_transfer', 'comgate', 'gopay'], true) ||
            ($order['payment_status'] ?? '') !== 'paid' ||
            ($order['fulfillment_source'] ?? 'own') === 'external' ||
            in_array($order['status'] ?? '', ['shipped', 'cancelled', 'completed', 'test'], true)) {
            throw new InvalidArgumentException('Podat lze pouze zaplacenou aktivní objednávku.');
        }
        $method = $shipping['method'] ?? '';
        if (!in_array($method, ['zasilkovna_pickup', 'zasilkovna_home'], true) ||
            trim($sender) === '') {
            throw new InvalidArgumentException('Nastav označení odesílatele a ověř dopravu Zásilkovnou.');
        }
        $name = self::field($input, 'first_name', 70);
        $surname = self::field($input, 'surname', 70);
        $weightInput = $input['weight_kg'] ?? null;
        $weight = is_string($weightInput) ? str_replace(',', '.', trim($weightInput)) : '';
        $weightLimit = $method === 'zasilkovna_home' ? 30 : 15;
        if ($name === '' || $surname === '' || preg_match('/^(?:[1-9][0-9]?|0)(?:\.[0-9]{1,3})?$/D', $weight) !== 1 ||
            (float) $weight <= 0 || (float) $weight > $weightLimit) {
            throw new InvalidArgumentException('Vyplň jméno, příjmení a hmotnost balíku od 0,001 do ' . $weightLimit . ' kg.');
        }
        $email = self::field($input, 'email', 254);
        $phone = self::field($input, 'phone', 40);
        $phone = preg_replace('/[\s().-]+/', '', $phone) ?? '';
        $number = (string) ($order['order_number'] ?? '');
        $value = (int) ($order['subtotal_czk'] ?? 0);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            preg_match('/^\+?[0-9 ]{9,20}$/D', $phone) !== 1 ||
            preg_match('/^[A-Za-z0-9-]{1,40}$/D', $number) !== 1 ||
            $value < 1 || $value > 9999999) {
            throw new InvalidArgumentException('Zadej platný e-mail, telefon a zkontroluj hodnotu objednávky.');
        }
        $attributes = ['number' => $number, 'name' => $name, 'surname' => $surname,
            'email' => $email, 'phone' => $phone, 'addressId' => '',
            'cod' => '0', 'value' => (string) $value, 'currency' => 'CZK',
            'weight' => $weight, 'eshop' => $sender];
        if ($method === 'zasilkovna_pickup') {
            $code = (string) ($shipping['pickup_code'] ?? '');
            if (($shipping['pickup_verified'] ?? false) !== true ||
                preg_match('/^[0-9]{1,12}$/D', $code) !== 1) {
                throw new InvalidArgumentException('Tato objednávka nemá ověřené české výdejní místo. Podání ověř ručně.');
            }
            $attributes['addressId'] = $code;
        } else {
            if (strtoupper((string) ($shipping['country'] ?? 'CZ')) !== 'CZ') {
                throw new InvalidArgumentException('Zásilkovna domů HD zde podporuje pouze adresu v ČR.');
            }
            $street = self::field($input, 'street', 120);
            $house = self::field($input, 'house_number', 30);
            $city = self::field($input, 'city', 120);
            $zip = preg_replace('/\s+/', '', self::field($input, 'postal_code', 20));
            if ($street === '' || $house === '' || $city === '' ||
                preg_match('/^[0-9]{5}$/D', $zip) !== 1) {
                throw new InvalidArgumentException('Pro doručení domů vyplň zvlášť ulici a číslo domu; ověř město a PSČ v objednávce.');
            }
            $attributes['addressId'] = '106';
            $attributes['street'] = $street;
            $attributes['houseNumber'] = $house;
            $attributes['city'] = $city;
            $attributes['zip'] = $zip;
        }
        return ['method' => $method, 'attributes' => $attributes];
    }

    private static function field(array $input, string $key, int $limit): string
    {
        $value = $input[$key] ?? null;
        return self::savedField($value, $limit);
    }

    private static function savedField(mixed $value, int $limit): string
    {
        if (!is_string($value)) return '';
        $value = trim($value);
        return strlen($value) <= $limit && preg_match('//u', $value) === 1 &&
            !preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : '';
    }
}
