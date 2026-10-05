<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use Throwable;

/** Editable copy and sending identity; operational order facts stay in code. */
final class MailSettingsRepository
{
    public const EVENTS = [
        'order' => ['label' => 'Přijetí objednávky', 'subject' => 'Přijali jsme objednávku {order_number}',
            'message' => 'Děkujeme, {customer_name}. Objednávku jsme přijali. Níže najdete její souhrn a způsob úhrady.'],
        'paid' => ['label' => 'Platba potvrzena', 'subject' => 'Platba za objednávku {order_number} dorazila',
            'message' => 'Platbu jsme obdrželi. O dalším postupu doručení vás budeme informovat.'],
        'processing' => ['label' => 'Připravuje se', 'subject' => 'Připravujeme objednávku {order_number}',
            'message' => 'Vaši objednávku právě připravujeme. Jakmile bude připravena, dáme vám vědět.'],
        'ready_to_ship' => ['label' => 'Připraveno k odeslání', 'subject' => 'Objednávka {order_number} je připravena',
            'message' => 'Balík je připravený k předání dopravci. O předání vás budeme informovat.'],
        'shipped' => ['label' => 'Předáno dopravci', 'subject' => 'Objednávka {order_number} je na cestě',
            'message' => 'Zásilka byla předána dopravci. Podrobnosti ke sledování najdete níže.'],
        'tracking' => ['label' => 'Doplněno sledování', 'subject' => 'Sledování zásilky {order_number}',
            'message' => 'Doplnili jsme číslo nebo odkaz pro sledování vaší zásilky.'],
        'completed' => ['label' => 'Dokončeno', 'subject' => 'Objednávka {order_number} je dokončena',
            'message' => 'Objednávku jsme označili jako dokončenou. Děkujeme za váš nákup.'],
        'cancelled' => ['label' => 'Zrušeno', 'subject' => 'Objednávka {order_number} byla zrušena',
            'message' => 'Vaše objednávka byla zrušena. V případě dotazů nám odpovězte na tento e-mail.'],
    ];

    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        foreach (['shop_mail_settings', 'shop_mail_templates'] as $table) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table) === 0) return false;
        }
        return true;
    }

    public function load(string $legacySender = ''): array
    {
        if ($legacySender === '') {
            $legacySender = (string) ((new TaxEvidenceRepository($this->db))->settings()['mail_from'] ?? '');
        }
        $settings = ['from_email' => $legacySender, 'from_name' => 'dobrodruzi.cz',
            'reply_to' => '', 'public_base_url' => '', 'automatic_enabled' => true,
            'admin_recovery_email' => '', 'smtp_host' => '', 'smtp_port' => 587,
            'smtp_security' => 'starttls', 'smtp_username' => '', 'smtp_password_encrypted' => ''];
        $templates = [];
        foreach (self::EVENTS as $event => $default) {
            // Routine internal handling steps should not generate three additional
            // customer messages between payment confirmation and dispatch.
            $templates[$event] = ['enabled' => !in_array($event,
                ['processing', 'ready_to_ship', 'completed'], true), 'subject' => $default['subject'],
                'message' => $default['message'], 'label' => $default['label']];
        }
        if (!$this->installed()) return ['settings' => $settings, 'templates' => $templates];
        $row = $this->db->queryFirstRow('SELECT settings_json FROM shop_mail_settings WHERE id=%i', 1);
        if ($row !== null) {
            $saved = json_decode((string) $row['settings_json'], true);
            if (is_array($saved)) $settings = array_replace($settings, array_intersect_key($saved, $settings));
        }
        foreach ($this->db->query('SELECT event_code, enabled, subject, message_text FROM shop_mail_templates') as $row) {
            $code = $row['event_code'];
            if (!isset($templates[$code])) continue;
            $templates[$code] = ['enabled' => (bool) $row['enabled'], 'subject' => (string) $row['subject'],
                'message' => (string) $row['message_text'], 'label' => self::EVENTS[$code]['label']];
        }
        return ['settings' => $settings, 'templates' => $templates];
    }

    public function save(array $input): void
    {
        if (!$this->installed()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        $email = self::field($input, 'from_email', 254);
        $reply = self::field($input, 'reply_to', 254);
        $name = self::field($input, 'from_name', 100);
        $baseUrl = rtrim(self::field($input, 'public_base_url', 500), '/');
        $previous = $this->load()['settings'];
        $recovery = self::field($input + $previous, 'admin_recovery_email', 254);
        $host = self::field($input + $previous, 'smtp_host', 253);
        $port = $input['smtp_port'] ?? $previous['smtp_port'];
        $security = $input['smtp_security'] ?? $previous['smtp_security'];
        $username = self::field($input + $previous, 'smtp_username', 254);
        $password = $input['smtp_password'] ?? '';
        if (!is_string($password) || strlen($password) > 512 || preg_match('/[\x00-\x1f\x7f]/', $password) === 1 ||
            !is_string($port) && !is_int($port) || !is_string($security) ||
            ($host !== '' && (filter_var($host, FILTER_VALIDATE_IP) === false &&
                preg_match('/^(?=.{1,253}$)[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?$/iD', $host) !== 1 || str_contains($host, '..'))) ||
            filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false ||
            !in_array($security, ['starttls', 'tls'], true) ||
            ($host !== '' && ($username === '' || ($password === '' && ($input['smtp_clear_password'] ?? '') === '1')))) {
            throw new InvalidArgumentException('Zkontroluj server SMTP, port, zabezpečení, uživatele a heslo.');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false ||
            $reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL) === false ||
            $recovery !== '' && filter_var($recovery, FILTER_VALIDATE_EMAIL) === false ||
            $name === '' || preg_match('/[\r\n]/', $name) ||
            $baseUrl !== '' && (!filter_var($baseUrl, FILTER_VALIDATE_URL) ||
                parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' ||
                parse_url($baseUrl, PHP_URL_QUERY) !== null ||
                parse_url($baseUrl, PHP_URL_FRAGMENT) !== null ||
                parse_url($baseUrl, PHP_URL_USER) !== null)) {
            throw new InvalidArgumentException('Zkontroluj jméno odesílatele, e-mailové adresy a veřejnou HTTPS adresu obchodu.');
        }
        $encrypted = ($input['smtp_clear_password'] ?? '') === '1' ? '' : (string) $previous['smtp_password_encrypted'];
        if ($password !== '') $encrypted = MailCredential::encrypt($password);
        if ($host !== '' && $encrypted === '') {
            throw new InvalidArgumentException('Pro SMTP vyplň heslo ke schránce.');
        }
        $settings = ['from_email' => $email, 'from_name' => $name, 'reply_to' => $reply,
            'public_base_url' => $baseUrl, 'automatic_enabled' => ($input['automatic_enabled'] ?? null) === '1',
            'admin_recovery_email' => $recovery, 'smtp_host' => $host,
            'smtp_port' => (int) $port, 'smtp_security' => $security,
            'smtp_username' => $username, 'smtp_password_encrypted' => $encrypted];
        $inputTemplates = $input['templates'] ?? null;
        if (!is_array($inputTemplates)) throw new InvalidArgumentException('Šablony e-mailů chybí.');
        $templates = [];
        foreach (self::EVENTS as $code => $default) {
            $entry = $inputTemplates[$code] ?? null;
            if (!is_array($entry)) throw new InvalidArgumentException('Šablona ' . $code . ' chybí.');
            $subject = self::field($entry, 'subject', 190);
            $message = self::field($entry, 'message', 5000);
            if ($subject === '' || $message === '' || preg_match('/[\r\n]/', $subject)) {
                throw new InvalidArgumentException('Zkontroluj předmět, text a zástupné značky šablony ' . $code . '.');
            }
            preg_match_all('/\{([^{}]+)\}/u', $subject . ' ' . $message, $found);
            foreach ($found[1] as $placeholder) {
                if (!in_array($placeholder, ['order_number', 'customer_name', 'total', 'carrier'], true)) {
                    throw new InvalidArgumentException('Neznámá zástupná značka {' . $placeholder . '}.');
                }
            }
            $templates[$code] = ['subject' => $subject, 'message' => $message,
                'enabled' => ($entry['enabled'] ?? null) === '1'];
        }
        $this->db->startTransaction();
        try {
            $this->db->query('INSERT INTO shop_mail_settings (id, settings_json) VALUES (%i, %s)
                ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json), updated_at=UTC_TIMESTAMP()',
                1, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            foreach ($templates as $code => $template) {
                $this->db->query('INSERT INTO shop_mail_templates (event_code, enabled, subject, message_text)
                    VALUES (%s, %i, %s, %s) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),
                    subject=VALUES(subject), message_text=VALUES(message_text), updated_at=UTC_TIMESTAMP()',
                    $code, $template['enabled'] ? 1 : 0, $template['subject'], $template['message']);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private static function field(array $input, string $key, int $max): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Neplatné pole e-mailu: ' . $key . '.');
        }
        return trim($value);
    }
}
