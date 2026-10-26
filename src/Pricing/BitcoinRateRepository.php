<?php
declare(strict_types=1);

namespace SimpleStore\Pricing;

use MeekroDB;
use Throwable;

/** A shared, throttled CZK/BTC quote. A failed feed never changes the order price. */
final class BitcoinRateRepository
{
    private const ENDPOINT = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=czk&include_last_updated_at=true';
    private const FRESH_SECONDS = 900;
    private const MAX_AGE_SECONDS = 7200;
    private $transport;
    private $clock;

    public function __construct(private MeekroDB $db, ?callable $transport = null, ?callable $clock = null)
    {
        $this->transport = $transport ?? [$this, 'fetch'];
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return array{rate:float, updated_at:string}|null */
    public function current(): ?array
    {
        $row = null;
        try {
            $tables = $this->db->queryFirstColumn('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN %ls', ['shop_btc_rate_cache','shop_btc_rate_refresh']);
            if (!in_array('shop_btc_rate_cache', $tables, true)) return null;
            $row = $this->db->queryFirstRow('SELECT * FROM shop_btc_rate_cache WHERE id=1');
            // Deployment setup belongs to the authenticated worker, never a public request.
            if (!in_array('shop_btc_rate_refresh', $tables, true)) return $this->usable($row);
            $now = ($this->clock)();
            $state = $this->db->queryFirstRow('SELECT * FROM shop_btc_rate_refresh WHERE id=1');
            if (!$this->due($row, $state, $now)) return $this->usable($row);
            $lock = 'btc-czk:' . sha1((string)$this->db->queryFirstField('SELECT DATABASE()'));
            if ((int)$this->db->queryFirstField('SELECT GET_LOCK(%s,0)', $lock) !== 1) return $this->usable($row);
            try {
                $row = $this->db->queryFirstRow('SELECT * FROM shop_btc_rate_cache WHERE id=1');
                $state = $this->db->queryFirstRow('SELECT * FROM shop_btc_rate_refresh WHERE id=1');
                if (!$this->due($row, $state, $now)) return $this->usable($row);
                $failures = min(16, (int)($state['failures'] ?? 0) + 1);
                // Reserve BEFORE HTTP, so a timeout/process crash still leaves a cooldown.
                $this->db->query('INSERT INTO shop_btc_rate_refresh (id,next_attempt_at,failures) VALUES (1,%i,%i) ON DUPLICATE KEY UPDATE next_attempt_at=VALUES(next_attempt_at),failures=VALUES(failures)', $now + self::retryDelay($failures, 0, '', $now), $failures);
                try { $response = ($this->transport)(); }
                catch (Throwable $e) { $response = ['code'=>0, 'body'=>null, 'retry_after'=>'']; }
                $code = (int)($response['code'] ?? 0);
                $quote = $code === 200 ? self::parseQuote($response['body'] ?? null, $now) : null;
                $next = $now + ($quote !== null ? self::FRESH_SECONDS : self::retryDelay($failures, $code, (string)($response['retry_after'] ?? ''), $now));
                $this->db->update('shop_btc_rate_refresh', ['next_attempt_at'=>$next, 'failures'=>$quote !== null ? 0 : $failures, 'last_http_code'=>$code], 'id=1');
                $this->db->query('INSERT INTO shop_btc_rate_cache (id,rate_czk,source_updated_at,checked_at) VALUES (1,%s,%s,%s) ON DUPLICATE KEY UPDATE rate_czk=VALUES(rate_czk),source_updated_at=VALUES(source_updated_at),checked_at=VALUES(checked_at)',
                    $quote === null ? ($row['rate_czk'] ?? null) : (string)$quote['rate'], $quote['updated_at'] ?? $row['source_updated_at'] ?? null, gmdate('Y-m-d H:i:s', $now));
                return $quote ?? $this->usable($row);
            } finally { $this->db->queryFirstField('SELECT RELEASE_LOCK(%s)', $lock); }
        } catch (Throwable $exception) {
            error_log('BTC price quote unavailable: ' . get_class($exception));
            return $this->usable($row);
        }
    }

    private function due(?array $row, ?array $state, int $now): bool
    {
        $checked = $row === null ? 0 : (int)strtotime((string)$row['checked_at'] . ' UTC');
        return $checked <= $now - self::FRESH_SECONDS && (int)($state['next_attempt_at'] ?? 0) <= $now;
    }

    public static function retryDelay(int $failures, int $code, string $retryAfter, int $now): int
    {
        $delay = min(86400, self::FRESH_SECONDS * (2 ** min(7, max(0, $failures - 1))));
        if ($code === 429) $delay = max(3600, $delay);
        if (in_array($code, [401,403], true)) $delay = max(86400, $delay);
        $retry = ctype_digit($retryAfter) ? min((float)$retryAfter, 315360000) : max(0, (int)strtotime($retryAfter) - $now);
        return (int)max($delay, $retry);
    }

    /** @return array{rate:float, updated_at:string}|null */
    public static function parseQuote(?string $json, ?int $now = null): ?array
    {
        $now ??= time();
        if ($json === null) return null;
        $data = json_decode($json, true);
        $rate = $data['bitcoin']['czk'] ?? null;
        $time = $data['bitcoin']['last_updated_at'] ?? null;
        if (!is_numeric($rate) || !is_int($time) || (float) $rate < 1000 ||
            (float) $rate > 1000000000 || $time < $now - self::MAX_AGE_SECONDS || $time > $now + 300) {
            return null;
        }
        return ['rate' => (float) $rate, 'updated_at' => gmdate('Y-m-d H:i:s', $time)];
    }

    /** @return array{rate:float, updated_at:string}|null */
    private function usable(?array $row): ?array
    {
        if ($row === null || !is_numeric($row['rate_czk'] ?? null) ||
            (float) $row['rate_czk'] < 1000 || (float) $row['rate_czk'] > 1000000000) return null;
        $updated = strtotime((string) ($row['source_updated_at'] ?? '') . ' UTC');
        if ($updated === false || $updated < ($this->clock)() - self::MAX_AGE_SECONDS || $updated > ($this->clock)() + 300) return null;
        return ['rate' => (float) $row['rate_czk'], 'updated_at' => (string) $row['source_updated_at']];
    }

    private function fetch(): array
    {
        $handle = curl_init(self::ENDPOINT);
        if ($handle === false) return ['code'=>0];
        $body = ''; $retryAfter = '';
        curl_setopt_array($handle, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'SimpleStore/1.0 (CZK to BTC indicative display)',
            CURLOPT_HEADERFUNCTION => static function($handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) $retryAfter = trim(substr($header,12));
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 4096) return 0;
                $body .= $chunk; return strlen($chunk);
            }]);
        try {
            $ok = curl_exec($handle);
            return ['code'=>(int)curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body'=>$ok === false ? null : $body, 'retry_after'=>$retryAfter];
        } finally { curl_close($handle); }
    }
}
