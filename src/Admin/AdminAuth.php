<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

/** Database-backed administrator login, protected by a password and PHP session. */
final class AdminAuth
{
    public function __construct(private AdminUserRepository $users, string $cookiePath)
    {
        ini_set('session.use_strict_mode', '1');
        session_name('simple_store_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        if (!session_start()) {
            throw new \RuntimeException('PHP sessions are unavailable. Check the session directory.');
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function signedIn(): bool
    {
        $id = $_SESSION['admin_id'] ?? null;
        $sessionHash = $_SESSION['admin_hash'] ?? null;
        if (!is_int($id) || $id < 1 || !is_string($sessionHash)) {
            return false;
        }
        $user = $this->users->findAdminById($id);
        if ($user === null || !hash_equals(hash('sha256', $user['password_hash']), $sessionHash)) {
            unset($_SESSION['admin_id'], $_SESSION['admin_hash']);
            return false;
        }
        return true;
    }

    public function token(): string
    {
        return $_SESSION['csrf'];
    }

    public function validToken(mixed $token): bool
    {
        return is_string($token) && hash_equals($this->token(), $token);
    }

    public function signIn(string $username, string $password): bool
    {
        if (($_SESSION['blocked_until'] ?? 0) > time()) {
            return false;
        }

        $user = $this->users->findAdminByUsername($username);
        if ($user !== null && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $user['id'];
            $_SESSION['admin_hash'] = hash('sha256', $user['password_hash']);
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
