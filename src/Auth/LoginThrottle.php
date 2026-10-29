<?php
declare(strict_types=1);

namespace SimpleStore\Auth;

use InvalidArgumentException;
use RuntimeException;

/** Shared, bounded attempt budgets; clearing a browser cookie cannot reset them. */
final class LoginThrottle
{
    private const WINDOW = 300;
    private const MAX_ENTRIES = 10000;
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname(__DIR__, 2) . '/config/.login-throttle.json';
    }

    /** Reserve before checking credentials, so simultaneous attempts cannot exceed the budget. */
    public function consume(string $role, string $identifier, string $address, ?int $now = null): int
    {
        if (!in_array($role, ['admin', 'customer'], true)) throw new InvalidArgumentException('Invalid login role.');
        $now ??= time();
        $ip = @inet_pton($address);
        $ip = $ip === false ? 'unknown' : bin2hex($ip);
        $identity = strtolower(trim($identifier));
        $keys = [
            hash('sha256', $role . ':ip:' . $ip) => 100,
            hash('sha256', $role . ':account:' . $identity) => 30,
            hash('sha256', $role . ':pair:' . $ip . ':' . $identity) => 10,
        ];
        $lock = @fopen($this->file . '.lock', 'c');
        if ($lock === false) throw new RuntimeException('Login throttle storage is unavailable.');
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Login throttle storage is busy.');
            $state = [];
            if (is_file($this->file)) {
                $raw = @file_get_contents($this->file, false, null, 0, 2097153);
                if ($raw === false || strlen($raw) > 2097152) throw new RuntimeException('Invalid login throttle storage.');
                $state = json_decode($raw, true);
                if (!is_array($state) || count($state) > self::MAX_ENTRIES) throw new RuntimeException('Invalid login throttle storage.');
            }
            foreach ($state as $key => $entry) {
                if (!is_array($entry) || !is_int($entry['until'] ?? null) || !is_int($entry['count'] ?? null) || $entry['count'] < 1) {
                    throw new RuntimeException('Invalid login throttle entry.');
                }
                if ($entry['until'] <= $now) unset($state[$key]);
            }
            $retry = 0;
            foreach ($keys as $key => $limit) {
                if (($state[$key]['count'] ?? 0) >= $limit) $retry = max($retry, $state[$key]['until'] - $now);
            }
            if ($retry > 0) return $retry;
            foreach ($keys as $key => $limit) {
                $state[$key] ??= ['count' => 0, 'until' => $now + self::WINDOW];
                $state[$key]['count']++;
            }
            if (count($state) > self::MAX_ENTRIES) throw new RuntimeException('Login throttle storage is full.');
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            $temporary = $this->file . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $stream = @fopen($temporary, 'xb');
            if ($stream === false) throw new RuntimeException('Cannot write login throttle.');
            try {
                if (!chmod($temporary, 0600) || fwrite($stream, $json) !== strlen($json) || !fflush($stream)) {
                    throw new RuntimeException('Cannot write login throttle.');
                }
            } finally { fclose($stream); }
            if (!@rename($temporary, $this->file)) throw new RuntimeException('Cannot replace login throttle.');
            $temporary = null;
            return 0;
        } finally {
            if ($temporary !== null && is_file($temporary)) @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Called after CSRF validation, only on anonymous password-login submissions. */
    public static function guard(string $role, string $identifier): void
    {
        try {
            // Forwarded headers are client-controlled unless a trusted proxy rewrites REMOTE_ADDR.
            $retry = (new self())->consume($role, $identifier, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        } catch (RuntimeException $error) {
            error_log('Login protection unavailable: ' . $error->getMessage());
            http_response_code(503);
            header('Retry-After: 60');
            throw new InvalidArgumentException('Přihlášení je dočasně nedostupné. Zkus to za chvíli znovu.');
        }
        if ($retry > 0) {
            http_response_code(429);
            header('Retry-After: ' . $retry);
            throw new InvalidArgumentException('Příliš mnoho pokusů. Zkus to znovu za ' . (int) ceil($retry / 60) . ' min.');
        }
    }
}
