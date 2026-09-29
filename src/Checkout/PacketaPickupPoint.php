<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** Packeta's public widget key selects Czech internal branches; its API validates the order data. */
final class PacketaPickupPoint
{
    public const SCRIPT_URL = 'https://widget.packeta.com/v6/www/js/library.js';
    private const VALIDATE_URL = 'https://widget.packeta.com/v6/pps/api/widget/v1/validate';

    /** @param null|callable(string, string): array{status:int, body:string} $transport */
    public function __construct(private string $apiKey = '', private $transport = null)
    {
        if ($apiKey !== '' && preg_match('/^[a-zA-Z0-9]{16}$/D', $apiKey) !== 1) {
            throw new InvalidArgumentException('Klíč widgetu Zásilkovny musí mít 16 písmen nebo číslic.');
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

    /** These same country/vendor restrictions are sent to the widget and validation endpoint. */
    public static function options(): array
    {
        return ['country' => 'cz', 'language' => 'cs',
            'vendors' => [['country' => 'cz'], ['country' => 'cz', 'group' => 'zbox']]];
    }

    /** Use the authoritative address returned by Packeta, never a browser-supplied label. */
    public function verify(string $id): array
    {
        if (!$this->isConfigured()) {
            throw new InvalidArgumentException('Zásilkovna není nastavená. Vyber jinou dopravu.');
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Vyber výdejní místo přímo v mapě Zásilkovny.');
        }
        $request = json_encode(['apiKey' => $this->apiKey, 'point' => ['id' => $id],
            'options' => ['country' => 'cz', 'vendors' => self::options()['vendors']]], JSON_THROW_ON_ERROR);
        $response = $this->transport !== null
            ? ($this->transport)(self::VALIDATE_URL, $request)
            : self::post($request);
        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null) || strlen($response['body']) > 65536) {
            throw new InvalidArgumentException('Výdejní místo nelze právě ověřit. Zkus to znovu.');
        }
        if ($response['status'] === 401) {
            throw new InvalidArgumentException('Klíč widgetu Zásilkovny neplatí. Kontaktuj obchod.');
        }
        if ($response['status'] !== 200) {
            throw new InvalidArgumentException('Výdejní místo nelze právě ověřit. Zkus to znovu.');
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || ($data['isValid'] ?? null) !== true ||
            !is_array($data['point'] ?? null)) {
            throw new InvalidArgumentException('Výdejní místo není dostupné. Vyber jiné v mapě.');
        }
        $point = $data['point'];
        $address = $point['address'] ?? null;
        if (!is_array($address) || strtolower((string) ($address['country'] ?? '')) !== 'cz' ||
            isset($point['carrierId'])) {
            throw new InvalidArgumentException('Vyber české výdejní místo Zásilkovny.');
        }
        $name = self::label($point['name'] ?? null, 190);
        $street = self::label($address['street'] ?? null, 150);
        $city = self::label($address['city'] ?? null, 120);
        $zip = self::label($address['zip'] ?? null, 20);
        $formatted = trim($street . ', ' . $city . ', ' . $zip);
        if ($name === '' || $street === '' || $city === '' || $zip === '' || strlen($formatted) > 190) {
            throw new InvalidArgumentException('Adresa výdejního místa není úplná. Vyber jiné místo.');
        }
        return ['pickup_code' => $id, 'pickup_point' => $name, 'pickup_address' => $formatted];
    }

    private static function label(mixed $value, int $limit): string
    {
        if (!is_string($value)) return '';
        $value = trim($value);
        return strlen($value) <= $limit && preg_match('//u', $value) === 1 &&
            !preg_match('/[\x00-\x1f\x7f]/', $value) ? $value : '';
    }

    /** Fixed HTTPS endpoint; works with cURL or allow_url_fopen on ordinary PHP hosting. */
    private static function post(string $body): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'X-Language: cs'];
        if (function_exists('curl_init')) {
            $curl = curl_init(self::VALIDATE_URL);
            if ($curl === false) return ['status' => 0, 'body' => ''];
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false]);
            $result = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            return ['status' => $status, 'body' => is_string($result) ? $result : ''];
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => 6, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $result = @file_get_contents(self::VALIDATE_URL, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('~^HTTP/\S+ ([0-9]{3})~', $header, $match)) $status = (int) $match[1];
        }
        return ['status' => $status, 'body' => is_string($result) ? $result : ''];
    }
}
