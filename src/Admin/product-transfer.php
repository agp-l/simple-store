<?php
declare(strict_types=1);

use SimpleStore\Product\ProductBulkTransfer;
use SimpleStore\Product\ProductImportJob;

// This handler runs only after the shared admin authentication and CSRF checks.
$transfer = new ProductBulkTransfer($db, $repository, $site['languages']);
$transferError = '';
$bulkJson = is_string($_POST['bulk_json'] ?? null) ? $_POST['bulk_json'] : '';
$bulkRestore = ($_POST['import_mode'] ?? '') === 'restore';
$bulkStock = ($_POST['include_stock'] ?? '') === '1';
$bulkState = null;
$exportRows = [];
$exportLanguageInput = $_POST['export_language'] ?? $_GET['export_language'] ?? '';
$exportLanguage = is_string($exportLanguageInput) ? $exportLanguageInput : '';
$exportCategoryInput = $_POST['export_category'] ?? $_GET['export_category'] ?? '';
$exportCategory = is_string($exportCategoryInput) ? $exportCategoryInput : '';
$exportSearchInput = $_POST['export_search'] ?? $_GET['export_search'] ?? '';
$exportSearch = is_string($exportSearchInput) ? $exportSearchInput : '';

try {
    if ($method === 'POST' && $action === 'product-bulk-prepare') {
        if (($_FILES['json_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = $_FILES['json_file'];
            if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
                throw new InvalidArgumentException('JSON se nenahrál. Zkontroluj limity nahrávání na hostingu.');
            }
            if (filesize($upload['tmp_name']) > ProductBulkTransfer::LIMIT) throw new InvalidArgumentException('JSON může mít nejvýše 32 MiB.');
            if (trim($bulkJson) !== '') throw new InvalidArgumentException('Vyber soubor, nebo vlož text JSON; ne obojí současně. Text zůstal zachován.');
            $bulkJson = (string) file_get_contents($upload['tmp_name']);
        }
        $items = $transfer->prepare($bulkJson, $bulkRestore, $bulkStock);
        ProductImportJob::expire();
        $token = ProductImportJob::fromItems($items);
        if (isset($_SESSION['product_json_job'])) (new ProductImportJob($_SESSION['product_json_job']))->remove();
        $_SESSION['product_json_job'] = $token;
        header('Location: ' . $adminUrl . '?section=product-import&tab=bulk#import-results', true, 303);
        exit;
    }
    if ($method === 'POST' && in_array($action, ['product-bulk-step', 'product-bulk-clear'], true)) {
        $job = new ProductImportJob($_SESSION['product_json_job'] ?? '');
        if ($action === 'product-bulk-clear') {
            $job->remove(); unset($_SESSION['product_json_job']);
        } else { $job->stepJson($transfer); }
    }
    if (isset($_SESSION['product_json_job'])) $bulkState = (new ProductImportJob($_SESSION['product_json_job']))->state();
    if ($importTab === 'export' || $action === 'product-bulk-export') {
        $exportRows = $transfer->rows($exportLanguage, $exportCategory, $exportSearch);
        if ($method === 'POST' && $action === 'product-bulk-export') {
            $scope = $_POST['export_scope'] ?? '';
            if (!in_array($scope, ['selected', 'filtered'], true)) throw new InvalidArgumentException('Vyber rozsah exportu.');
            if ($scope === 'selected') $exportRows = ProductBulkTransfer::select($exportRows, is_string($_POST['selected_json'] ?? null) ? $_POST['selected_json'] : '');
            if ($exportRows === []) throw new InvalidArgumentException('Výběr neobsahuje žádné produkty.');
            $namesOnly = ($_POST['export_format'] ?? '') === 'names';
            $exportUrl = '';
            if ($namesOnly) {
                $exportUrl = (string) ((new \SimpleStore\Accounting\MailSettingsRepository($db))->load('')['settings']['public_base_url'] ?? '');
                if ($exportUrl === '') {
                    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
                    if (preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]+)?$/D', $host) !== 1) $host = 'localhost';
                    $exportUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $host . rtrim($basePath, '/');
                }
            }
            $body = $namesOnly ? $transfer->supplierOverview($exportRows, $exportUrl) : $transfer->export($exportRows);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="products-' . gmdate('Y-m-d-His') . ($namesOnly ? '-suppliers.json' : '.json') . '"');
            echo $body;
            exit;
        }
    }
} catch (InvalidArgumentException | RuntimeException $exception) {
    $transferError = $exception->getMessage();
}
if ($method === 'POST' && $action === 'product-bulk-step' && ($_POST['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if ($transferError !== '') http_response_code(422);
    echo json_encode(['state' => $bulkState, 'error' => $transferError], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
