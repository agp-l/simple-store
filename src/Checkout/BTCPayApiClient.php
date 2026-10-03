<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use Closure;
use RuntimeException;

/** Narrow Greenfield adapter; credentials and invoice lookups remain on the server. */
final class BTCPayApiClient
{
    private Closure|null $transport;
    private string $baseUrl;

    public function __construct(string $serverUrl, private string $storeId, private string $apiKey,
        ?callable $transport = null)
    {
        $this->baseUrl = rtrim(trim($serverUrl), '/');
        $parts = parse_url($this->baseUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) ||
            !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment']) ||
            (($parts['scheme'] ?? '') === 'http' &&
                !in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1'], true)) ||
            preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $storeId) !== 1 ||
            $apiKey === '' || strlen($apiKey) > 512 || preg_match('/[\x00-\x20\x7f]/', $apiKey)) {
            throw new RuntimeException('Přístup k BTCPay Serveru není platně nastaven.');
        }
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public function create(array $invoice): array
    {
        return $this->request('POST', '/api/v1/stores/' . rawurlencode($this->storeId) . '/invoices', $invoice);
    }

    public function status(string $invoiceId): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $invoiceId) !== 1) {
            throw new RuntimeException('Neplatné ID faktury BTCPay.');
        }
        return $this->request('GET', '/api/v1/invoices/' . rawurlencode($invoiceId));
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->baseUrl . $path;
        $headers = ['Authorization: token ' . $this->apiKey, 'Accept: application/json',
            'Content-Type: application/json'];
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $url, $headers, $body);
        } else {
            if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL není dostupné.');
            $curl = curl_init($url);
            if ($curl === false) throw new RuntimeException('BTCPay Server není dostupný.');
            $reply = '';
            $options = [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$reply): int {
                    if (strlen($reply) + strlen($chunk) > 1048576) return 0;
                    $reply .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
            curl_setopt_array($curl, $options);
            $ok = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($ok === false) throw new RuntimeException('Spojení s BTCPay Serverem selhalo.');
            $response = ['status' => $code, 'body' => $reply];
        }
        if (!is_array($response) || !is_int($response['status'] ?? null) ||
            !is_string($response['body'] ?? null)) {
            throw new RuntimeException('BTCPay Server nevrátil platnou odpověď.');
        }
        $code = $response['status'];
        if ($code < 200 || $code >= 300) {
            $message = 'BTCPay Server odmítl požadavek (HTTP ' . $code . ').';
            if ($method === 'POST' && in_array($code, [400, 401, 403, 404, 422], true)) {
                throw new BTCPayApiRejectedException($message);
            }
            throw new RuntimeException($message);
        }
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded) || array_values($decoded) === $decoded) {
            throw new RuntimeException('BTCPay Server nevrátil platnou fakturu.');
        }
        return $decoded;
    }
}
