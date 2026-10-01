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
    private const RETRY_SECONDS = 180;
    private const MAX_AGE_SECONDS = 7200;

    public function __construct(private MeekroDB $db)
    {
    }

    /** @return array{rate:float, updated_at:string}|null */
    public function current(): ?array
    {
        try {
            $exists = (int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                'shop_btc_rate_cache');
            if ($exists === 0) return null;
            $row = $this->db->queryFirstRow('SELECT rate_czk, source_updated_at, checked_at FROM shop_btc_rate_cache WHERE id=%i', 1);
            $checked = $row === null ? 0 : (int) strtotime((string) $row['checked_at'] . ' UTC');
            if ($checked > time() - self::FRESH_SECONDS && $this->usable($row) !== null) return $this->usable($row);
            if ($checked > time() - self::RETRY_SECONDS) return $this->usable($row);
            // One worker fetches a new rate; other requests use a recent cached value.
            if ((int) $this->db->queryFirstField('SELECT GET_LOCK(%s, %i)', 'shop_btc_czk_rate', 0) !== 1) {
                return $this->usable($row);
            }
            try {
                $row = $this->db->queryFirstRow('SELECT rate_czk, source_updated_at, checked_at FROM shop_btc_rate_cache WHERE id=%i', 1);
                $checked = $row === null ? 0 : (int) strtotime((string) $row['checked_at'] . ' UTC');
                if ($checked > time() - self::FRESH_SECONDS && $this->usable($row) !== null) return $this->usable($row);
                if ($checked > time() - self::RETRY_SECONDS) return $this->usable($row);
                $quote = self::parseQuote($this->fetch());
                if ($quote !== null) {
                    $this->db->query('INSERT INTO shop_btc_rate_cache (id, rate_czk, source_updated_at, checked_at) VALUES (%i, %s, %s, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE rate_czk=VALUES(rate_czk), source_updated_at=VALUES(source_updated_at), checked_at=UTC_TIMESTAMP()',
                        1, (string) $quote['rate'], $quote['updated_at']);
                    return $quote;
                }
                $this->db->query('INSERT INTO shop_btc_rate_cache (id, rate_czk, source_updated_at, checked_at) VALUES (%i, NULL, NULL, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE checked_at=UTC_TIMESTAMP()', 1);
                return $this->usable($row);
            } finally {
                $this->db->queryFirstField('SELECT RELEASE_LOCK(%s)', 'shop_btc_czk_rate');
            }
        } catch (Throwable $exception) {
            error_log('BTC price quote unavailable: ' . $exception->getMessage());
            return null;
        }
    }

    /** @return array{rate:float, updated_at:string}|null */
    public static function parseQuote(?string $json): ?array
    {
        if ($json === null) return null;
        $data = json_decode($json, true);
        $rate = $data['bitcoin']['czk'] ?? null;
        $time = $data['bitcoin']['last_updated_at'] ?? null;
        if (!is_numeric($rate) || !is_int($time) || (float) $rate < 1000 ||
            (float) $rate > 1000000000 || $time < time() - self::MAX_AGE_SECONDS || $time > time() + 300) {
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
        if ($updated === false || $updated < time() - self::MAX_AGE_SECONDS || $updated > time() + 300) return null;
        return ['rate' => (float) $row['rate_czk'], 'updated_at' => (string) $row['source_updated_at']];
    }

    private function fetch(): ?string
    {
        $handle = curl_init(self::ENDPOINT);
        if ($handle === false) return null;
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3, CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'SimpleStore/1.0 (CZK to BTC indicative display)']);
        try {
            $body = curl_exec($handle);
            return curl_getinfo($handle, CURLINFO_HTTP_CODE) === 200 && is_string($body) && strlen($body) < 4096
                ? $body : null;
        } finally {
            curl_close($handle);
        }
    }
}
