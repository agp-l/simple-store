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
        unset($local['local_test_checkout']);
        $local['btc_prices_enabled'] = is_bool($local['btc_prices_enabled'] ?? null)
            ? $local['btc_prices_enabled'] : ($example['btc_prices_enabled'] ?? true);
        $local['shipping_methods'] = self::normalizedMethods($local['shipping_methods'] ?? [],
            $example['shipping_methods']);
        $local['packeta'] = is_array($local['packeta'] ?? null)
            ? array_replace($example['packeta'], $local['packeta']) : $example['packeta'];
        $local['ppl'] = is_array($local['ppl'] ?? null)
            ? array_replace($example['ppl'] ?? ['widget_key' => ''], $local['ppl'])
            : ($example['ppl'] ?? ['widget_key' => '']);
        $comgateDefaults = $example['comgate'] ?? [
            'enabled' => false, 'test' => true, 'merchant' => '', 'secret' => '', 'return_base_url' => '',
        ];
        $local['comgate'] = is_array($local['comgate'] ?? null)
            ? array_replace($comgateDefaults, $local['comgate']) : $comgateDefaults;
        $gopayDefaults = $example['gopay'] ?? [
            'enabled' => false, 'test' => true, 'goid' => '', 'client_id' => '',
            'client_secret' => '', 'return_base_url' => '',
        ];
        $local['gopay'] = is_array($local['gopay'] ?? null)
            ? array_replace($gopayDefaults, $local['gopay']) : $gopayDefaults;
        $btcpayDefaults = $example['btcpay'] ?? [
            'enabled' => false, 'server_url' => '', 'store_id' => '', 'api_key' => '',
            'webhook_secret' => '', 'return_base_url' => '',
        ];
        $local['btcpay'] = is_array($local['btcpay'] ?? null)
            ? array_replace($btcpayDefaults, $local['btcpay']) : $btcpayDefaults;
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
        unset($saved['local_test_checkout']);
        $result = array_replace_recursive($fallback, $saved);
        $result['shipping_methods'] = self::normalizedMethods($saved['shipping_methods'] ?? [],
            $fallback['shipping_methods']);
        return $result;
    }

    /** Save one administration page without resetting values owned by the other pages. */
    public function saveSection(string $section, array $input, string $basePath, array $current): array
    {
        $fields = [
            'delivery' => ['shipping_price', 'shipping_enabled'],
            'carriers' => ['packeta_api_key', 'ppl_widget_key', 'packeta_sender',
                'packeta_api_password', 'packeta_clear_password'],
            'payment' => ['account_display', 'iban', 'recipient', 'payment_due_days',
                'comgate_enabled', 'comgate_test', 'comgate_merchant', 'comgate_return_base_url',
                'comgate_secret', 'comgate_clear_secret', 'gopay_enabled', 'gopay_test',
                'gopay_goid', 'gopay_client_id', 'gopay_return_base_url', 'gopay_client_secret',
                'gopay_clear_secret', 'btcpay_enabled', 'btcpay_server_url', 'btcpay_store_id',
                'btcpay_return_base_url', 'btcpay_api_key', 'btcpay_clear_api_key',
                'btcpay_webhook_secret', 'btcpay_clear_webhook_secret'],
            'prices' => ['btc_prices_enabled'],
            'legal' => ['terms_url'],
        ];
        if (!isset($fields[$section])) {
            throw new InvalidArgumentException('Neznámá část nastavení obchodu.');
        }
        $values = [
            'shipping_price' => [], 'shipping_enabled' => [],
            'btc_prices_enabled' => !empty($current['btc_prices_enabled']) ? '1' : '0',
            'account_display' => (string) ($current['bank_transfer']['account_display'] ?? ''),
            'iban' => (string) ($current['bank_transfer']['iban'] ?? ''),
            'recipient' => (string) ($current['bank_transfer']['recipient'] ?? ''),
            'payment_due_days' => (string) ($current['bank_transfer']['payment_due_days'] ?? 7),
            'terms_url' => (string) ($current['terms_url'] ?? ''),
            'packeta_api_key' => (string) ($current['packeta']['api_key'] ?? ''),
            'ppl_widget_key' => (string) ($current['ppl']['widget_key'] ?? ''),
            'packeta_sender' => (string) ($current['packeta']['sender'] ?? ''),
            'comgate_enabled' => !empty($current['comgate']['enabled']) ? '1' : '0',
            'comgate_test' => !empty($current['comgate']['test']) ? '1' : '0',
            'comgate_merchant' => (string) ($current['comgate']['merchant'] ?? ''),
            'comgate_return_base_url' => (string) ($current['comgate']['return_base_url'] ?? ''),
            'gopay_enabled' => !empty($current['gopay']['enabled']) ? '1' : '0',
            'gopay_test' => !empty($current['gopay']['test']) ? '1' : '0',
            'gopay_goid' => (string) ($current['gopay']['goid'] ?? ''),
            'gopay_client_id' => (string) ($current['gopay']['client_id'] ?? ''),
            'gopay_return_base_url' => (string) ($current['gopay']['return_base_url'] ?? ''),
            'btcpay_enabled' => !empty($current['btcpay']['enabled']) ? '1' : '0',
            'btcpay_server_url' => (string) ($current['btcpay']['server_url'] ?? ''),
            'btcpay_store_id' => (string) ($current['btcpay']['store_id'] ?? ''),
            'btcpay_return_base_url' => (string) ($current['btcpay']['return_base_url'] ?? ''),
            'packeta_api_password' => '', 'comgate_secret' => '',
            'gopay_client_secret' => '', 'btcpay_api_key' => '', 'btcpay_webhook_secret' => '',
        ];
        foreach (ShippingPolicy::defaults() as $code => $method) {
            $values['shipping_price'][$code] = (string) ($current['shipping_methods'][$code]['price_czk'] ?? $method['price_czk']);
            $values['shipping_enabled'][$code] = !empty($current['shipping_methods'][$code]['enabled']) ? '1' : '0';
        }
        foreach ($fields[$section] as $field) {
            if ($field === 'shipping_enabled' || $field === 'shipping_price') {
                $values[$field] = $input[$field] ?? [];
            } elseif (in_array($field, ['btc_prices_enabled', 'comgate_enabled', 'comgate_test',
                'gopay_enabled', 'gopay_test', 'btcpay_enabled'], true)) {
                $values[$field] = ($input[$field] ?? null) === '1' ? '1' : '0';
            } else {
                $values[$field] = $input[$field] ?? '';
            }
        }
        return $this->save($values, $basePath, $current);
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
        $comgateMerchant = self::value($input, 'comgate_merchant');
        if ($comgateMerchant !== '' && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $comgateMerchant) !== 1) {
            throw new InvalidArgumentException('Identifikátor obchodníka Comgate může obsahovat jen písmena, číslice, tečku, pomlčku a podtržítko.');
        }
        $comgateSecret = self::value($input, 'comgate_secret');
        if ($comgateSecret === '') {
            $comgateSecret = ($input['comgate_clear_secret'] ?? null) === '1' ? '' :
                (string) ($current['comgate']['secret'] ?? '');
        }
        if ($comgateSecret !== '' && (strlen($comgateSecret) > 256 ||
            preg_match('/^[\x21-\x7e]+$/D', $comgateSecret) !== 1)) {
            throw new InvalidArgumentException('Tajný klíč Comgate musí mít nejvýše 256 znaků bez mezer.');
        }
        $comgateEnabled = ($input['comgate_enabled'] ?? null) === '1';
        $comgateReturnBaseUrl = self::comgateReturnBaseUrl(
            self::value($input, 'comgate_return_base_url'), $basePath);
        if ($comgateEnabled && $comgateReturnBaseUrl === '') {
            throw new InvalidArgumentException('Před zapnutím Comgate vyplň veřejnou HTTPS adresu obchodu.');
        }
        $gopayGoid = self::value($input, 'gopay_goid');
        if ($gopayGoid !== '' && preg_match('/^[0-9]{1,20}$/D', $gopayGoid) !== 1) {
            throw new InvalidArgumentException('Identifikátor GoID musí obsahovat pouze číslice.');
        }
        $gopayClientId = self::value($input, 'gopay_client_id');
        if ($gopayClientId !== '' && (strlen($gopayClientId) > 256 ||
            preg_match('/^[\x21-\x7e]+$/D', $gopayClientId) !== 1)) {
            throw new InvalidArgumentException('Client ID GoPay musí mít nejvýše 256 znaků bez mezer.');
        }
        $gopayClientSecret = self::value($input, 'gopay_client_secret');
        if ($gopayClientSecret === '') {
            $gopayClientSecret = ($input['gopay_clear_secret'] ?? null) === '1' ? '' :
                (string) ($current['gopay']['client_secret'] ?? '');
        }
        if ($gopayClientSecret !== '' && (strlen($gopayClientSecret) > 256 ||
            preg_match('/^[\x21-\x7e]+$/D', $gopayClientSecret) !== 1)) {
            throw new InvalidArgumentException('Client secret GoPay musí mít nejvýše 256 znaků bez mezer.');
        }
        $gopayEnabled = ($input['gopay_enabled'] ?? null) === '1';
        $gopayReturnBaseUrl = self::paymentReturnBaseUrl(
            self::value($input, 'gopay_return_base_url'), $basePath, 'GoPay');
        if ($gopayEnabled && $gopayReturnBaseUrl === '') {
            throw new InvalidArgumentException('Před zapnutím GoPay vyplň veřejnou HTTPS adresu obchodu.');
        }
        $btcpayEnabled = ($input['btcpay_enabled'] ?? null) === '1';
        $btcpayServerUrl = self::btcpayServerUrl(self::value($input, 'btcpay_server_url'));
        $btcpayStoreId = self::value($input, 'btcpay_store_id');
        if ($btcpayStoreId !== '' && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $btcpayStoreId) !== 1) {
            throw new InvalidArgumentException('ID obchodu BTCPay musí obsahovat nejvýše 100 znaků bez mezer.');
        }
        $btcpayApiKey = self::retainedSecret($input, 'btcpay_api_key', 'btcpay_clear_api_key',
            (string) ($current['btcpay']['api_key'] ?? ''), 'API klíč BTCPay');
        $btcpayWebhookSecret = self::retainedSecret($input, 'btcpay_webhook_secret',
            'btcpay_clear_webhook_secret', (string) ($current['btcpay']['webhook_secret'] ?? ''),
            'Tajný klíč webhooku BTCPay');
        $btcpayReturnBaseUrl = self::paymentReturnBaseUrl(
            self::value($input, 'btcpay_return_base_url'), $basePath, 'BTCPay');
        if ($btcpayEnabled && ($btcpayServerUrl === '' || $btcpayStoreId === '' || $btcpayApiKey === '' ||
            $btcpayWebhookSecret === '' || $btcpayReturnBaseUrl === '')) {
            throw new InvalidArgumentException('Před zapnutím BTCPay vyplň adresu serveru, ID obchodu, API klíč, tajný klíč webhooku a veřejnou HTTPS adresu obchodu.');
        }
        $settings = [
            'btc_prices_enabled' => ($input['btc_prices_enabled'] ?? null) === '1',
            'shipping_methods' => $shipping,
            'packeta' => ['api_key' => $packetaKey, 'api_password' => $password, 'sender' => $sender],
            'ppl' => ['widget_key' => $pplKey],
            'bank_transfer' => $bankSettings,
            'comgate' => [
                'enabled' => $comgateEnabled,
                'test' => ($input['comgate_test'] ?? null) === '1',
                'merchant' => $comgateMerchant,
                'secret' => $comgateSecret,
                'return_base_url' => $comgateReturnBaseUrl,
            ],
            'gopay' => [
                'enabled' => $gopayEnabled,
                'test' => ($input['gopay_test'] ?? null) === '1',
                'goid' => $gopayGoid,
                'client_id' => $gopayClientId,
                'client_secret' => $gopayClientSecret,
                'return_base_url' => $gopayReturnBaseUrl,
            ],
            'btcpay' => [
                'enabled' => $btcpayEnabled,
                'server_url' => $btcpayServerUrl,
                'store_id' => $btcpayStoreId,
                'api_key' => $btcpayApiKey,
                'webhook_secret' => $btcpayWebhookSecret,
                'return_base_url' => $btcpayReturnBaseUrl,
            ],
            'terms_url' => $termsUrl,
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

    private static function comgateReturnBaseUrl(string $url, string $basePath): string
    {
        return self::paymentReturnBaseUrl($url, $basePath, 'Comgate');
    }

    private static function retainedSecret(array $input, string $field, string $clearField,
        string $current, string $label): string
    {
        $secret = self::value($input, $field);
        if ($secret === '') {
            $secret = ($input[$clearField] ?? null) === '1' ? '' : $current;
        }
        if ($secret !== '' && (strlen($secret) > 512 ||
            preg_match('/^[\x21-\x7e]+$/D', $secret) !== 1)) {
            throw new InvalidArgumentException($label . ' musí mít nejvýše 512 znaků bez mezer.');
        }
        return $secret;
    }

    private static function btcpayServerUrl(string $url): string
    {
        if ($url === '') return '';
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $domain = is_string($host) && strlen($host) <= 253 &&
            preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/iD', $host) === 1;
        $publicIpv4 = filter_var($host, FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $segments = explode('/', trim($path, '/'));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
            (!$domain && !$publicIpv4) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment']) ||
            preg_match('#^/(?:[A-Za-z0-9._~-]+/?)*$#D', $path === '' ? '/' : $path) !== 1 ||
            in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('BTCPay vyžaduje HTTPS adresu serveru, např. https://platby.obchod.cz.');
        }
        return rtrim($url, '/');
    }

    private static function paymentReturnBaseUrl(string $url, string $basePath, string $provider): string
    {
        if ($url === '') return '';
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $publicIpv4 = filter_var($host, FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        $publicDomain = is_string($host) && strlen($host) <= 253 &&
            preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/iD', $host) === 1 &&
            !preg_match('/(?:^|\.)(?:localhost|local|internal)$/iD', $host);
        $expectedPath = rtrim($basePath, '/');
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
            (!$publicIpv4 && !$publicDomain) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment']) ||
            !in_array($parts['path'] ?? '', [$expectedPath, $expectedPath . '/'], true)) {
            throw new InvalidArgumentException(
                $provider . ' vyžaduje veřejnou HTTPS adresu kořene obchodu bez parametrů, např. https://obchod.cz' .
                rtrim($basePath, '/') . '.');
        }
        return rtrim($url, '/');
    }
}
