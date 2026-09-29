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

$example = require dirname(__DIR__) . '/config/checkout.example.php';
$repo = new CheckoutSettingsRepository(new MeekroDB());
$old = ['bank_transfer' => ['iban' => '', 'account_display' => '', 'recipient' => ''],
    'shipping_methods' => [], 'terms_url' => ''];
$fallback = CheckoutSettingsRepository::withDefaults($old, $example);
if ($fallback['bank_transfer']['account_display'] !== '' ||
    $fallback['shipping_methods']['home']['price_czk'] !== 99 ||
    $repo->load($fallback) !== $fallback) {
    throw new RuntimeException('Old private checkout settings did not receive the new defaults.');
}
$generated = new BankTransferPayment('', '1265098001/5500', 'Test');
if ($generated->snapshot()['iban'] !== 'CZ5855000000001265098001') {
    throw new RuntimeException('The configured bank account did not yield a valid Czech IBAN.');
}
$input = ['home_label' => 'Kurýr', 'home_price_czk' => '120',
    'account_display' => '1265098001/5500', 'iban' => '', 'recipient' => 'Test',
    'payment_due_days' => '10', 'terms_url' => '/simple-store/cs/obchodni-podminky',
    'local_test_checkout' => '1'];
$saved = $repo->save($input, '/simple-store/');
$loaded = $repo->load($fallback);
if ($loaded !== $saved || $saved['bank_transfer']['iban'] !== $generated->snapshot()['iban'] ||
    $saved['shipping_methods']['home']['price_czk'] !== 120 ||
    $saved['terms_url'] !== '/simple-store/cs/obchodni-podminky') {
    throw new RuntimeException('Checkout settings were not validated and loaded from the database.');
}
foreach ([['home_price_czk' => '-1'], ['terms_url' => 'https://other.test/terms'],
    ['iban' => 'CZ0000000000000000000000']] as $change) {
    try {
        $repo->save(array_replace($input, $change), '/simple-store/');
        throw new RuntimeException('Invalid settings were accepted.');
    } catch (InvalidArgumentException $expected) {
        if ($repo->load($fallback) !== $saved) {
            throw new RuntimeException('Invalid settings overwrote the saved configuration.');
        }
    }
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
