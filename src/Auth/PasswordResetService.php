<?php
declare(strict_types=1);

namespace SimpleStore\Auth;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use SimpleStore\Accounting\MailSettingsRepository;
use SimpleStore\Accounting\MailTransport;
use Throwable;

/** Role-scoped, time-limited password recovery. A password change invalidates all account sessions. */
final class PasswordResetService
{
    private $transport;

    public function __construct(private MeekroDB $db, ?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function available(): bool
    {
        return (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_password_resets') > 0;
    }

    /** Returns the same result whether the account exists, is inactive, or has been throttled. */
    public function request(string $role, string $identifier, string $path): void
    {
        self::role($role);
        if (!in_array($path, ['admin.php', 'account.php'], true)) throw new InvalidArgumentException('Neplatná adresa obnovy.');
        if (!$this->available()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky.');
        $settings = (new MailSettingsRepository($this->db))->load()['settings'];
        if ($settings['from_email'] === '' || $settings['public_base_url'] === '') {
            throw new RuntimeException('Pro obnovu hesla nastav odesílatele a veřejnou HTTPS adresu v Nastavení obchodu → E-maily.');
        }
        if ($role === 'admin') {
            $email = strtolower(trim((string) $settings['admin_recovery_email']));
            $user = $email !== '' && hash_equals($email, strtolower(trim($identifier)))
                ? $this->db->queryFirstRow('SELECT id, password_hash FROM users WHERE role=%s AND is_active=1 ORDER BY id LIMIT 1', 'admin')
                : null;
        } else {
            $email = strtolower(trim($identifier));
            $user = filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 254
                ? $this->db->queryFirstRow('SELECT id, password_hash FROM users WHERE email=%s AND role=%s AND is_active=1 LIMIT 1',
                    $email, 'customer') : null;
        }
        if ($user === null) {
            return;
        }
        $id = (int) $user['id'];
        if ((int) $this->db->queryFirstField('SELECT COUNT(*) FROM shop_password_resets
            WHERE user_id=%i AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)', $id) > 0) return;
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $this->db->query('INSERT INTO shop_password_resets (token_hash, user_id, password_hash_at_issue, role, created_at, expires_at)
            VALUES (%s, %i, %s, %s, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))',
            $hash, $id, hash('sha256', (string) $user['password_hash']), $role);
        $url = rtrim($settings['public_base_url'], '/') . '/' . $path . '?mode=reset&token=' . $token;
        $subject = '=?UTF-8?B?' . base64_encode($role === 'admin'
            ? 'Obnova hesla administrace · dobrodruzi.cz'
            : 'Obnova hesla zákaznického účtu · dobrodruzi.cz') . '?=';
        $from = (string) $settings['from_email'];
        $name = '=?UTF-8?B?' . base64_encode((string) $settings['from_name']) . '?=';
        $headers = 'From: ' . $name . ' <' . $from . ">\r\nContent-Type: text/plain; charset=UTF-8";
        $account = $role === 'admin' ? 'administrátorskému účtu' : 'zákaznickému účtu';
        $message = "Pro obnovu hesla k {$account} na dobrodruzi.cz otevři tento odkaz:\n\n{$url}\n\n"
            . "Odkaz platí 30 minut a lze jej použít jen jednou. Pokud jsi o obnovu nežádal(a), zprávu ignoruj.\n";
        try {
            $delivered = $this->transport !== null
                ? ($this->transport)($email, $subject, $message, $headers)
                : (new MailTransport($settings))->send($email, $subject, $message, $headers);
            if (!$delivered) {
                throw new RuntimeException('Poštovní server zprávu nepřijal.');
            }
        } catch (Throwable $error) {
            $this->db->query('DELETE FROM shop_password_resets WHERE token_hash=%s', $hash);
            error_log('Password recovery delivery failed: ' . $error->getMessage());
        }
    }

    public function valid(string $role, string $token): bool
    {
        self::role($role);
        if (!$this->available() || !self::tokenValid($token)) return false;
        $row = $this->db->queryFirstRow('SELECT r.password_hash_at_issue, u.password_hash FROM shop_password_resets r
            JOIN users u ON u.id=r.user_id WHERE r.token_hash=%s AND r.role=%s AND u.role=%s AND u.is_active=1
            AND r.expires_at > UTC_TIMESTAMP() LIMIT 1', hash('sha256', $token), $role, $role);
        return $row !== null && self::passwordUnchanged($row);
    }

    public function complete(string $role, string $token, string $password, string $confirmation): void
    {
        self::role($role);
        if ($password !== $confirmation || preg_match('/^.{10,}$/usD', $password) !== 1 || strlen($password) > 72) {
            throw new InvalidArgumentException('Hesla se musí shodovat a mít 10 až 72 znaků.');
        }
        if (!self::tokenValid($token) || !$this->available()) {
            throw new InvalidArgumentException('Odkaz pro obnovu vypršel nebo už byl použit.');
        }
        $hash = hash('sha256', $token);
        $this->db->startTransaction();
        try {
            $row = $this->db->queryFirstRow('SELECT r.user_id, r.password_hash_at_issue, u.password_hash
                FROM shop_password_resets r
                JOIN users u ON u.id=r.user_id WHERE r.token_hash=%s AND r.role=%s AND u.role=%s AND u.is_active=1
                AND r.expires_at > UTC_TIMESTAMP() LIMIT 1 FOR UPDATE',
                $hash, $role, $role);
            if ($row === null || !self::passwordUnchanged($row)) {
                throw new InvalidArgumentException('Odkaz pro obnovu vypršel nebo už byl použit.');
            }
            $userId = (int) $row['user_id'];
            $this->db->query('UPDATE users SET password_hash=%s, password_changed_at=UTC_TIMESTAMP()
                WHERE id=%i AND role=%s AND is_active=1', password_hash($password, PASSWORD_DEFAULT), $userId, $role);
            $this->db->query('DELETE FROM shop_password_resets WHERE user_id=%i', $userId);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private static function tokenValid(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    private static function passwordUnchanged(array $row): bool
    {
        return is_string($row['password_hash_at_issue'] ?? null) &&
            is_string($row['password_hash'] ?? null) &&
            hash_equals($row['password_hash_at_issue'], hash('sha256', $row['password_hash']));
    }

    private static function role(string $role): void
    {
        if (!in_array($role, ['admin', 'customer'], true)) throw new InvalidArgumentException('Neplatný typ účtu.');
    }
}
