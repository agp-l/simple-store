<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use Closure;
use RuntimeException;

/** Small REST v2 adapter. The injected transport allows tests without merchant credentials. */
final class ComgateApiClient
{
    private const API = 'https://payments.comgate.cz/v2.0';
    private ?Closure $transport;

    public function __construct(private string $merchant, private string $secret, ?callable $transport = null)
    {
        if ($merchant === '' || $secret === '' || str_contains($merchant, ':')) {
            throw new RuntimeException('Přístup k platební bráně není nastaven.');
        }
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public function create(array $payment): array
    {
        return $this->request('POST', self::API . '/payment.json', $payment);
    }

    public function status(string $transId): array
    {
        if (preg_match('/^[A-Za-z0-9-]{3,100}$/D', $transId) !== 1) {
            throw new RuntimeException('Neplatné ID platby Comgate.');
        }
        return $this->request('GET', self::API . '/payment/transId/' . rawurlencode($transId) . '.json', null);
    }

    private function request(string $method, string $url, ?array $body): array
    {
        if ($this->transport !== null) {
            $result = ($this->transport)($method, $url, $body, $this->merchant, $this->secret);
            if (!is_array($result)) throw new RuntimeException('Neplatná odpověď Comgate.');
            return $result;
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Pro Comgate musí být na serveru zapnuto PHP cURL.');
        }
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Spojení s Comgate není dostupné.');
        $headers = ['Accept: application/json'];
        if ($body !== null) $headers[] = 'Content-Type: application/json; charset=UTF-8';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERPWD => $this->merchant . ':' . $this->secret,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        try {
            $response = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if (!is_string($response) || strlen($response) > 65536) {
                throw new RuntimeException('Odpověď Comgate nebyla doručena.');
            }
            $decoded = json_decode($response, true);
            if (!is_array($decoded) || !isset($decoded['code']) || $http < 200 || $http >= 500) {
                throw new RuntimeException('Comgate nevrátila platnou odpověď (HTTP ' . $http . ').');
            }
            return $decoded;
        } finally {
            curl_close($ch);
        }
    }
}
