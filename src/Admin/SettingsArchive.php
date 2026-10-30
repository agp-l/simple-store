<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use Throwable;
use SimpleStore\Accounting\MailCredential;

/** Non-destructive settings transfer. No schema changes, DELETE, REPLACE or external API calls. */
final class SettingsArchive
{
    public const FORMAT = 'simple-store-settings-archive';
    public const LIMIT = 8388608;
    public const TABLES = [
        'checkout' => ['shop_checkout_settings', 'id', ['settings_json']],
        'mail' => ['shop_mail_settings', 'id', ['settings_json']],
        'google' => ['shop_google_login_settings', 'id', ['settings_json']],
        'business' => ['shop_tax_settings', 'id', ['settings_json']],
        'site_texts' => ['shop_site_copy', 'language', ['copy_json']],
        'mail_templates' => ['shop_mail_templates', 'event_code', ['enabled', 'subject', 'message_text']],
        'product_import' => ['shop_product_import_settings', 'id', ['prompt_text']],
        'supplier_prices' => ['shop_supplier_price_settings', 'id', ['paused', 'weekly_enabled', 'cron_hash', 'options_json']],
    ];
    private const SECRETS = ['smtp_password_encrypted' => 'smtp_password', 'client_secret_encrypted' => 'client_secret'];

    public function __construct(private MeekroDB $db, private array $defaults = []) {}

    public static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Export never creates a row or changes an encryption key. */
    public function snapshot(bool $lock = false): array
    {
        $data = [];
        foreach (self::TABLES as $section => [$table, $primary, $columns]) {
            $engine = $this->db->queryFirstField('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table);
            if ($engine === null) continue;
            if (strtolower((string)$engine) !== 'innodb') throw new RuntimeException('Nastavení vyžaduje transakční tabulky InnoDB.');
            $rows = $this->db->query('SELECT * FROM '.$table.' ORDER BY '.$primary.($lock ? ' FOR UPDATE' : ''));
            $data[$section] = $this->defaults[$section] ?? [];
            foreach ($rows as $row) {
                $key = (string)$row[$primary];
                $entry = [];
                foreach ($columns as $column) {
                    if (!array_key_exists($column, $row)) continue;
                    $value = $row[$column];
                    if (str_ends_with($column, '_json')) {
                        $value = $value === null ? [] : json_decode((string)$value, true, 64, JSON_THROW_ON_ERROR);
                        if (!is_array($value)) throw new RuntimeException('Neplatné uložené nastavení: '.$section);
                        $value = self::portable($value);
                    } elseif (in_array($column, ['enabled', 'paused', 'weekly_enabled'], true)) $value = (int)$value;
                    $entry[$column] = $value;
                }
                $data[$section][$key] = array_replace_recursive($data[$section][$key] ?? [], $entry);
            }
        }
        return $data;
    }

    public static function portable(array $values): array
    {
        foreach (self::SECRETS as $encrypted => $plain) {
            if (!array_key_exists($encrypted, $values)) continue;
            $values[$plain] = $values[$encrypted] === '' ? '' : MailCredential::decrypt($values[$encrypted]);
            unset($values[$encrypted]);
        }
        return $values;
    }

    public static function digest(array $data): string
    {
        $sort = static function ($value) use (&$sort) {
            if (!is_array($value)) return $value;
            ksort($value);
            return array_map($sort, $value);
        };
        return hash('sha256', self::json($sort($data)));
    }

    public static function decode(string $json): array
    {
        if ($json === '' || strlen($json) > self::LIMIT) throw new InvalidArgumentException('Vyber JSON do 8 MiB.');
        try { $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new InvalidArgumentException('Soubor neobsahuje platný JSON.'); }
        if (!is_array($value) || ($value['format'] ?? null) !== self::FORMAT || ($value['version'] ?? null) !== 1 || !is_array($value['data'] ?? null)) {
            throw new InvalidArgumentException('Nepodporovaná záloha nastavení. Použij Export v tomto obchodě.');
        }
        return $value['data'];
    }

    /** Empty values cannot erase data. Missing keys and list elements are retained. */
    public static function merge(array $current, array $incoming, bool $overwrite, string $path = ''): array
    {
        foreach ($incoming as $key => $value) {
            if (!is_string($key) && !is_int($key)) throw new InvalidArgumentException('Neplatné pole.');
            $field = $path.'.'.$key;
            if (is_string($key) && str_ends_with($key, '_encrypted')) throw new InvalidArgumentException('Import vyžaduje přenositelné klíče z Exportu, nikoli šifrované hodnoty.');
            if ($value === null || $value === '' || $value === []) continue;
            if (is_array($value)) {
                if (isset($current[$key]) && !is_array($current[$key])) throw new InvalidArgumentException('Nesprávný typ: '.$field);
                $current[$key] = self::merge($current[$key] ?? [], $value, $overwrite, $field);
            } else {
                if (!is_scalar($value) || (is_string($value) && (strlen($value) > 300000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)))) throw new InvalidArgumentException('Neplatná hodnota: '.$field);
                if (array_key_exists($key, $current) && $current[$key] !== null && gettype($current[$key]) !== gettype($value)) throw new InvalidArgumentException('Nesprávný typ: '.$field);
                if (!array_key_exists($key, $current) || $current[$key] === '' || $current[$key] === null || $overwrite) $current[$key] = $value;
            }
        }
        return $current;
    }

    public function plan(array $current, array $incoming, bool $overwrite): array
    {
        $next = $current; $changes = [];
        foreach ($incoming as $section => $rows) {
            if (!isset(self::TABLES[$section]) || !isset($current[$section]) || !is_array($rows)) throw new InvalidArgumentException('Neznámá nebo nenainstalovaná oblast: '.(string)$section);
            [, $primary, $columns] = self::TABLES[$section];
            foreach ($rows as $key => $row) {
                if (($primary === 'id' && (string)$key !== '1') || ($primary === 'language' && preg_match('/^[a-z]{2}$/D', (string)$key) !== 1) ||
                    ($primary === 'event_code' && !isset($current[$section][$key])) || !is_array($row) || array_diff(array_keys($row), $columns)) throw new InvalidArgumentException('Neplatný záznam v oblasti '.$section);
                foreach ($row as $column => $value) {
                    if (str_ends_with($column, '_json') && !is_array($value)) throw new InvalidArgumentException('Nastavení musí být objekt: '.$section);
                    if (!str_ends_with($column, '_json') && !is_scalar($value) && $value !== null) throw new InvalidArgumentException('Neplatné pole: '.$section);
                    if (in_array($column, ['enabled','paused','weekly_enabled'], true) && !in_array($value,[0,1],true)) throw new InvalidArgumentException('Neplatný přepínač.');
                }
                $before = $current[$section][$key] ?? [];
                $after = self::merge($before, $row, $overwrite, $section.'.'.$key);
                if ($section === 'google' && ($after['settings_json']['client_id'] ?? '') !== ($before['settings_json']['client_id'] ?? '') && empty($row['settings_json']['client_secret'])) throw new InvalidArgumentException('Změna Google Client ID vyžaduje současně jeho Client secret.');
                if (self::digest($before) !== self::digest($after)) $this->validate($section, $after, (string)$key);
                if (self::digest($before) !== self::digest($after)) {
                    $next[$section][$key] = $after;
                    $changes[] = ['section'=>$section, 'key'=>(string)$key, 'before'=>$before, 'after'=>$after];
                }
            }
        }
        return ['data'=>$next, 'changes'=>$changes, 'version'=>self::digest($current)];
    }

    private function validate(string $section, array $row, string $key): void
    {
        $s = $row['settings_json'] ?? [];
        if ($section === 'site_texts') \SimpleStore\Content\SiteCopyRepository::validate($row['copy_json']);
        if ($section === 'mail_templates') {
            \SimpleStore\Accounting\MailContent::validatePlaceholders(($row['subject'] ?? '').' '.($row['message_text'] ?? ''), \SimpleStore\Accounting\MailContent::placeholders($key));
            if (!is_string($row['subject'] ?? null) || $row['subject'] === '' || strlen($row['subject']) > 190 || preg_match('/[\r\n]/', $row['subject']) || !is_string($row['message_text'] ?? null) || strlen($row['message_text']) > 5000) throw new InvalidArgumentException('Neplatná šablona e-mailu.');
        }
        if ($section === 'google') {
            if (strlen($s['client_id'] ?? '') > 200 || strlen($s['client_secret'] ?? '') > 512 || preg_match('/[\x00-\x20\x7f]/', $s['client_secret'] ?? '')) throw new InvalidArgumentException('Neplatné přístupové údaje Google.');
            if (($s['client_id'] ?? '') !== '' && (!is_string($s['client_id']) || preg_match('/^[a-zA-Z0-9-]+\.apps\.googleusercontent\.com$/D', $s['client_id']) !== 1)) throw new InvalidArgumentException('Neplatné Google Client ID.');
            if (!empty($s['enabled']) && (empty($s['client_id']) || empty($s['client_secret']))) throw new InvalidArgumentException('Zapnutý Google vyžaduje ID i secret.');
        }
        if ($section === 'checkout') {
            (new \SimpleStore\Checkout\CheckoutSettingsRepository($this->db))->saveSection('legal', ['terms_url'=>$s['terms_url'] ?? ''], '', $s, false);
            if (isset($s['delivery_estimate'])) \SimpleStore\Product\DeliveryEstimate::validate($s['delivery_estimate']);
            foreach (['comgate','gopay','btcpay','fio_bank','packeta','ppl','bank_transfer','shipping_methods'] as $field) if (isset($s[$field]) && !is_array($s[$field])) throw new InvalidArgumentException('Neplatná konfigurace '.$field);
            if (isset($s['shipping_methods'])) new \SimpleStore\Checkout\ShippingPolicy($s['shipping_methods']);
            foreach (['comgate'=>['merchant','secret'], 'gopay'=>['goid','client_id','client_secret'], 'btcpay'=>['server_url','store_id','api_key','webhook_secret'], 'fio_bank'=>['account_display','token']] as $gateway=>$required) {
                if (empty($s[$gateway]['enabled'])) continue;
                foreach ($required as $field) if (!is_string($s[$gateway][$field] ?? null) || $s[$gateway][$field] === '') throw new InvalidArgumentException('Chybí údaj zapnuté služby '.$gateway.'.'.$field);
            }
            if (!empty($s['fio_bank']['token']) && !\SimpleStore\Checkout\FioStatementClient::validToken($s['fio_bank']['token'])) throw new InvalidArgumentException('Neplatný Fio token.');
        }
        if ($section === 'business') {
            foreach (['email','mail_from'] as $f) if (!empty($s[$f]) && filter_var($s[$f], FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Neplatný kontaktní e-mail.');
            if (isset($s['legal_form']) && !in_array($s['legal_form'], ['sole_trader','company'], true) || isset($s['expense_method']) && !in_array($s['expense_method'], ['actual','percentage'], true) || isset($s['expense_percentage']) && !in_array($s['expense_percentage'], [30,40,60,80], true)) throw new InvalidArgumentException('Neplatné údaje provozovatele.');
        }
        if ($section === 'supplier_prices') {
            $options=$row['options_json'] ?? [];
            \SimpleStore\Supplier\PricingPolicy::settings($options + \SimpleStore\Supplier\PricingPolicy::DEFAULTS);
        }
        if ($section === 'mail') {
            if (strlen($s['smtp_password'] ?? '') > 512 || preg_match('/[\x00-\x1f\x7f]/', $s['smtp_password'] ?? '') || empty($s['from_name']) || strlen($s['from_name']) > 100 || preg_match('/[\r\n]/', $s['from_name'])) throw new InvalidArgumentException('Neplatné nastavení odesílatele nebo SMTP hesla.');
            $host=$s['smtp_host'] ?? '';
            if ($host !== '' && (empty($s['smtp_username']) || empty($s['smtp_password']) || (filter_var($host,FILTER_VALIDATE_IP) === false && preg_match('/^(?=.{1,253}$)[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?$/iD',$host)!==1) || str_contains($host,'..'))) throw new InvalidArgumentException('Zkontroluj server SMTP, uživatele a heslo.');
            foreach (['from_email','reply_to','admin_recovery_email'] as $field) if (!empty($s[$field]) && filter_var($s[$field], FILTER_VALIDATE_EMAIL) === false) throw new InvalidArgumentException('Neplatný e-mail '.$field);
            if (isset($s['smtp_port']) && (!is_int($s['smtp_port']) || $s['smtp_port'] < 1 || $s['smtp_port'] > 65535)) throw new InvalidArgumentException('Neplatný SMTP port.');
            if (isset($s['smtp_security']) && !in_array($s['smtp_security'], ['tls','starttls'], true)) throw new InvalidArgumentException('Neplatné zabezpečení SMTP.');
            if (isset($s['copy'])) \SimpleStore\Accounting\MailSettingsRepository::validateCopy($s['copy']);
        }
        if (isset($row['cron_hash']) && $row['cron_hash'] !== '' && preg_match('/^[a-f0-9]{64}$/D', $row['cron_hash']) !== 1) throw new InvalidArgumentException('Neplatný otisk cron klíče.');
    }

    /** Caller must retain/download a before-snapshot. Recheck every value under row locks. */
    public function apply(array $incoming, bool $overwrite, string $version): int
    {
        return (new \SimpleStore\Supplier\WorkerLock($this->db))->run(fn() => $this->applyLocked($incoming, $overwrite, $version));
    }

    private function applyLocked(array $incoming, bool $overwrite, string $version): int
    {
        $this->db->startTransaction();
        try {
            $current = $this->snapshot(true);
            if (!hash_equals(self::digest($current), $version)) throw new InvalidArgumentException('Nastavení se od náhledu změnilo. Nic nebylo importováno; vytvoř nový náhled.');
            $plan = $this->plan($current, $incoming, $overwrite);
            foreach ($plan['changes'] as $change) {
                [$table,$primary,$columns] = self::TABLES[$change['section']];
                $fields = [$primary => $change['key']];
                foreach ($change['after'] as $column => $value) {
                    if (str_ends_with($column, '_json')) {
                        foreach (self::SECRETS as $encrypted => $plain) {
                            if (!array_key_exists($plain, $value)) continue;
                            $value[$encrypted] = $value[$plain] === '' ? '' : MailCredential::encrypt($value[$plain]);
                            unset($value[$plain]);
                        }
                        $value = self::json($value);
                    }
                    $fields[$column] = $value;
                }
                // Only known columns; identifiers never originate in the uploaded file.
                $names = array_keys($fields);
                $updates = array_map(static fn($name) => $name.'=VALUES('.$name.')', array_slice($names, 1));
                $this->db->query('INSERT INTO '.$table.' ('.implode(',', $names).') VALUES ('.implode(',',array_fill(0,count($names),'%s')).') ON DUPLICATE KEY UPDATE '.implode(',', $updates), ...array_values($fields));
            }
            $this->db->commit();
            return count($plan['changes']);
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
