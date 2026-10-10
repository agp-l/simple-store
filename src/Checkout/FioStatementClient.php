<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use RuntimeException;

/** Read-only transport for Fio's period export; never expose token-bearing URLs in errors. */
final class FioStatementClient
{
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function fetch(string $token, string $from, string $to): array
    {
        if (preg_match('/^[A-Za-z0-9]{20,128}$/D', $token) !== 1 ||
            preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D', $from) !== 1 ||
            preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/D', $to) !== 1) {
            throw new RuntimeException('Neplatné nastavení tokenu nebo období Fio.');
        }
        $url = 'https://fioapi.fio.cz/v1/rest/periods/' . $token . '/' . $from . '/' . $to . '/transactions.json';
        [$status, $body] = $this->transport === null
            ? $this->request($url) : ($this->transport)($url);
        if ($status !== 200) {
            throw new RuntimeException($status === 409
                ? 'Fio dovoluje požadavek se stejným tokenem nejvýše jednou za 30 sekund.'
                : 'Fio nevrátilo výpis (HTTP ' . (int) $status . '). Zkontroluj token a přístup k účtu.');
        }
        if (!is_string($body) || strlen($body) > 16000000) {
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

    private function request(string $url): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('Na serveru chybí PHP cURL.');
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Spojení s Fio nelze otevřít.');
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'simple-store-fio/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 16000000) return 0;
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($ch) === false) throw new RuntimeException('Výpis Fio nelze bezpečně načíst.');
            return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
        } finally {
            curl_close($ch);
        }
    }
}
