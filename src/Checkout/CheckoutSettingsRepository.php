<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;

/** Store checkout configuration in the database so it can be edited in administration. */
final class CheckoutSettingsRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public static function withDefaults(array $local, array $example): array
    {
        $local['shipping_methods'] = self::normalizedMethods($local['shipping_methods'] ?? [],
            $example['shipping_methods']);
        $local['packeta'] = is_array($local['packeta'] ?? null)
            ? array_replace($example['packeta'], $local['packeta']) : $example['packeta'];
        $local['ppl'] = is_array($local['ppl'] ?? null)
            ? array_replace($example['ppl'] ?? ['widget_key' => ''], $local['ppl'])
            : ($example['ppl'] ?? ['widget_key' => '']);
        if (($local['bank_transfer']['account_display'] ?? '') === '' &&
            ($local['bank_transfer']['recipient'] ?? '') === '') {
            $local['bank_transfer'] = $example['bank_transfer'];
        }
        return $local;
    }

    public function load(array $fallback): array
    {
        if (!$this->installed()) {
            return $fallback;
        }
        $row = $this->db->queryFirstRow('SELECT settings_json FROM shop_checkout_settings WHERE id=%i', 1);
        if ($row === null) {
            return $fallback;
        }
        $saved = json_decode((string) $row['settings_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($saved)) {
            throw new InvalidArgumentException('Uložené nastavení objednávek není platné.');
        }
        $result = array_replace_recursive($fallback, $saved);
        $result['shipping_methods'] = self::normalizedMethods($saved['shipping_methods'] ?? [],
            $fallback['shipping_methods']);
        return $result;
    }

    public function save(array $input, string $basePath, array $current = []): array
    {
        $prices = $input['shipping_price'] ?? null;
        $enabled = $input['shipping_enabled'] ?? [];
        if (!is_array($prices) || !is_array($enabled)) {
            throw new InvalidArgumentException('Zkontroluj ceny a dostupnost doprav.');
        }
        $shipping = ShippingPolicy::defaults();
        foreach ($shipping as $code => &$method) {
            $raw = $prices[$code] ?? null;
            $price = is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 100000]]) : false;
            if ($price === false) {
                throw new InvalidArgumentException('Cena dopravy musí být celé číslo od 0 do 100 000 Kč.');
            }
            $method['price_czk'] = $price;
            $method['enabled'] = ($enabled[$code] ?? null) === '1';
        }
        unset($method);
        if ((new ShippingPolicy($shipping))->options() === []) {
            throw new InvalidArgumentException('Zapni alespoň jeden způsob dopravy.');
        }

        $account = self::value($input, 'account_display');
        $iban = self::value($input, 'iban');
        $recipient = self::value($input, 'recipient');
        if ($account !== '' || $iban !== '' || $recipient !== '') {
            if ($account === '' || $recipient === '') {
                throw new InvalidArgumentException('Pro platbu převodem vyplň číslo účtu a příjemce.');
            }
            $bank = new BankTransferPayment($iban, $account, $recipient);
            $bankSettings = $bank->snapshot();
        } else {
            $bankSettings = ['iban' => '', 'account_display' => '', 'recipient' => ''];
        }
        $dueDays = filter_var(self::value($input, 'payment_due_days'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 60]]);
        if ($dueDays === false) {
            throw new InvalidArgumentException('Splatnost platby musí být 1 až 60 dní.');
        }
        $bankSettings['payment_due_days'] = $dueDays;

        $termsUrl = self::value($input, 'terms_url');
        if ($termsUrl !== '' && (!str_starts_with($termsUrl, $basePath) ||
            preg_match('~^/(?:[a-z0-9]+(?:-[a-z0-9]+)*)(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*/?$~D', $termsUrl) !== 1)) {
            throw new InvalidArgumentException('Odkaz na podmínky musí být místní adresa stránky v tomto obchodě.');
        }
        $packetaKey = self::value($input, 'packeta_api_key');
        new PacketaPickupPoint($packetaKey);
        $pplKey = self::value($input, 'ppl_widget_key');
        new PplPickupPoint($pplKey);
        $password = self::value($input, 'packeta_api_password');
        if ($password === '') {
            $password = ($input['packeta_clear_password'] ?? null) === '1' ? '' :
                (string) ($current['packeta']['api_password'] ?? '');
        }
        if ($password !== '' && preg_match('/^[\x21-\x7e]{8,128}$/D', $password) !== 1) {
            throw new InvalidArgumentException('API heslo Zásilkovny musí mít 8 až 128 znaků bez mezer.');
        }
        $sender = self::value($input, 'packeta_sender');
        if ($sender !== '' && (strlen($sender) > 64 || preg_match('/[\x00-\x1f\x7f]/', $sender) ||
            preg_match('//u', $sender) !== 1)) {
            throw new InvalidArgumentException('Označení odesílatele Zásilkovny je neplatné.');
        }
        $settings = [
            'shipping_methods' => $shipping,
            'packeta' => ['api_key' => $packetaKey, 'api_password' => $password, 'sender' => $sender],
            'ppl' => ['widget_key' => $pplKey],
            'bank_transfer' => $bankSettings,
            'terms_url' => $termsUrl,
            'local_test_checkout' => ($input['local_test_checkout'] ?? null) === '1',
        ];
        $this->ensureTable();
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->query('INSERT INTO shop_checkout_settings (id, settings_json) VALUES (%i, %s)
            ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json), updated_at=CURRENT_TIMESTAMP', 1, $json);
        return $settings;
    }

    private function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_checkout_settings'
        ) > 0;
    }

    private function ensureTable(): void
    {
        $this->db->query('CREATE TABLE IF NOT EXISTS shop_checkout_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            settings_json LONGTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** Old one-method settings map onto PPL while exposing the new catalog. */
    private static function normalizedMethods(mixed $methods, array $defaults): array
    {
        if (!is_array($methods)) return $defaults;
        if (isset($methods['home']) && !isset($methods['ppl_home']) &&
            is_array($methods['home'])) {
            $defaults['ppl_home']['price_czk'] = $methods['home']['price_czk'] ??
                $defaults['ppl_home']['price_czk'];
        }
        unset($methods['home'], $methods['pickup']);
        return array_replace_recursive($defaults, $methods);
    }

    private static function value(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || strlen($value) > 1000) {
            throw new InvalidArgumentException('Neplatné pole nastavení: ' . $key . '.');
        }
        return trim($value);
    }
}
