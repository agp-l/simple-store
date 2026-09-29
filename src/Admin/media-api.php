<?php
declare(strict_types=1);

use SimpleStore\Content\ContentRepository;
use SimpleStore\Media\MediaAttachment;
use SimpleStore\Media\MediaLibrary;
use SimpleStore\Product\ProductDetails;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Category\CategoryRepository;

// Only admin.php includes this controller, after checking the admin session and POST CSRF.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

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
    if ($method === 'GET') {
        echo json_encode(['files' => $library->files($type, $key)], JSON_THROW_ON_ERROR);
        return;
    }

    $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
    if ($revision === false || $revision < 1 || (int) $current['revision_number'] !== $revision) {
        throw new RuntimeException('Obsah se mezitím změnil. Obnov stránku a zkus to znovu.');
    }
    $paths = [];
    try {
        if (($_POST['action'] ?? '') === 'media-upload') {
            $files = $_FILES['photos'] ?? [];
            $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
            MediaAttachment::capacity($current, $type, $count);
            $paths = $library->storeUploaded($type, $key, $files);
        } elseif (($_POST['action'] ?? '') === 'media-attach') {
            $path = $_POST['path'] ?? null;
            if (!is_string($path) || !ProductDetails::imagePath(trim($path))) {
                throw new InvalidArgumentException('Zadej cestu v images/ nebo HTTPS odkaz na obrázek.');
            }
            $paths = [trim($path)];
        } else {
            throw new InvalidArgumentException('Neznámá úprava fotografií.');
        }
        $form = $type === 'product'
            ? MediaAttachment::product($current, $paths)
            : MediaAttachment::document($current, $paths);
        $saved = $type === 'product'
            ? $products->saveRevision($form, $key, $revision)
            : $content->saveRevision($form, $key, $revision);
    } catch (Throwable $error) {
        // A failed database revision must not leave newly uploaded unreferenced files.
        if (($_POST['action'] ?? '') === 'media-upload') $library->removeNew($paths);
        throw $error;
    }
    echo json_encode([
        'revision' => (int) $saved['revision_number'],
        'paths' => $paths,
        'url' => $basePath . $language . ($type === 'product' ? '/produkt' : ($type === 'post' ? '/blog' : '')) .
            '/' . rawurlencode($form['slug']) . '?edit=1',
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log((string) $error);
    $conflict = str_contains($error->getMessage(), 'mezi') ||
        str_contains($error->getMessage(), 'changed since');
    http_response_code($error instanceof InvalidArgumentException ? 422 : ($conflict ? 409 : 500));
    echo json_encode(['error' => $error instanceof InvalidArgumentException || $site['debug']
        ? $error->getMessage() : 'Fotografie se nepodařilo uložit. Zkontroluj oprávnění a nastavení PHP.'], JSON_THROW_ON_ERROR);
}
