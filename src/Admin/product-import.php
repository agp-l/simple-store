<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Product\ProductImport;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductStockRepository;
use SimpleStore\Product\ProductSupplierLinkRepository;

// Authentication and CSRF validation are performed by admin.php before routing here.
$screen = 'product-import';
$importError = '';
$importPreview = null;
$importJson = is_string($_POST['json'] ?? null) ? $_POST['json'] : '';
$language = $_GET['language'] ?? $site['default_language'];
if (!is_string($language) || !in_array($language, $site['languages'], true)) $language = $site['default_language'];
$categories = new CategoryRepository($db);
$repository = new ProductRepository($db, $site['languages'], $categories, $site['revision_limit'], new ProductStockRepository($db));
$importTab = $_GET['tab'] ?? (isset($_GET['key']) || isset($_GET['example']) ? 'single' : 'export');
if (!is_string($importTab) || !in_array($importTab, ['export', 'bulk', 'single', 'zip', 'help'], true)) $importTab = 'export';
if (str_starts_with((string) $action, 'product-zip-')) $importTab = 'zip';
if (str_starts_with((string) $action, 'product-import-')) $importTab = 'single';
if (str_starts_with((string) $action, 'product-bulk-')) $importTab = $action === 'product-bulk-export' ? 'export' : 'bulk';
$promptError = '';
$aiPrompt = '';
if ($action === 'product-prompt-save') $importTab = 'help';
if ($importTab === 'help') {
    $promptStore = new \SimpleStore\Product\ProductImportPromptRepository($db);
    $aiPrompt = $method === 'POST' && $action === 'product-prompt-save'
        ? (is_string($_POST['ai_prompt'] ?? null) ? $_POST['ai_prompt'] : '') : $promptStore->load();
    if ($method === 'POST' && $action === 'product-prompt-save') {
        try {
            $promptStore->save($aiPrompt);
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=product-import&tab=help&language=' . rawurlencode($language) . '&prompt_saved=1'), true, 303);
            exit;
        } catch (Throwable $exception) {
            error_log('Product import prompt save: ' . $exception->getMessage());
            $promptError = $exception instanceof InvalidArgumentException ? $exception->getMessage()
                : 'Zadání se nepodařilo uložit. Zkontroluj práva databáze nebo aktualizuj tabulky v sekci Databáze. Rozepsaný text zůstal zachován.';
        }
    }
}
require __DIR__ . '/product-transfer.php';
$zipMode = is_string($_POST['duplicate_mode'] ?? null) ? $_POST['duplicate_mode'] : 'skip';
$zipState = null;
$zipError = '';
try {
    if ($method === 'POST' && in_array($action, ['product-zip-upload', 'product-zip-step', 'product-zip-clear'], true)) {
        if ($action === 'product-zip-upload') {
            \SimpleStore\Product\ProductImportJob::expire();
            $token = \SimpleStore\Product\ProductImportJob::upload($_FILES['package'] ?? [], $zipMode);
            if (isset($_SESSION['product_zip_job'])) (new \SimpleStore\Product\ProductImportJob($_SESSION['product_zip_job']))->remove();
            $_SESSION['product_zip_job'] = $token;
        } else {
            $job = new \SimpleStore\Product\ProductImportJob($_SESSION['product_zip_job'] ?? '');
            if ($action === 'product-zip-clear') {
                $job->remove();
                unset($_SESSION['product_zip_job']);
            } else {
                $job->step(new \SimpleStore\Product\ProductPackageImporter($repository, $categories,
                    new \SimpleStore\Media\MediaLibrary(dirname(__DIR__, 2)), $site['languages']));
            }
        }
    }
    if (isset($_SESSION['product_zip_job'])) $zipState = (new \SimpleStore\Product\ProductImportJob($_SESSION['product_zip_job']))->state();
} catch (InvalidArgumentException | RuntimeException $exception) {
    $zipError = $exception->getMessage();
}
if ($method === 'POST' && $action === 'product-zip-step' && ($_POST['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    if ($zipError !== '') http_response_code(422);
    echo json_encode(['state' => $zipState, 'error' => $zipError], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
if ($method === 'POST' && $action === 'product-zip-upload' && $zipError === '') {
    header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=product-import&tab=zip#zip-import'), true, 303);
    exit;
}
try {
    if ($method === 'POST' && in_array($action, ['product-import-preview', 'product-import-save'], true)) {
        $importData = ProductImport::decode($importJson);
        $language = $importData['language'];
        if (!in_array($language, $site['languages'], true)) throw new InvalidArgumentException('Neplatný jazyk produktu.');
        $key = $importData['product_key'] ?? null;
        $current = $key === null ? null : $repository->current($key, $language);
        $importPreview = ProductImport::fields($importData, $current);
        if ($categories->findForAdmin($language, $importPreview['category_path']) === null) {
            throw new InvalidArgumentException('category_path neodpovídá aktivní kategorii. Použij cestu ze seznamu níže.');
        }
        if ($action === 'product-import-save') {
            $repository->saveRevision($importPreview, $key, $importData['revision'] ?? null, ProductImport::suppliers($importData));
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($basePath . $language . '/produkt/' . $importPreview['slug'] . '?edit=1'), true, 303);
            exit;
        }
    } elseif (is_string($_GET['key'] ?? null)) {
        $key = $_GET['key'];
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) throw new InvalidArgumentException('Neplatný klíč produktu.');
        $current = $repository->current($key, $language);
        if ($current === null) throw new InvalidArgumentException('Produkt neexistuje.');
        $importJson = ProductImport::export($current, (new ProductSupplierLinkRepository($db))->forProduct($key));
    } elseif (($_GET['example'] ?? '') === 'flextail') {
        $importJson = (string) file_get_contents(__DIR__ . '/../Product/examples/flextail.json');
    }
} catch (InvalidArgumentException | RuntimeException $exception) {
    $importError = $exception->getMessage();
    $importPreview = null;
}
if (!in_array($language, $site['languages'], true)) $language = $site['default_language'];
$importCategories = $categories->allForAdmin($language);
