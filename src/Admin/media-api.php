<?php
declare(strict_types=1);

use SimpleStore\Content\ContentRepository;
use SimpleStore\Media\MediaAttachment;
use SimpleStore\Media\MediaDeletion;
use SimpleStore\Media\MediaLibrary;
use SimpleStore\Product\ProductDetails;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Category\CategoryRepository;

// Only admin.php includes this controller, after checking the admin session and POST CSRF.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
// Libraries may write a diagnostic to stdout; keep the API response valid JSON.
ob_start();

try {
    $input = $method === 'GET' ? $_GET : $_POST;
    $type = $input['type'] ?? null;
    $key = $input['key'] ?? null;
    $language = $input['language'] ?? null;
    if (!is_string($type) || !in_array($type, ['product', 'page', 'post'], true) ||
        !is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
        !is_string($language) || !in_array($language, $site['languages'], true)) {
        throw new InvalidArgumentException('Neplatný produkt nebo dokument.');
    }
    $products = new ProductRepository($db, $site['languages'], new CategoryRepository($db), $site['revision_limit']);
    $current = $type === 'product' ? $products->current($key, $language) : $content->currentDocument($key, $language);
    if ($current === null || ($type !== 'product' && $current['type'] !== $type)) {
        throw new InvalidArgumentException('Obsah už neexistuje.');
    }
    $library = new MediaLibrary(__DIR__ . '/../..');
    $deletion = new MediaDeletion($db, $library);
    if ($method === 'GET') {
        if ($type === 'product') $current['variant_inventory'] = (new \SimpleStore\Product\ProductVariantRepository($db))->snapshot($key);
        $files = $deletion->withDeletionState($type, $key,
            MediaAttachment::withUsage($current, $type, $library->files($type, $key), true));
        $response = json_encode(['files' => $files], JSON_THROW_ON_ERROR);
        ob_end_clean();
        echo $response;
        return;
    }

    $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
    if ($revision === false || $revision < 1 || (int) $current['revision_number'] !== $revision) {
        throw new RuntimeException('Obsah se mezitím změnil. Obnov stránku a zkus to znovu.');
    }
    if (($_POST['action'] ?? '') === 'media-delete') {
        $path = $_POST['path'] ?? null;
        if (!is_string($path)) throw new InvalidArgumentException('Vyber fotografii ke smazání.');
        $deletion->deleteUnused($type, $key, $path);
        $response = json_encode(['revision' => $revision, 'deleted' => $path], JSON_THROW_ON_ERROR);
        ob_end_clean();
        echo $response;
        return;
    }
    if (($_POST['action'] ?? '') === 'media-detach') {
        $path = $_POST['path'] ?? null;
        if ($type !== 'product' || !is_string($path)) throw new InvalidArgumentException('Vyber fotografii produktu k odebrání.');
        $form = MediaAttachment::detachProduct($current, $path);
        $saved = $products->saveRevision($form, $key, $revision);
        $response = json_encode(['revision'=>(int)$saved['revision_number'], 'detached'=>$path, 'image_path'=>$form['image_path'],
            'url'=>$basePath.$language.'/produkt/'.rawurlencode($form['slug']).'?edit=1'], JSON_THROW_ON_ERROR);
        ob_end_clean(); echo $response; return;
    }
    $mode = $_POST['mode'] ?? ($type === 'product' ? 'main-image' : 'section-add-image');
    $rawIndex = $_POST['index'] ?? null;
    $index = $rawIndex === null ? null : filter_var($rawIndex, FILTER_VALIDATE_INT);
    if (!is_string($mode) || $index === false) {
        throw new InvalidArgumentException('Neplatné umístění fotografie.');
    }
    if ($type === 'product' && $mode === 'gallery-set' && isset($_POST['target_path'])) {
        // The picker may have removed another photo since the thumbnail was rendered.
        $target = $_POST['target_path'];
        $details = ProductDetails::decode($current['details_json'] ?? null, $current['sizes'] ?? '');
        $index = is_string($target) ? array_search($target, $details['gallery'], true) : false;
        if ($index === false) throw new InvalidArgumentException('Nahrazovaná fotografie už není v galerii. Zavři výběr a obnov stránku.');
    }
    $paths = [];
    try {
        if (($_POST['action'] ?? '') === 'media-upload') {
            $files = $_FILES['photos'] ?? [];
            $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
            MediaAttachment::capacity($current, $type, $count, $mode, $index);
            $paths = $library->storeUploaded($type, $key, $files);
        } elseif (($_POST['action'] ?? '') === 'media-attach') {
            $path = $_POST['path'] ?? null;
            if (!is_string($path) || !ProductDetails::imagePath(trim($path)) || (new \SimpleStore\Media\MediaCatalog(__DIR__ . '/../..'))->absolute(trim($path)) === null) {
                throw new InvalidArgumentException('Vyber fotografii nahranou ve složce images/.');
            }
            $paths = [trim($path)];
        } else {
            throw new InvalidArgumentException('Neznámá úprava fotografií.');
        }
        $form = $type === 'product'
            ? MediaAttachment::product($current, $paths, $mode, $index)
            : MediaAttachment::document($current, $paths, $mode, $index);
        $saved = $type === 'product'
            ? $products->saveRevision($form, $key, $revision)
            : $content->saveRevision($form, $key, $revision);
    } catch (Throwable $error) {
        // A failed database revision must not leave newly uploaded unreferenced files.
        if (($_POST['action'] ?? '') === 'media-upload') $library->removeNew($paths);
        throw $error;
    }
    $response = json_encode([
        'revision' => (int) $saved['revision_number'],
        'paths' => $paths,
        'url' => $basePath . $language . ($type === 'product' ? '/produkt' : ($type === 'post' ? '/blog' : '')) .
            '/' . rawurlencode($form['slug']) . '?edit=1',
    ], JSON_THROW_ON_ERROR);
    ob_end_clean();
    echo $response;
} catch (Throwable $error) {
    error_log((string) $error);
    $conflict = str_contains($error->getMessage(), 'mezi') ||
        str_contains($error->getMessage(), 'changed since');
    http_response_code($error instanceof InvalidArgumentException ? 422 : ($conflict ? 409 : 500));
    $response = json_encode(['error' => $error instanceof InvalidArgumentException || $conflict || $site['debug']
        ? $error->getMessage() : 'Fotografie se nepodařilo uložit. Zkontroluj oprávnění a nastavení PHP.'], JSON_THROW_ON_ERROR);
    ob_end_clean();
    echo $response;
}
