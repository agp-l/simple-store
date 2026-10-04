<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryPath;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Media\MediaAttachment;
use SimpleStore\Media\MediaDeletion;
use SimpleStore\Media\MediaLibrary;
use SimpleStore\Media\MediaPath;
use SimpleStore\Product\ProductRepository;

// admin.php has already checked the admin session.
$screen = 'media';
$selectedMedia = null;
$mediaFiles = [];
$mediaError = '';
$requestedType = $_GET['type'] ?? null;
$requestedKey = $_GET['key'] ?? null;
$requestedLanguage = $_GET['language'] ?? null;

if ($requestedType !== null || $requestedKey !== null || $requestedLanguage !== null) {
    if (!is_string($requestedType) || !in_array($requestedType, ['product', 'page', 'post'], true) ||
        !is_string($requestedKey) || preg_match('/^[a-f0-9]{32}$/D', $requestedKey) !== 1 ||
        !is_string($requestedLanguage) || !in_array($requestedLanguage, $site['languages'], true)) {
        $mediaError = 'Neplatný odkaz na fotografie. Otevři knihovnu přímo u produktu nebo dokumentu.';
    } else {
        $current = $requestedType === 'product'
            ? (new ProductRepository($db, $site['languages'], new CategoryRepository($db),
                $site['revision_limit']))->current($requestedKey, $requestedLanguage)
            : $content->currentDocument($requestedKey, $requestedLanguage);
        if ($current === null || ($requestedType !== 'product' && $current['type'] !== $requestedType)) {
            $mediaError = 'Obsah už neexistuje. Otevři knihovnu přímo u produktu nebo dokumentu.';
        } else {
            $route = $requestedType === 'product' ? '/produkt' :
                ($requestedType === 'post' ? '/blog' : '');
            $selectedMedia = [
                'type' => $requestedType,
                'key' => $requestedKey,
                'language' => $requestedLanguage,
                'revision' => (int) $current['revision_number'],
                'label' => $current[$requestedType === 'product' ? 'name' : 'title'],
                'mainImagePath' => $requestedType === 'product' ? $current['image_path'] : '',
                'category' => $requestedType === 'product' ? CategoryPath::fromProduct($current) : '',
                'directory' => MediaPath::directory($requestedType, $requestedKey),
                'editUrl' => $basePath . $requestedLanguage . $route . '/' .
                    rawurlencode($current['slug']) . '?edit=1',
            ];
            $library = new MediaLibrary(__DIR__ . '/../..');
            $mediaFiles = (new MediaDeletion($db, $library))->withDeletionState($requestedType, $requestedKey,
                MediaAttachment::withUsage($current, $requestedType,
                    $library->files($requestedType, $requestedKey)));
        }
    }
}
