<?php
declare(strict_types=1);

namespace SimpleStore\Auth;

use RuntimeException;

/** Session handling shared by the admin and customer roles. */
abstract class RoleAuth
{
    private string $idKey;
    private string $hashKey;

    public function __construct(string $cookiePath, string $sessionName, string $role, string $sameSite = 'Lax')
    {
        $this->idKey = $role . '_id';
        $this->hashKey = $role . '_hash';
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Only one account session may be active in a request.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // A storefront request may have read the separate cart session first.
        // Its closed session ID must never be reused for an account cookie.
        session_id('');
        session_name($sessionName);
        $incomingId = $_COOKIE[$sessionName] ?? null;
        if (is_string($incomingId) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $incomingId) === 1) {
            session_id($incomingId);
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
        if (!session_start()) {
            throw new RuntimeException('PHP sessions are unavailable. Check the session directory.');
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    abstract protected function byName(string $name): ?array;
    abstract protected function byId(int $id): ?array;

    public function user(): ?array
    {
        $id = $_SESSION[$this->idKey] ?? null;
        $sessionHash = $_SESSION[$this->hashKey] ?? null;
        if (!is_int($id) || $id < 1 || !is_string($sessionHash)) return null;
        $user = $this->byId($id);
        if ($user === null || !hash_equals(hash('sha256', $user['password_hash']), $sessionHash)) {
            unset($_SESSION[$this->idKey], $_SESSION[$this->hashKey]);
            return null;
        }
        return $user;
    }

    public function signedIn(): bool
    {
        return $this->user() !== null;
    }

    public function token(): string
    {
        return $_SESSION['csrf'];
    }

    public function validToken(mixed $token): bool
    {
        return is_string($token) && hash_equals($this->token(), $token);
    }

    public function retryAfterSeconds(): int
    {
        $remaining = max(0, (int) ($_SESSION['blocked_until'] ?? 0) - time());
        if ($remaining === 0) unset($_SESSION['blocked_until']);
        return $remaining;
    }

    public function signIn(string $name, string $password): bool
    {
        if ($this->retryAfterSeconds() > 0) return false;
        $user = $this->byName($name);
        if ($user !== null && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION[$this->idKey] = (int) $user['id'];
            $_SESSION[$this->hashKey] = hash('sha256', $user['password_hash']);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            unset($_SESSION['failed_logins'], $_SESSION['blocked_until']);
            return true;
        }
        $_SESSION['failed_logins'] = ($_SESSION['failed_logins'] ?? 0) + 1;
        if ($_SESSION['failed_logins'] >= 5) {
            $_SESSION['blocked_until'] = time() + 300;
            $_SESSION['failed_logins'] = 0;
        }
        return false;
    }

    public function signOut(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
