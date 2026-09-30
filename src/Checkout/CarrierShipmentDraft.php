<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** A local shipment snapshot, before the carrier has accepted a parcel. */
final class CarrierShipmentDraft
{
    public static function fromOrder(array $order, array $input): array
    {
        $shipping = $order['shipping'] ?? null;
        $method = is_array($shipping) ? ($shipping['method'] ?? '') : '';
        if (!in_array($method, ['balikovna_pickup', 'gls_pickup', 'gls_home'], true) ||
            ($order['payment_method'] ?? '') !== 'bank_transfer' ||
            ($order['payment_status'] ?? '') !== 'paid' ||
            ($order['fulfillment_source'] ?? 'own') !== 'own' ||
            in_array($order['status'] ?? '', ['shipped', 'completed', 'cancelled', 'test'], true)) {
            throw new InvalidArgumentException('Připravit lze pouze zaplacenou aktivní objednávku expedovanou obchodem přes Balíkovnu nebo GLS.');
        }

        $name = self::field($input, 'recipient', 140);
        $email = self::field($input, 'email', 254);
        $phoneValue = $input['phone'] ?? null;
        $phone = is_string($phoneValue) && strlen($phoneValue) <= 40
            ? (preg_replace('/[\s().-]+/', '', trim($phoneValue)) ?? '') : '';
        $phone = ltrim($phone, '+'); // Numeric CSV contact cannot become a spreadsheet formula.
        $weight = str_replace(',', '.', self::field($input, 'weight_kg', 10));
        $maxWeight = $method === 'balikovna_pickup' ? 15 : ($method === 'gls_pickup' ? 10 : 40);
        if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            preg_match('/^(?:420)?[0-9]{9}$/D', $phone) !== 1 ||
            preg_match('/^(?:[0-9]{1,2})(?:\.[0-9]{1,3})?$/D', $weight) !== 1 ||
            (float) $weight <= 0 || (float) $weight > $maxWeight) {
            throw new InvalidArgumentException('Vyplň příjemce, platný e-mail a telefon a hmotnost balíku do ' . $maxWeight . ' kg.');
        }
        $number = (string) ($order['order_number'] ?? '');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,39}$/D', $number) !== 1) {
            throw new InvalidArgumentException('Číslo objednávky není platné pro export zásilky.');
        }

        $draft = ['method' => $method, 'order_number' => $number,
            'recipient' => $name, 'email' => $email, 'phone' => $phone,
            'weight_kg' => $weight, 'country' => 'CZ',
            'pickup_code' => '', 'pickup_point' => '', 'pickup_address' => '',
            'street' => '', 'city' => '', 'postal_code' => ''];
        if ($method === 'balikovna_pickup') {
            $code = (string) ($shipping['pickup_code'] ?? '');
            $zip = (string) ($shipping['pickup_postal_code'] ?? '');
            if (preg_match('/^(?:B[0-9]{5,12}|[0-9]{1,12})$/D', $code) !== 1 ||
                preg_match('/^[0-9]{5}$/D', $zip) !== 1) {
                throw new InvalidArgumentException('Balíkovna v objednávce nemá platné ID a PSČ místa.');
            }
            $draft['pickup_code'] = $code;
            $draft['pickup_point'] = self::field($shipping, 'pickup_point', 190);
            $draft['pickup_address'] = self::field($shipping, 'pickup_address', 190);
            $draft['postal_code'] = $zip; // Physical postcode for review only; import destination uses pickup_code.
            $draft['city'] = self::field($input, 'city', 120);
            if ($draft['pickup_point'] === '' || $draft['pickup_address'] === '' || $draft['city'] === '') {
                throw new InvalidArgumentException('Doplň obec Balíkovny podle vybraného místa.');
            }
        } else {
            if ($method === 'gls_pickup') {
                $code = (string) ($shipping['pickup_code'] ?? '');
                if (preg_match('/^[A-Za-z0-9_-]{3,80}$/D', $code) !== 1) {
                    throw new InvalidArgumentException('V objednávce chybí ID místa GLS.');
                }
                $draft['pickup_code'] = $code;
                $draft['pickup_point'] = self::field($shipping, 'pickup_point', 190);
                $draft['pickup_address'] = self::field($shipping, 'pickup_address', 190);
                if ($draft['pickup_point'] === '' || $draft['pickup_address'] === '') {
                    throw new InvalidArgumentException('V objednávce chybí adresa místa GLS.');
                }
            }
            $draft['street'] = self::field($input, 'street', 120);
            $draft['city'] = self::field($input, 'city', 120);
            $draft['postal_code'] = preg_replace('/\s+/', '', self::field($input, 'postal_code', 12)) ?? '';
            if ($draft['street'] === '' || $draft['city'] === '' ||
                preg_match('/^[0-9]{5}$/D', $draft['postal_code']) !== 1 ||
                ($method === 'gls_home' && strtoupper((string) ($shipping['country'] ?? 'CZ')) !== 'CZ')) {
                throw new InvalidArgumentException('Pro GLS ověř ulici, obec a české PSČ místa doručení.');
            }
        }
        return $draft;
    }

    /** The GLS map stores "street, city, ZIP"; legacy orders may need manual correction. */
    public static function addressDefaults(array $shipping): array
    {
        if (($shipping['method'] ?? '') === 'gls_pickup') {
            $parts = array_map('trim', explode(',', (string) ($shipping['pickup_address'] ?? '')));
            if (count($parts) >= 3) {
                return ['street' => implode(', ', array_slice($parts, 0, -2)),
                    'city' => $parts[count($parts) - 2], 'postal_code' => $parts[count($parts) - 1]];
            }
            return ['street' => '', 'city' => '', 'postal_code' => ''];
        }
        return ['street' => (string) ($shipping['street'] ?? ''),
            'city' => (string) ($shipping['city'] ?? ''),
            'postal_code' => (string) ($shipping['postal_code'] ?? '')];
    }

    private static function field(array $input, string $key, int $limit): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) return '';
        $value = trim($value);
        if (strlen($value) > $limit || preg_match('//u', $value) !== 1 ||
            preg_match('/[\x00-\x1f\x7f]/', $value) === 1 ||
            preg_match('/^[=+@-]/', $value) === 1) return '';
        return $value;
    }
}
