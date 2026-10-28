<?php
declare(strict_types=1);

namespace SimpleStore\Configuration;

use InvalidArgumentException;
use RuntimeException;

/** Administrator edits the same installation-owned JSON used by PHP and the scheduler. */
final class InstallationSettings
{
    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? Installation::configurationFile();
    }

    public static function revision(Installation $installation): string
    {
        return hash('sha256', self::json($installation));
    }

    public function save(string $address, string $revision): Installation
    {
        try { $address = Installation::normalize($address); }
        catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('Zadej úplnou HTTPS adresu obchodu, případně včetně podsložky. Bez /cs, parametrů a kotvy.', 0, $error);
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new InvalidArgumentException('Platnost formuláře vypršela. Obnov stránku a zkus uložení znovu.');
        }
        // A separate, persistent lock survives the atomic replacement of the JSON inode.
        $lock = @fopen(dirname($this->file) . '/.installation.lock', 'c');
        if ($lock === false) throw new RuntimeException(self::WRITE_ERROR);
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Právě probíhá jiné uložení adresy. Zkus to za chvíli znovu.');
            }
            $current = Installation::fromFile($this->file);
            if (!hash_equals(self::revision($current), $revision)) {
                throw new InvalidArgumentException('Adresa byla mezitím změněna. Obnov stránku, zkontroluj aktuální hodnotu a ulož ji znovu.');
            }
            $previous = $current->previousBaseUrls();
            if ($address !== $current->baseUrl() && !in_array($current->baseUrl(), $previous, true)) {
                if (count($previous) >= 32) throw new InvalidArgumentException('Historie adres je plná. Nejprve uprav previous_base_urls v config/installation.json.');
                $previous[] = $current->baseUrl();
            }
            $next = new Installation($address, $previous);
            $json = self::json($next);
            if (strlen($json) > 16384) throw new InvalidArgumentException('Historie adres je příliš dlouhá. Zkrať ji v config/installation.json.');
            $temporary = dirname($this->file) . '/.installation-' . bin2hex(random_bytes(8)) . '.tmp';
            $stream = @fopen($temporary, 'xb');
            if ($stream === false) throw new RuntimeException(self::WRITE_ERROR);
            try {
                // Keep the existing hosting permissions so the FTP scheduler can still read it.
                $permissions = @fileperms($this->file);
                if (!@chmod($temporary, $permissions === false ? 0644 : $permissions & 0777) ||
                    fwrite($stream, $json) !== strlen($json) || !fflush($stream) ||
                    (function_exists('fsync') && !fsync($stream))) {
                    throw new RuntimeException(self::WRITE_ERROR);
                }
            } finally { fclose($stream); }
            if (!@rename($temporary, $this->file)) throw new RuntimeException(self::WRITE_ERROR);
            $temporary = null;
            clearstatcache(true, $this->file);
            return $next;
        } finally {
            if ($temporary !== null && is_file($temporary)) @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private const WRITE_ERROR = 'Adresu se nepodařilo uložit. Hosting musí umožnit PHP zápis do složky config. Původní konfigurace zůstala zachovaná.';

    private static function json(Installation $installation): string
    {
        return json_encode(['public_base_url' => $installation->baseUrl(),
            'previous_base_urls' => $installation->previousBaseUrls()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
