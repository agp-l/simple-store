<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use RuntimeException;

/** Read-only transport for Fio's period export; never expose token-bearing URLs in errors. */
final class FioStatementClient
{
    private const MAX_BODY_BYTES = 16000000;
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const REQUEST_TIMEOUT_SECONDS = 40;

    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function fetch(string $token, string $from, string $to): array
    {
        if (!self::validToken($token) ||
            preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D', $from) !== 1 ||
            preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D', $to) !== 1) {
            throw new RuntimeException('Neplatné nastavení tokenu nebo období Fio.');
        }
        $url = 'https://fioapi.fio.cz/v1/rest/periods/' . $token . '/' . $from . '/' . $to . '/transactions.json';
        [$status, $body] = $this->transport === null
            ? $this->request($url) : ($this->transport)($url);
        if ($status !== 200) {
            throw new RuntimeException(self::httpError((int) $status));
        }
        if (!is_string($body) || strlen($body) > self::MAX_BODY_BYTES) {
            throw new RuntimeException('Výpis Fio je příliš velký nebo neplatný.');
        }
        $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
        $statement = is_array($data) ? ($data['accountStatement'] ?? null) : null;
        if (!is_array($statement) || !is_array($statement['info'] ?? null)) {
            throw new RuntimeException('Fio nevrátilo identifikaci účtu ve výpisu.');
        }
        $transactions = $statement['transactionList']['transaction'] ?? [];
        if ($transactions === null) $transactions = [];
        if (!is_array($transactions) || array_keys($transactions) !== range(0, count($transactions) - 1) && $transactions !== []) {
            throw new RuntimeException('Fio nevrátilo platný seznam pohybů.');
        }
        return ['info' => $statement['info'], 'transactions' => $transactions];
    }

    /** Tokens generated in Fio internet banking have exactly 64 URL-safe characters. */
    public static function validToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{64}$/D', $token) === 1;
    }

    private static function httpError(int $status): string
    {
        return match ($status) {
            404 => 'Fio nezná adresu požadavku (HTTP 404). Zkontroluj parametry volání API.',
            409 => 'Fio dovoluje požadavek se stejným tokenem nejvýše jednou za 30 sekund (HTTP 409).',
            413 => 'Výpis Fio obsahuje příliš mnoho pohybů (HTTP 413). Zkrať období stahování.',
            422 => 'Fio odmítlo požadavek na starší historii bez autorizace (HTTP 422).',
            500 => 'Fio odmítlo token (HTTP 500): podle dokumentace neexistuje nebo není aktivní. V internetbankingu v Nastavení → API zkontroluj dokončenou autorizaci a platnost tokenu. Po aktivaci vyčkej 5 minut; nový token ulož v nastavení plateb.',
            default => 'Fio nevrátilo výpis (HTTP ' . $status . '). Zkus to znovu nebo ověř dostupnost API.',
        };
    }

    private function request(string $url): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Na serveru chybí PHP cURL.');
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Spojení s Fio nelze otevřít.');
        $body = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'simple-store-fio/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($ch) === false) {
                $code = curl_errno($ch);
                $phase = $code === 28 ? self::timeoutPhase($ch) : '';
                throw new RuntimeException(FioTransportError::message($code, $tooLarge, $phase));
            }
            return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
        } finally {
            curl_close($ch);
        }
    }

    private static function timeoutPhase(\CurlHandle $ch): string
    {
        // Only fixed phase labels leave this method; never forward curl_error() or the request URL.
        if ((float) curl_getinfo($ch, CURLINFO_CONNECT_TIME) <= 0) return 'navazování spojení';
        if ((float) curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) <= 0) return 'vyjednávání TLS';
        if ((float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) <= 0) return 'čekání na odpověď banky';
        return 'stahování výpisu';
    }
}
