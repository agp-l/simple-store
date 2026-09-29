<?php
declare(strict_types=1);

use SimpleStore\Checkout\CheckoutSettingsRepository;

// admin.php has already verified the administrator session and form token.
$screen = 'settings';
$settingsError = '';
$example = require __DIR__ . '/../../config/checkout.example.php';
$localFile = __DIR__ . '/../../config/checkout.php';
$fallback = CheckoutSettingsRepository::withDefaults(
    is_file($localFile) ? require $localFile : $example, $example);
$repository = new CheckoutSettingsRepository($db);
$settings = $repository->load($fallback);
$form = [
    'home_label' => $settings['shipping_methods']['home']['label'] ?? 'Doručení na adresu',
    'home_price_czk' => (string) ($settings['shipping_methods']['home']['price_czk'] ?? 99),
    'account_display' => $settings['bank_transfer']['account_display'] ?? '',
    'iban' => $settings['bank_transfer']['iban'] ?? '',
    'recipient' => $settings['bank_transfer']['recipient'] ?? '',
    'payment_due_days' => (string) ($settings['bank_transfer']['payment_due_days'] ?? 7),
    'terms_url' => $settings['terms_url'] ?? '',
    'local_test_checkout' => ($settings['local_test_checkout'] ?? true) === true ? '1' : '0',
];
if ($method === 'POST') {
    try {
        $repository->save($_POST, $basePath);
        header('Location: ' . $adminUrl . '?section=settings&saved=1', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $settingsError = $exception->getMessage();
        foreach ($form as $key => $value) {
            if (is_string($_POST[$key] ?? null)) {
                $form[$key] = $_POST[$key];
            }
        }
        $form['local_test_checkout'] = ($_POST['local_test_checkout'] ?? null) === '1' ? '1' : '0';
    }
}
