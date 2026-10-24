<?php
declare(strict_types=1);

use SimpleStore\Admin\ConfigurationBackup;

// Included only from authenticated settings, menus or categories controllers.
$backupError = ''; $backupPreview = null; $backupJson = ''; $backupToken = '';
$backupService = new ConfigurationBackup($db, $site['languages'], require __DIR__ . '/../../config/menus.php');
$backupReturn = $backupKind === 'navigation' ? ['section' => $screen, 'language' => $language] :
    ['section' => 'settings', 'tab' => $backupKind === 'site-texts' ? 'appearance' : 'mail'];
if ($screen === 'menus') $backupReturn['slot'] = $slot;
$backupUrl = $adminUrl . '?' . http_build_query($backupReturn);
$backupAction = $_POST['action'] ?? '';
try {
    if ($method === 'GET' && ($_GET['backup'] ?? '') === 'export') {
        $json = ConfigurationBackup::json($backupService->snapshot($backupKind));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="simple-store-' . $backupKind . '-' . gmdate('Y-m-d') . '.json"');
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        echo $json; exit;
    }
    if ($method === 'POST' && in_array($backupAction, ['configuration-preview', 'configuration-apply'], true)) {
        if (($_POST['backup_kind'] ?? '') !== $backupKind) throw new InvalidArgumentException('Vyber odpovídající oblast zálohy.');
        $backupJson = is_string($_POST['backup_json'] ?? null) ? $_POST['backup_json'] : '';
        $upload = $_FILES['backup_file'] ?? null;
        if ($backupAction === 'configuration-preview' && is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($upload['error'] ?? null) !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Soubor se nepodařilo nahrát. Vyber JSON do 2 MiB.');
            if (!is_string($upload['name'] ?? null) || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'json' ||
                !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name']) ||
                ($upload['size'] ?? ConfigurationBackup::LIMIT + 1) > ConfigurationBackup::LIMIT) throw new InvalidArgumentException('Vyber soubor .json do 2 MiB.');
            $backupJson = (string) file_get_contents($upload['tmp_name'], false, null, 0, ConfigurationBackup::LIMIT + 1);
        }
        $backupData = $backupService->decode($backupJson, $backupKind);
        $backupPreview = $backupService->plan($backupData);
        $backupJson = ConfigurationBackup::json($backupData);
        $backupToken = ConfigurationBackup::token($backupData, $backupPreview['version'], $csrf);
        if ($backupAction === 'configuration-apply') {
            $postedToken = $_POST['backup_token'] ?? null;
            if (!is_string($postedToken)) throw new InvalidArgumentException('Nejdřív zobraz náhled importu.');
            $backupService->apply($backupData, $postedToken, $csrf);
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($backupUrl . '&imported=1#configuration-backup'), true, 303);
            exit;
        }
    }
} catch (\Throwable $error) {
    $backupPreview = null;
    $backupError = $error instanceof InvalidArgumentException ? $error->getMessage() : 'Zálohu se nepodařilo zpracovat. Ověř databázi a zkus to znovu; import se neuložil.';
    if (!$error instanceof InvalidArgumentException) error_log('Configuration backup: ' . $error->getMessage());
    http_response_code($error instanceof InvalidArgumentException ? 422 : 503);
}
if (strlen($backupJson) > ConfigurationBackup::LIMIT) $backupJson = '';
