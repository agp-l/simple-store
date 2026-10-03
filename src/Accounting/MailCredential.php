<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use RuntimeException;

/** The encryption key stays outside the database and must accompany its backup. */
final class MailCredential
{
    private const KEY_FILE = __DIR__ . '/../../config/.smtp-key.php';

    public static function encrypt(string $password): string
    {
        if (!function_exists('openssl_encrypt')) throw new RuntimeException('PHP OpenSSL je nutné pro uložení SMTP hesla.');
        $key = self::key(true);
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($password, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) throw new RuntimeException('SMTP heslo se nepodařilo zabezpečit.');
        return base64_encode($nonce . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) < 28) throw new RuntimeException('Uložené SMTP heslo je neplatné.');
        $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', self::key(false), OPENSSL_RAW_DATA,
            substr($payload, 0, 12), substr($payload, 12, 16));
        if ($plain === false) throw new RuntimeException('Nelze přečíst SMTP heslo. Obnov také soubor config/.smtp-key.php ze zálohy.');
        return $plain;
    }

    private static function key(bool $create): string
    {
        if ($create && !is_file(self::KEY_FILE)) {
            $handle = @fopen(self::KEY_FILE, 'x');
            if ($handle === false && !is_file(self::KEY_FILE)) {
                throw new RuntimeException('Nelze vytvořit soubor config/.smtp-key.php. Zkontroluj práva adresáře config/.');
            }
            if ($handle !== false) {
                @chmod(self::KEY_FILE, 0600);
                try {
                    if (fwrite($handle, "<?php return '" . bin2hex(random_bytes(32)) . "';\n") !== 81) {
                        throw new RuntimeException('Nelze uložit šifrovací klíč SMTP.');
                    }
                } finally {
                    fclose($handle);
                }
            }
        }
        $hex = @file_get_contents(self::KEY_FILE);
        if (!is_string($hex) || preg_match("/^<\\?php return '([a-f0-9]{64})';\\n$/D", $hex, $matches) !== 1) {
            throw new RuntimeException('Chybí platný šifrovací klíč config/.smtp-key.php.');
        }
        return hex2bin($matches[1]);
    }
}
