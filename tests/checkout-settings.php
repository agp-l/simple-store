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
use SimpleStore\Checkout\BTCPayPaymentService;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\ShippingPolicy;

$example = require dirname(__DIR__) . '/config/checkout.example.php';
$db = new MeekroDB();
$repo = new CheckoutSettingsRepository($db);
$old = ['bank_transfer' => ['iban' => '', 'account_display' => '', 'recipient' => ''],
    'shipping_methods' => [], 'terms_url' => ''];
$fallback = CheckoutSettingsRepository::withDefaults($old, $example);
if ($fallback['bank_transfer']['account_display'] !== '' ||
    $fallback['shipping_methods']['ppl_home']['price_czk'] !== 99 ||
    $fallback['ppl']['widget_key'] !== '' ||
    $fallback['comgate'] !== ['enabled' => false, 'test' => true, 'merchant' => '',
        'secret' => '', 'return_base_url' => ''] ||
    $fallback['gopay'] !== ['enabled' => false, 'test' => true, 'goid' => '',
        'client_id' => '', 'client_secret' => '', 'return_base_url' => ''] ||
    $fallback['btcpay'] !== ['enabled' => false, 'server_url' => '', 'store_id' => '',
        'api_key' => '', 'webhook_secret' => '', 'return_base_url' => ''] ||
    $fallback['btc_prices_enabled'] !== true ||
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
    'ppl_widget_key' => 'public-ppl-key-123',
    'packeta_api_password' => 'private-test-password', 'packeta_sender' => 'Dobrodruzi',
    'comgate_enabled' => '1', 'comgate_test' => '1', 'comgate_merchant' => 'test-merchant',
    'comgate_secret' => 'comgate-test-secret',
    'comgate_return_base_url' => 'https://obchod.example/simple-store/',
    'gopay_enabled' => '1', 'gopay_test' => '1', 'gopay_goid' => '1234567890',
    'gopay_client_id' => 'sandbox-client', 'gopay_client_secret' => 'sandbox-secret',
    'gopay_return_base_url' => 'https://obchod.example/simple-store/',
    'btcpay_enabled' => '1', 'btcpay_server_url' => 'https://btcpay.example/pay/',
    'btcpay_store_id' => 'TestStore123', 'btcpay_api_key' => 'btcpay-test-key',
    'btcpay_webhook_secret' => 'btcpay-test-webhook-secret',
    'btcpay_return_base_url' => 'https://obchod.example/simple-store/',
    'btc_prices_enabled' => '1',
];
$input['shipping_price'] = array_combine(array_keys(ShippingPolicy::defaults()),
    array_values($input['shipping_price']));
$input['shipping_price']['ppl_home'] = '120';
$saved = $repo->save($input, '/simple-store/');
$loaded = $repo->load($fallback);
if ($loaded != $saved || $saved['bank_transfer']['iban'] !== $generated->snapshot()['iban'] ||
    $saved['shipping_methods']['ppl_home']['price_czk'] !== 120 ||
    $saved['terms_url'] !== '/simple-store/cs/obchodni-podminky' ||
    $saved['packeta']['api_key'] !== 'ABCDEF1234567890' ||
    $saved['packeta']['api_password'] !== 'private-test-password' ||
    $saved['comgate'] !== ['enabled' => true, 'test' => true,
        'merchant' => 'test-merchant', 'secret' => 'comgate-test-secret',
        'return_base_url' => 'https://obchod.example/simple-store'] ||
    $saved['gopay'] !== ['enabled' => true, 'test' => true, 'goid' => '1234567890',
        'client_id' => 'sandbox-client', 'client_secret' => 'sandbox-secret',
        'return_base_url' => 'https://obchod.example/simple-store'] ||
    $saved['btcpay'] !== ['enabled' => true, 'server_url' => 'https://btcpay.example/pay',
        'store_id' => 'TestStore123', 'api_key' => 'btcpay-test-key',
        'webhook_secret' => 'btcpay-test-webhook-secret',
        'return_base_url' => 'https://obchod.example/simple-store'] ||
    $saved['btc_prices_enabled'] !== true ||
    $saved['packeta']['sender'] !== 'Dobrodruzi') {
    throw new RuntimeException('Checkout settings were not validated and loaded from the database.');
}
if ($saved['ppl']['widget_key'] !== 'public-ppl-key-123') {
    throw new RuntimeException('PPL widget key was not persisted.');
}
$withoutBitcoin = $repo->save(array_replace($input, ['btc_prices_enabled' => '']),
    '/simple-store/', $saved);
if ($withoutBitcoin['btc_prices_enabled'] !== false ||
    $repo->load($fallback)['btc_prices_enabled'] !== false) {
    throw new RuntimeException('The storefront BTC display switch did not persist.');
}
$withoutNewPassword = $repo->save(array_replace($input,
    ['packeta_api_password' => '', 'comgate_secret' => '', 'gopay_client_secret' => '',
        'btcpay_api_key' => '', 'btcpay_webhook_secret' => '']),
    '/simple-store/', $saved);
if ($withoutNewPassword['packeta']['api_password'] !== 'private-test-password' ||
    $withoutNewPassword['comgate']['secret'] !== 'comgate-test-secret' ||
    $withoutNewPassword['gopay']['client_secret'] !== 'sandbox-secret' ||
    $withoutNewPassword['btcpay']['api_key'] !== 'btcpay-test-key' ||
    $withoutNewPassword['btcpay']['webhook_secret'] !== 'btcpay-test-webhook-secret') {
    throw new RuntimeException('Saving other settings erased an API secret.');
}
$cleared = $repo->save(array_replace($input, ['packeta_api_password' => '',
    'packeta_clear_password' => '1']), '/simple-store/', $saved);
if ($cleared['packeta']['api_password'] !== '') {
    throw new RuntimeException('The explicit password removal failed.');
}
$comgateCleared = $repo->save(array_replace($input, ['comgate_secret' => '',
    'comgate_clear_secret' => '1']), '/simple-store/', $saved);
if ($comgateCleared['comgate']['secret'] !== '' || !$comgateCleared['comgate']['enabled']) {
    throw new RuntimeException('Explicit Comgate secret removal changed the wrong setting.');
}
$gopayCleared = $repo->save(array_replace($input, ['gopay_client_secret' => '',
    'gopay_clear_secret' => '1']), '/simple-store/', $saved);
if ($gopayCleared['gopay']['client_secret'] !== '' || !$gopayCleared['gopay']['enabled'] ||
    $gopayCleared['comgate']['secret'] !== 'comgate-test-secret') {
    throw new RuntimeException('Explicit GoPay secret removal changed the wrong setting.');
}
$btcpayCleared = $repo->save(array_replace($input, ['btcpay_api_key' => '',
    'btcpay_webhook_secret' => '', 'btcpay_enabled' => '0',
    'btcpay_clear_api_key' => '1', 'btcpay_clear_webhook_secret' => '1']), '/simple-store/', $saved);
if ($btcpayCleared['btcpay']['api_key'] !== '' ||
    $btcpayCleared['btcpay']['webhook_secret'] !== '' || $btcpayCleared['btcpay']['enabled']) {
    throw new RuntimeException('Explicit BTCPay key removal changed the wrong setting.');
}
$repo->save($input, '/simple-store/');
foreach ([['shipping_price' => array_replace($input['shipping_price'], ['ppl_home' => '-1'])],
    ['terms_url' => 'https://other.test/terms'],
    ['iban' => 'CZ0000000000000000000000'],
    ['packeta_api_key' => 'API-HESLO'],
    ['comgate_merchant' => 'invalid merchant ID'],
    ['comgate_secret' => "invalid\nsecret"],
    ['comgate_return_base_url' => 'http://obchod.example/simple-store'],
    ['comgate_return_base_url' => 'https://localhost/simple-store'],
    ['comgate_return_base_url' => 'https://192.168.1.2/simple-store'],
    ['comgate_return_base_url' => 'https://obchod.example/another-site'],
    ['comgate_return_base_url' => 'https://obchod.example/simple-store?redirect=evil'],
    ['comgate_return_base_url' => ''],
    ['gopay_goid' => 'merchant-id'],
    ['gopay_goid' => '123 456'],
    ['gopay_client_id' => "bad\nclient"],
    ['gopay_client_secret' => "bad\nsecret"],
    ['gopay_return_base_url' => 'http://obchod.example/simple-store'],
    ['gopay_return_base_url' => 'https://localhost/simple-store'],
    ['gopay_return_base_url' => 'https://192.168.1.2/simple-store'],
    ['gopay_return_base_url' => 'https://obchod.example/another-site'],
    ['gopay_return_base_url' => 'https://obchod.example/simple-store?redirect=evil'],
    ['gopay_return_base_url' => ''],
    ['btcpay_server_url' => 'http://btcpay.example'],
    ['btcpay_server_url' => 'http://localhost.evil.test'],
    ['btcpay_server_url' => 'https://user@btcpay.example'],
    ['btcpay_server_url' => 'https://btcpay.example/pay/../other'],
    ['btcpay_server_url' => 'https://btcpay.example/pay?redirect=evil'],
    ['btcpay_server_url' => ''],
    ['btcpay_store_id' => 'bad store id'],
    ['btcpay_api_key' => "bad\nkey"],
    ['btcpay_webhook_secret' => "bad\nsecret"],
    ['btcpay_return_base_url' => 'http://obchod.example/simple-store'],
    ['btcpay_return_base_url' => 'http://192.168.1.2/simple-store'],
    ['btcpay_return_base_url' => 'https://obchod.example/another-site'],
    ['btcpay_return_base_url' => ''],
    ['ppl_widget_key' => 'invalid key with spaces']] as $change) {
    try {
        $repo->save(array_replace($input, $change), '/simple-store/');
        throw new RuntimeException('Invalid settings were accepted.');
    } catch (InvalidArgumentException $expected) {
        if ($repo->load($fallback) != $saved) {
            throw new RuntimeException('Invalid settings overwrote the saved configuration.');
        }
    }
}
$localBtcpay = $repo->saveSection('payment', array_replace($input, [
    'btcpay_server_url' => 'http://localhost:8080/BTCPayLite/',
    'btcpay_return_base_url' => 'http://localhost/simple-store/',
]), '/simple-store/', $saved);
if (!$localBtcpay['btcpay']['enabled'] ||
    $localBtcpay['btcpay']['server_url'] !== 'http://localhost:8080/BTCPayLite' ||
    $localBtcpay['btcpay']['return_base_url'] !== 'http://localhost/simple-store' ||
    $localBtcpay['btcpay']['api_key'] !== $saved['btcpay']['api_key'] ||
    !(new BTCPayPaymentService($db, $localBtcpay['btcpay']))->canInitiate()) {
    throw new RuntimeException('Local BTCPay configuration or saved secrets were lost.');
}
$disabled = $repo->save(array_replace($input, [
    'shipping_enabled' => array_replace($input['shipping_enabled'], ['gls_pickup' => '0']),
]), '/simple-store/');
if ((new ShippingPolicy($disabled['shipping_methods']))->quote('gls_pickup') !== null ||
    (new ShippingPolicy($disabled['shipping_methods']))->quote('ppl_home') !== 120) {
    throw new RuntimeException('Disabled carrier remained available or edited price was lost.');
}
$withoutGateway = $repo->save(array_replace($input,
    ['comgate_enabled' => '0', 'comgate_return_base_url' => '']), '/simple-store/');
if ($withoutGateway['comgate']['enabled'] || $withoutGateway['comgate']['return_base_url'] !== '') {
    throw new RuntimeException('A disabled Comgate gateway must not require a return URL.');
}
$withoutGoPay = $repo->save(array_replace($input,
    ['gopay_enabled' => '0', 'gopay_return_base_url' => '']), '/simple-store/');
if ($withoutGoPay['gopay']['enabled'] || $withoutGoPay['gopay']['return_base_url'] !== '') {
    throw new RuntimeException('A disabled GoPay gateway must not require a return URL.');
}
$withoutBtcpay = $repo->save(array_replace($input, [
    'btcpay_enabled' => '0', 'btcpay_server_url' => '', 'btcpay_store_id' => '',
    'btcpay_api_key' => '', 'btcpay_webhook_secret' => '', 'btcpay_return_base_url' => '',
]), '/simple-store/');
if ($withoutBtcpay['btcpay']['enabled'] || $withoutBtcpay['btcpay']['server_url'] !== '' ||
    $withoutBtcpay['btcpay']['return_base_url'] !== '') {
    throw new RuntimeException('A disabled BTCPay gateway must not require connection settings.');
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
// Each settings page updates its own fields while preserving unrelated values and secrets.
$section = $repo->saveSection('delivery',
    ['shipping_price' => array_fill_keys(array_keys(ShippingPolicy::defaults()), '81'),
        'shipping_enabled' => array_fill_keys(array_keys(ShippingPolicy::defaults()), '1')],
    '/simple-store/', $saved);
if ($section['shipping_methods']['gls_pickup']['price_czk'] !== 81 ||
    $section['bank_transfer'] !== $saved['bank_transfer'] ||
    $section['packeta'] !== $saved['packeta'] ||
    $section['comgate'] !== $saved['comgate']) {
    throw new RuntimeException('Delivery settings overwrote unrelated configuration.');
}
$payment = $repo->saveSection('payment', ['account_display' => '1265098001/5500',
    'iban' => '', 'recipient' => 'Test', 'payment_due_days' => '10',
    'comgate_merchant' => 'test-merchant', 'comgate_return_base_url' => 'https://obchod.example/simple-store/',
    'gopay_goid' => '1234567890', 'gopay_client_id' => 'sandbox-client',
    'gopay_return_base_url' => 'https://obchod.example/simple-store/',
    'btcpay_server_url' => 'https://btcpay.example/pay/',
    'btcpay_store_id' => 'TestStore123',
    'btcpay_return_base_url' => 'https://obchod.example/simple-store/'],
    '/simple-store/', $section);
if ($payment['comgate']['enabled'] || $payment['gopay']['enabled'] || $payment['btcpay']['enabled'] ||
    $payment['comgate']['secret'] !== $saved['comgate']['secret'] ||
    $payment['btcpay']['api_key'] !== $saved['btcpay']['api_key'] ||
    $payment['shipping_methods']['gls_pickup']['price_czk'] !== 81) {
    throw new RuntimeException('Payment settings reset unrelated values or lost secrets.');
}
$db->json = json_encode(['local_test_checkout' => true], JSON_THROW_ON_ERROR);
if (isset($repo->load($fallback)['local_test_checkout'])) {
    throw new RuntimeException('Obsolete local checkout setting was loaded.');
}
echo "Checkout settings tests passed.\n";
