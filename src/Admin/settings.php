<?php
declare(strict_types=1);

use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\ShippingPolicy;
use SimpleStore\Accounting\MailSettingsRepository;
use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Content\SiteCopyRepository;

// admin.php has already verified the administrator session and form token.
$screen = 'settings';
$settingsError = '';
$settingsTabs = ['overview', 'accounts', 'products', 'appearance', 'delivery', 'carriers', 'payment', 'prices', 'mail', 'legal'];
$requestedTab = $_POST['tab'] ?? $_GET['tab'] ?? 'overview';
$settingsTab = is_string($requestedTab) && in_array($requestedTab, $settingsTabs, true)
    ? $requestedTab : 'overview';
if ($settingsTab === 'accounts') { require __DIR__.'/settings-google.php'; return; }
if ($settingsTab === 'products') {
    require __DIR__ . '/settings-products.php';
    return;
}
$requestedPaymentArea = $_POST['payment_area'] ?? $_GET['area'] ?? '';
$paymentArea = $settingsTab === 'payment' && is_string($requestedPaymentArea) &&
    in_array($requestedPaymentArea, ['bank', 'fio', 'comgate', 'gopay', 'btcpay'], true)
    ? $requestedPaymentArea : ($settingsTab === 'payment' ? 'bank' : '');
$copyLanguageInput = $_POST['language'] ?? $_GET['language'] ?? $site['default_language'];
$copyLanguage = is_string($copyLanguageInput) && in_array($copyLanguageInput, $site['languages'], true)
    ? $copyLanguageInput : $site['default_language'];
if (in_array($settingsTab, ['appearance', 'mail'], true)) {
    $backupKind = $settingsTab === 'appearance' ? 'site-texts' : 'email-texts';
    require __DIR__ . '/configuration-transfer.php';
}
$siteCopyStore = new SiteCopyRepository($db);
$siteCopyValues = $siteCopyStore->load($copyLanguage);
$example = require __DIR__ . '/../../config/checkout.example.php';
$localFile = __DIR__ . '/../../config/checkout.php';
$fallback = CheckoutSettingsRepository::withDefaults(
    is_file($localFile) ? require $localFile : $example, $example);
$repository = new CheckoutSettingsRepository($db);
$settings = $repository->load($fallback);
$deliveryEstimateForm = \SimpleStore\Product\DeliveryEstimate::configuration($settings['delivery_estimate'] ?? null);
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
$mailPreviewIsDraft = false;
$previewCode = $_POST['preview_code'] ?? $_GET['preview'] ?? null;
$previewMode = $_POST['preview_mode'] ?? $_GET['preview_mode'] ?? 'own';
if (!is_string($previewMode) || !in_array($previewMode, ['own', 'assisted', 'mixed'], true)) $previewMode = 'own';
$shippingCatalog = ShippingPolicy::defaults();
$form = [
    'btc_prices_enabled' => ($settings['btc_prices_enabled'] ?? true) === true ? '1' : '0',
    'shipping_price' => [],
    'shipping_enabled' => [],
    'account_display' => $settings['bank_transfer']['account_display'] ?? '',
    'bank_source' => $settings['bank_transfer']['bank_source'] ??
        (str_ends_with((string) ($settings['bank_transfer']['account_display'] ?? ''), '/2010') ? 'fio' : 'manual'),
    'bank_transfer_enabled' => ($settings['bank_transfer']['enabled'] ?? true) ? '1' : '0',
    'fio_enabled' => ($settings['fio_bank']['enabled'] ?? false) ? '1' : '0',
    'fio_account_display' => $settings['fio_bank']['account_display'] ?? '',
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
$fioTokenConfigured = ($settings['fio_bank']['token'] ?? '') !== '';
$fioDraftToken = '';
$fioCronKey = '';
$fioAccountDifferent = !empty($settings['fio_bank']['enabled']) &&
    \SimpleStore\Checkout\FioTransferMatcher::canonicalAccount(
        (string) ($settings['fio_bank']['account_display'] ?? '')) !==
    \SimpleStore\Checkout\FioTransferMatcher::canonicalAccount(
        (string) ($settings['bank_transfer']['account_display'] ?? ''));
$paymentDraftSecrets = [];
foreach ($shippingCatalog as $code => $definition) {
    $saved = $settings['shipping_methods'][$code] ?? $definition;
    $form['shipping_price'][$code] = (string) ($saved['price_czk'] ?? $definition['price_czk']);
    $form['shipping_enabled'][$code] = ($saved['enabled'] ?? true) === true ? '1' : '0';
}
if ($method === 'POST' && !in_array($_POST['action'] ?? '', ['configuration-preview', 'configuration-apply'], true)) {
    try {
        if (($_POST['action'] ?? null) === 'fio-cron-rotate') {
            if ($settingsTab !== 'payment') throw new InvalidArgumentException('Neplatná stránka nastavení Fio.');
            $fioCronKey = $repository->rotateFioCronKey($settings);
            header('Cache-Control: private, no-store');
        } elseif (($_POST['action'] ?? null) === 'save-site-copy') {
            if ($settingsTab !== 'appearance' || $copyLanguageInput !== $copyLanguage) {
                throw new InvalidArgumentException('Vyber platný jazyk a stránku vzhledu.');
            }
            $siteCopyStore->save($copyLanguage, $_POST);
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=settings&tab=appearance&language=' . $copyLanguage . '&saved=1'), true, 303);
            exit;
        }
        if (($_POST['action'] ?? null) === 'preview-mail') {
            if ($settingsTab !== 'mail') throw new InvalidArgumentException('Otevři nastavení e-mailů.');
            $mailConfiguration = MailSettingsRepository::withDraft($mailConfiguration, $_POST);
            $mailPreviewIsDraft = true;
            $mailConfiguration['settings']['copy'] = MailSettingsRepository::validateCopy($mailConfiguration['settings']['copy']);
            $mailConfiguration['templates'] = MailSettingsRepository::validateTemplates($_POST['templates'] ?? null, $mailConfiguration['templates']);
            if (!is_string($previewCode) || !isset(\SimpleStore\Accounting\MailContent::definitions()[$previewCode])) {
                throw new InvalidArgumentException('Vyber šablonu pro náhled.');
            }
        }
        if (($_POST['action'] ?? null) === 'save-mail-settings') {
            $adminEmail = $_POST['admin_recovery_email'] ?? null;
            if (!is_string($adminEmail) || filter_var(trim($adminEmail), FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Vyplň platný kontaktní e-mail obchodu.');
            }
            $mailSettingsStore->save($_POST);
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=settings&tab=mail&saved=1'), true, 303);
            exit;
        }
        if (($_POST['action'] ?? null) === 'mail-test') {
            if (!$mailSettingsReady) throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky.');
            $mailQueue = new OrderMailQueue($db);
            $recipient = $_POST['test_recipient'] ?? null;
            if (!is_string($recipient)) throw new InvalidArgumentException('Zadej e-mail příjemce testu.');
            $mailId = $mailQueue->enqueueTest($recipient);
            $sent = $mailQueue->dispatch($mailId, $taxMailFrom);
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=settings&tab=mail&test=' . ($sent ? 'sent' : 'failed')), true, 303);
            exit;
        }
        if (!in_array($_POST['action'] ?? null, ['save-checkout-settings', 'preview-mail'], true) && $fioCronKey === '') {
            throw new InvalidArgumentException('Neznámá akce nastavení.');
        }
        if ($fioCronKey === '' && ($_POST['action'] ?? null) === 'save-checkout-settings') {
            $repository->saveSection($settingsTab, $_POST, $basePath, $settings);
            $paymentReturn = $settingsTab === 'payment' && in_array($paymentArea,
                ['bank', 'fio', 'comgate', 'gopay', 'btcpay'], true)
                ? '&area=' . $paymentArea . '#settings-' . $paymentArea : '';
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=settings&tab=' . $settingsTab .
                '&saved=1' . $paymentReturn), true, 303);
            exit;
        }
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $settingsError = $exception->getMessage();
        if ($settingsTab === 'appearance') {
            foreach ($siteCopyValues as $key => $value) {
                if (is_string($_POST[$key] ?? null)) $siteCopyValues[$key] = $_POST[$key];
            }
        }
        if ($settingsTab === 'mail') {
            $mailConfiguration = MailSettingsRepository::withDraft($mailConfiguration, $_POST);
            $mailPreviewIsDraft = true;
        }
    } catch (RuntimeException $exception) {
        http_response_code(503);
        $settingsError = $exception->getMessage();
    }
    if ($settingsError !== '' && ($_POST['action'] ?? null) === 'save-checkout-settings') {
        if ($settingsTab === 'prices' && is_array($_POST['delivery_estimate'] ?? null)) {
            foreach ($deliveryEstimateForm as $key => $value) {
                if ($key !== 'enabled' && is_string($_POST['delivery_estimate'][$key] ?? null)) $deliveryEstimateForm[$key] = $_POST['delivery_estimate'][$key];
            }
            $deliveryEstimateForm['enabled'] = ($_POST['delivery_estimate']['enabled'] ?? null) === '1';
        }
        foreach ($form as $key => $value) {
            if (is_string($value) && is_string($_POST[$key] ?? null)) $form[$key] = $_POST[$key];
        }
        $checkboxes = match ($settingsTab) {
            'payment' => match ($paymentArea) {
                'bank' => ['bank_transfer_enabled'], 'fio' => ['fio_enabled'],
                'comgate' => ['comgate_enabled', 'comgate_test'],
                'gopay' => ['gopay_enabled', 'gopay_test'],
                'btcpay' => ['btcpay_enabled'],
                default => ['bank_transfer_enabled', 'fio_enabled', 'comgate_enabled',
                    'comgate_test', 'gopay_enabled', 'gopay_test', 'btcpay_enabled'],
            },
            'prices' => ['btc_prices_enabled'],
            default => [],
        };
        foreach ($checkboxes as $key) $form[$key] = ($_POST[$key] ?? null) === '1' ? '1' : '0';
        if ($settingsTab === 'delivery') {
            foreach ($shippingCatalog as $code => $definition) {
                if (is_string($_POST['shipping_price'][$code] ?? null)) {
                    $form['shipping_price'][$code] = $_POST['shipping_price'][$code];
                }
                $form['shipping_enabled'][$code] =
                    ($_POST['shipping_enabled'][$code] ?? null) === '1' ? '1' : '0';
            }
        }
        // Secret drafts live only in this failed administrator response, never in a URL or session.
        if ($settingsTab === 'payment') {
            foreach (['fio_token', 'comgate_secret', 'gopay_client_secret',
                'btcpay_api_key', 'btcpay_webhook_secret'] as $field) {
                if (is_string($_POST[$field] ?? null) && strlen($_POST[$field]) <= 512) {
                    $paymentDraftSecrets[$field] = $_POST[$field];
                }
            }
            $fioDraftToken = $paymentDraftSecrets['fio_token'] ?? '';
        }
    }
}

if ($settingsTab === 'mail' && $settingsError === '' && is_string($previewCode) &&
    isset(\SimpleStore\Accounting\MailContent::definitions()[$previewCode])) {
    $mailPreview = \SimpleStore\Accounting\MailPreview::compose($previewCode, $previewMode, $mailConfiguration);
}
