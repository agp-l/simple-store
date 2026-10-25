<?php
declare(strict_types=1);

use SimpleStore\Product\PurchasePartnerTransfer;

$partnerBackupError = ''; $partnerBackupJson = ''; $partnerBackupPlan = null; $partnerBackupToken = '';
$partnerBackupUrl = $adminUrl . '?section=purchase-partners';
$partnerTransfer = new PurchasePartnerTransfer($db);
try {
    if ($method === 'GET' && ($_GET['backup'] ?? '') === 'export') {
        $json = PurchasePartnerTransfer::json($partnerTransfer->snapshot());
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="prodavajici-' . gmdate('Y-m-d') . '.json"');
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        echo $json; exit;
    }
    if ($method === 'POST' && in_array($action, ['purchase-partner-preview', 'purchase-partner-import'], true)) {
        $partnerBackupJson = is_string($_POST['backup_json'] ?? null) ? $_POST['backup_json'] : '';
        $upload = $_FILES['backup_file'] ?? null;
        if ($action === 'purchase-partner-preview' && is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['name'] ?? null) ||
                strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'json' ||
                !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name']) ||
                !is_int($upload['size'] ?? null) || $upload['size'] > PurchasePartnerTransfer::LIMIT) {
                throw new InvalidArgumentException('Vyber soubor .json do 2 MiB. Nahrání souboru se nezdařilo.');
            }
            $partnerBackupJson = (string) file_get_contents($upload['tmp_name'], false, null, 0, PurchasePartnerTransfer::LIMIT + 1);
        }
        $partnerBackupData = PurchasePartnerTransfer::decode($partnerBackupJson);
        $partnerBackupJson = PurchasePartnerTransfer::json($partnerBackupData);
        if ($action === 'purchase-partner-import') {
            $postedToken = $_POST['backup_token'] ?? null;
            if (!is_string($postedToken)) throw new InvalidArgumentException('Nejdřív zobraz náhled importu.');
            $partnerTransfer->apply($partnerBackupData, $postedToken, $csrf);
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($partnerBackupUrl . '&imported=1#partner-backup'), true, 303);
            exit;
        }
        $partnerBackupPlan = $partnerTransfer->plan($partnerBackupData);
        $partnerBackupToken = PurchasePartnerTransfer::token($partnerBackupData, $partnerBackupPlan['revision'], $csrf);
    }
} catch (Throwable $exception) {
    $partnerBackupPlan = null;
    $partnerBackupError = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Import se neuložil. Zkontroluj databázi a zkus nový náhled.';
    if (!$exception instanceof InvalidArgumentException) error_log((string) $exception);
    http_response_code($exception instanceof InvalidArgumentException ? 422 : 503);
}
if (strlen($partnerBackupJson) > PurchasePartnerTransfer::LIMIT) $partnerBackupJson = '';
