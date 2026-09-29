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
        if (($local['shipping_methods'] ?? []) === []) {
            $local['shipping_methods'] = $example['shipping_methods'];
        }
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
        return array_replace_recursive($fallback, $saved);
    }

    public function save(array $input, string $basePath): array
    {
        $label = self::value($input, 'home_label');
        $price = filter_var(self::value($input, 'home_price_czk'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 100000]]);
        if ($price === false) {
            throw new InvalidArgumentException('Cena dopravy musí být celé číslo od 0 do 100 000 Kč.');
        }
        $shipping = ['home' => ['label' => $label, 'price_czk' => $price, 'requires_address' => true]];
        new ShippingPolicy($shipping);

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
        $settings = [
            'shipping_methods' => $shipping,
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

    private static function value(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || strlen($value) > 1000) {
            throw new InvalidArgumentException('Neplatné pole nastavení: ' . $key . '.');
        }
        return trim($value);
    }
}
