<?php
declare(strict_types=1);

class MeekroDB
{
    public ?string $json = null;
    public bool $installed = false;

    public function queryFirstField(string $sql, mixed ...$values): int
    {
        return $this->installed ? 1 : 0;
    }

    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        return $this->json === null ? null : ['settings_json' => $this->json];
    }

    public function query(string $sql, mixed ...$values): array
    {
        if (str_starts_with($sql, 'CREATE TABLE')) {
            $this->installed = true;
        } elseif (str_starts_with($sql, 'INSERT INTO shop_checkout_settings')) {
            $this->json = $values[1];
        } else {
            throw new RuntimeException('Unexpected settings query.');
        }
        return [];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\BankTransferPayment;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\LocalCheckoutPreview;
use SimpleStore\Checkout\ShippingPolicy;

$example = require dirname(__DIR__) . '/config/checkout.example.php';
$db = new MeekroDB();
$repo = new CheckoutSettingsRepository($db);
$old = ['bank_transfer' => ['iban' => '', 'account_display' => '', 'recipient' => ''],
    'shipping_methods' => [], 'terms_url' => ''];
$fallback = CheckoutSettingsRepository::withDefaults($old, $example);
if ($fallback['bank_transfer']['account_display'] !== '' ||
    $fallback['shipping_methods']['ppl_home']['price_czk'] !== 99 ||
    count($fallback['shipping_methods']) !== 9 ||
    $repo->load($fallback) !== $fallback) {
    throw new RuntimeException('Old private checkout settings did not receive the new defaults.');
}
$generated = new BankTransferPayment('', '1265098001/5500', 'Test');
if ($generated->snapshot()['iban'] !== 'CZ5855000000001265098001') {
    throw new RuntimeException('The configured bank account did not yield a valid Czech IBAN.');
}
$input = ['shipping_price' => array_map('strval', array_column(ShippingPolicy::defaults(), 'price_czk')),
    'shipping_enabled' => array_fill_keys(array_keys(ShippingPolicy::defaults()), '1'),
    'account_display' => '1265098001/5500', 'iban' => '', 'recipient' => 'Test',
    'payment_due_days' => '10', 'terms_url' => '/simple-store/cs/obchodni-podminky',
    'packeta_api_key' => 'ABCDEF1234567890',
    'local_test_checkout' => '1'];
$input['shipping_price'] = array_combine(array_keys(ShippingPolicy::defaults()),
    array_values($input['shipping_price']));
$input['shipping_price']['ppl_home'] = '120';
$saved = $repo->save($input, '/simple-store/');
$loaded = $repo->load($fallback);
if ($loaded != $saved || $saved['bank_transfer']['iban'] !== $generated->snapshot()['iban'] ||
    $saved['shipping_methods']['ppl_home']['price_czk'] !== 120 ||
    $saved['terms_url'] !== '/simple-store/cs/obchodni-podminky' ||
    $saved['packeta']['api_key'] !== 'ABCDEF1234567890') {
    throw new RuntimeException('Checkout settings were not validated and loaded from the database.');
}
foreach ([['shipping_price' => array_replace($input['shipping_price'], ['ppl_home' => '-1'])],
    ['terms_url' => 'https://other.test/terms'],
    ['iban' => 'CZ0000000000000000000000'],
    ['packeta_api_key' => 'API-HESLO']] as $change) {
    try {
        $repo->save(array_replace($input, $change), '/simple-store/');
        throw new RuntimeException('Invalid settings were accepted.');
    } catch (InvalidArgumentException $expected) {
        if ($repo->load($fallback) != $saved) {
            throw new RuntimeException('Invalid settings overwrote the saved configuration.');
        }
    }
}
$disabled = $repo->save(array_replace($input, [
    'shipping_enabled' => array_replace($input['shipping_enabled'], ['gls_pickup' => '0']),
]), '/simple-store/');
if ((new ShippingPolicy($disabled['shipping_methods']))->quote('gls_pickup') !== null ||
    (new ShippingPolicy($disabled['shipping_methods']))->quote('ppl_home') !== 120) {
    throw new RuntimeException('Disabled carrier remained available or edited price was lost.');
}
$db->json = json_encode(['shipping_methods' => ['home' => [
    'label' => 'Starý kurýr', 'price_czk' => 149, 'requires_address' => true,
]]], JSON_THROW_ON_ERROR);
$migrated = $repo->load($fallback);
if (count($migrated['shipping_methods']) !== 9 ||
    isset($migrated['shipping_methods']['home']) ||
    $migrated['shipping_methods']['ppl_home']['price_czk'] !== 149) {
    throw new RuntimeException('Older saved courier price was not preserved.');
}
$local = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8080'];
if (!LocalCheckoutPreview::available($local, true) ||
    LocalCheckoutPreview::available($local, false) ||
    LocalCheckoutPreview::available($local, true, false) ||
    LocalCheckoutPreview::available(['REMOTE_ADDR' => '203.0.113.1', 'HTTP_HOST' => 'localhost'], true) ||
    LocalCheckoutPreview::available(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'eshop.example'], true)) {
    throw new RuntimeException('Local test orders must be limited to development on localhost.');
}
echo "Checkout settings tests passed.\n";
