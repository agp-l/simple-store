<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/src/bootstrap.php';
set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
use SimpleStore\Accounting\{MailContent, MailSettingsRepository, MailPreview};
$basePath = '/'; $adminUrl = '/admin.php'; $csrf = 'test-token';
$site = ['default_language' => 'cs', 'languages' => ['cs']];
$screen = 'settings'; $settingsTab = 'mail'; $settingsError = ''; $form = [];
$mailSettingsReady = true; $mailLegalWarnings = []; $mailPreviewIsDraft = false;
$mailConfiguration = ['settings' => ['from_name' => 'dobrodruzi.cz', 'from_email' => 'obchod@example.com',
    'reply_to' => 'podpora@example.com', 'public_base_url' => 'https://example.com', 'admin_recovery_email' => 'spravce@example.com',
    'automatic_enabled' => true, 'smtp_host' => '', 'smtp_port' => 587, 'smtp_security' => 'starttls',
    'smtp_username' => '', 'smtp_password_encrypted' => 'encrypted-not-a-real-secret', 'copy' => MailContent::defaults()], 'templates' => []];
foreach (MailContent::definitions() as $code => $definition) {
    $mailConfiguration['templates'][$code] = $definition + ['enabled' => !in_array($code, ['processing', 'ready_to_ship', 'completed'], true)];
}
$previewCode = $argv[1] ?? 'order'; $previewMode = $argv[2] ?? 'mixed';
$mailPreview = MailPreview::compose($previewCode, $previewMode, $mailConfiguration);
if (($argv[3] ?? '') === 'email') { echo $mailPreview['html']; exit; }
require dirname(__DIR__, 2) . '/view/admin/layout.php';
