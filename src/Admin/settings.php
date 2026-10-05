<?php
declare(strict_types=1);

use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\ShippingPolicy;
use SimpleStore\Accounting\MailSettingsRepository;
use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Accounting\TaxEvidenceRepository;

// admin.php has already verified the administrator session and form token.
$screen = 'settings';
$settingsError = '';
$settingsTabs = ['overview', 'delivery', 'carriers', 'payment', 'prices', 'mail', 'legal'];
$requestedTab = $_POST['tab'] ?? $_GET['tab'] ?? 'overview';
$settingsTab = is_string($requestedTab) && in_array($requestedTab, $settingsTabs, true)
    ? $requestedTab : 'overview';
$example = require __DIR__ . '/../../config/checkout.example.php';
$localFile = __DIR__ . '/../../config/checkout.php';
$fallback = CheckoutSettingsRepository::withDefaults(
    is_file($localFile) ? require $localFile : $example, $example);
$repository = new CheckoutSettingsRepository($db);
$settings = $repository->load($fallback);
$mailSettingsStore = new MailSettingsRepository($db);
$mailSettingsReady = $mailSettingsStore->installed();
$taxMailFrom = (string) ((new TaxEvidenceRepository($db))->settings()['mail_from'] ?? '');
$mailConfiguration = $mailSettingsStore->load($taxMailFrom);
$mailLegalWarnings = [];
if ($settingsTab === 'mail') {
    if ((string) $mailConfiguration['settings']['public_base_url'] === '') {
        $mailLegalWarnings[] = 'Vyplň veřejnou HTTPS adresu obchodu, aby v potvrzení objednávky fungovaly odkazy na právní stránky.';
    }
    if (!(new \SimpleStore\Accounting\OrderLegalDocuments($db))->installed()) {
        $mailLegalWarnings[] = 'Aktualizuj SQL tabulky, aby se u každé objednávky uložilo znění obchodních podmínek platné při objednání.';
    }
    if ((int) $db->queryFirstField(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
        'shop_mail_outbox', 'terms_attachment') === 0) {
        $mailLegalWarnings[] = 'Aktualizuj SQL tabulky pro přiložení podmínek k potvrzení objednávky.';
    }
    $termsPage = (new \SimpleStore\Content\ContentRepository($db))->findPublished('page', 'obchodni-podminky', 'cs');
    if ($termsPage === null) {
        $mailLegalWarnings[] = 'České obchodní podmínky nejsou publikované. Potvrzovací e-mail proto nemůže obsahovat jejich odkaz ani přílohu.';
    } else {
        try {
            $termsText = \SimpleStore\Accounting\OrderLegalDocuments::bodyText((string) $termsPage['body']);
            if ($termsText === '') {
                $mailLegalWarnings[] = 'Publikované obchodní podmínky nemají žádný text.';
            }
            if (str_contains($termsText, '[DOPLNIT')) {
                $mailLegalWarnings[] = 'Publikované obchodní podmínky ještě obsahují značku [DOPLNIT]. Oprav skutečné údaje před ostrým prodejem.';
            }
        } catch (InvalidArgumentException $error) {
            $mailLegalWarnings[] = $error->getMessage();
        }
    }
}
$mailPreview = null;
$previewCode = $_GET['preview'] ?? null;
if ($settingsTab === 'mail' && is_string($previewCode) && isset(MailSettingsRepository::EVENTS[$previewCode])) {
    $previewOrder = ['order_number' => 'DB-2026-0001', 'total_czk' => 1079, 'shipping_czk' => 79,
        'shipping' => ['label' => 'GLS na adresu', 'recipient' => 'Eva Nová'],
        'items' => [['name' => 'Lehký batoh', 'quantity' => 1, 'unit_price_czk' => 1000]],
        'payment_method' => 'bank_transfer', 'payment_details' => ['account_display' => '123456789/0000'],
        'variable_symbol' => '20260001', 'payment_due_at' => '2026-10-15'];
    $mailPreview = \SimpleStore\Accounting\OrderEmailComposer::compose($previewCode,
        $previewOrder, $mailConfiguration['templates'][$previewCode],
        ['number' => 'GLS123456789', 'url' => 'https://example.com/sledovani'],
        '', (string) $mailConfiguration['settings']['public_base_url'],
        $termsPage === null ? [] : ['obchodni-podminky']);
}
$shippingCatalog = ShippingPolicy::defaults();
$form = [
    'btc_prices_enabled' => ($settings['btc_prices_enabled'] ?? true) === true ? '1' : '0',
    'shipping_price' => [],
    'shipping_enabled' => [],
    'account_display' => $settings['bank_transfer']['account_display'] ?? '',
    'iban' => $settings['bank_transfer']['iban'] ?? '',
    'recipient' => $settings['bank_transfer']['recipient'] ?? '',
    'payment_due_days' => (string) ($settings['bank_transfer']['payment_due_days'] ?? 7),
    'terms_url' => $settings['terms_url'] ?? '',
    'packeta_api_key' => $settings['packeta']['api_key'] ?? '',
    'ppl_widget_key' => $settings['ppl']['widget_key'] ?? '',
    'packeta_sender' => $settings['packeta']['sender'] ?? '',
    'comgate_merchant' => $settings['comgate']['merchant'] ?? '',
    'comgate_return_base_url' => $settings['comgate']['return_base_url'] ?? '',
    'comgate_enabled' => ($settings['comgate']['enabled'] ?? false) === true ? '1' : '0',
    'comgate_test' => ($settings['comgate']['test'] ?? true) === true ? '1' : '0',
    'gopay_goid' => $settings['gopay']['goid'] ?? '',
    'gopay_client_id' => $settings['gopay']['client_id'] ?? '',
    'gopay_return_base_url' => $settings['gopay']['return_base_url'] ?? '',
    'gopay_enabled' => ($settings['gopay']['enabled'] ?? false) === true ? '1' : '0',
    'gopay_test' => ($settings['gopay']['test'] ?? true) === true ? '1' : '0',
    'btcpay_enabled' => ($settings['btcpay']['enabled'] ?? false) === true ? '1' : '0',
    'btcpay_server_url' => $settings['btcpay']['server_url'] ?? '',
    'btcpay_store_id' => $settings['btcpay']['store_id'] ?? '',
    'btcpay_return_base_url' => $settings['btcpay']['return_base_url'] ?? '',
];
$packetaPasswordConfigured = ($settings['packeta']['api_password'] ?? '') !== '';
$comgateSecretConfigured = ($settings['comgate']['secret'] ?? '') !== '';
$gopaySecretConfigured = ($settings['gopay']['client_secret'] ?? '') !== '';
$btcpayApiKeyConfigured = ($settings['btcpay']['api_key'] ?? '') !== '';
$btcpayWebhookSecretConfigured = ($settings['btcpay']['webhook_secret'] ?? '') !== '';
$btcpayDraftSecrets = [];
foreach ($shippingCatalog as $code => $definition) {
    $saved = $settings['shipping_methods'][$code] ?? $definition;
    $form['shipping_price'][$code] = (string) ($saved['price_czk'] ?? $definition['price_czk']);
    $form['shipping_enabled'][$code] = ($saved['enabled'] ?? true) === true ? '1' : '0';
}
if ($method === 'POST') {
    try {
        if (($_POST['action'] ?? null) === 'save-mail-settings') {
            $mailSettingsStore->save($_POST);
            header('Location: ' . $adminUrl . '?section=settings&tab=mail&saved=1', true, 303);
            exit;
        }
        if (($_POST['action'] ?? null) === 'mail-test') {
            if (!$mailSettingsReady) throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky.');
            $mailQueue = new OrderMailQueue($db);
            $recipient = $_POST['test_recipient'] ?? null;
            if (!is_string($recipient)) throw new InvalidArgumentException('Zadej e-mail příjemce testu.');
            $mailId = $mailQueue->enqueueTest($recipient);
            $sent = $mailQueue->dispatch($mailId, $taxMailFrom);
            header('Location: ' . $adminUrl . '?section=settings&tab=mail&test=' . ($sent ? 'sent' : 'failed'), true, 303);
            exit;
        }
        if (($_POST['action'] ?? null) !== 'save-checkout-settings') {
            throw new InvalidArgumentException('Neznámá akce nastavení.');
        }
        $repository->saveSection($settingsTab, $_POST, $basePath, $settings);
        header('Location: ' . $adminUrl . '?section=settings&tab=' . $settingsTab . '&saved=1', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $settingsError = $exception->getMessage();
        if ($settingsTab === 'mail') {
            foreach (['from_email', 'from_name', 'reply_to', 'public_base_url', 'admin_recovery_email',
                'smtp_host', 'smtp_port', 'smtp_security', 'smtp_username'] as $key) {
                if (is_string($_POST[$key] ?? null)) $mailConfiguration['settings'][$key] = $_POST[$key];
            }
            $mailConfiguration['settings']['automatic_enabled'] = ($_POST['automatic_enabled'] ?? null) === '1';
            foreach ($mailConfiguration['templates'] as $code => &$entry) {
                if (is_array($_POST['templates'][$code] ?? null)) {
                    foreach (['subject', 'message'] as $key) {
                        if (is_string($_POST['templates'][$code][$key] ?? null)) $entry[$key] = $_POST['templates'][$code][$key];
                    }
                    $entry['enabled'] = ($_POST['templates'][$code]['enabled'] ?? null) === '1';
                }
            }
            unset($entry);
        }
        foreach ($form as $key => $value) {
            if (is_string($_POST[$key] ?? null)) {
                $form[$key] = $_POST[$key];
            }
        }
        $form['btc_prices_enabled'] = ($_POST['btc_prices_enabled'] ?? null) === '1' ? '1' : '0';
        $form['comgate_enabled'] = ($_POST['comgate_enabled'] ?? null) === '1' ? '1' : '0';
        $form['comgate_test'] = ($_POST['comgate_test'] ?? null) === '1' ? '1' : '0';
        $form['gopay_enabled'] = ($_POST['gopay_enabled'] ?? null) === '1' ? '1' : '0';
        $form['gopay_test'] = ($_POST['gopay_test'] ?? null) === '1' ? '1' : '0';
        $form['btcpay_enabled'] = ($_POST['btcpay_enabled'] ?? null) === '1' ? '1' : '0';
        foreach ($shippingCatalog as $code => $definition) {
            if (is_string($_POST['shipping_price'][$code] ?? null)) {
                $form['shipping_price'][$code] = $_POST['shipping_price'][$code];
            }
            $form['shipping_enabled'][$code] = ($_POST['shipping_enabled'][$code] ?? null) === '1' ? '1' : '0';
        }
    } catch (RuntimeException $exception) {
        http_response_code(503);
        $settingsError = $exception->getMessage();
    }
    // Keep unsaved secrets only in this administrator response after a failed submission.
    // Never put them into a redirect URL, cookie, log, or persistent session.
    if ($settingsTab === 'payment' && $settingsError !== '' &&
        ($_POST['action'] ?? null) === 'save-checkout-settings') {
        foreach (['btcpay_api_key', 'btcpay_webhook_secret'] as $field) {
            if (is_string($_POST[$field] ?? null) && strlen($_POST[$field]) <= 512) {
                $btcpayDraftSecrets[$field] = $_POST[$field];
            }
        }
    }
}
