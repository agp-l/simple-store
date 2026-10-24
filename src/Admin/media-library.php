<?php
declare(strict_types=1);

use SimpleStore\Media\MediaArchive;
use SimpleStore\Media\MediaCatalog;

// Reached only through the authenticated admin router, with CSRF checked for every POST.
$screen = 'media';
$mediaCatalog = new MediaCatalog(__DIR__ . '/../..');
$mediaError = '';
$mediaNotice = $_SESSION['media_library_notice'] ?? '';
unset($_SESSION['media_library_notice']);
$mediaFolder = $_GET['folder'] ?? 'all';
$mediaSearch = $_GET['q'] ?? '';
$mediaOffset = filter_var($_GET['offset'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
if (!is_string($mediaFolder) || !is_string($mediaSearch) || strlen($mediaSearch) > 200 || $mediaOffset === false) throw new InvalidArgumentException('Neplatný filtr médií.');
$mediaSearch = trim($mediaSearch);
$mediaCollection = true;
if ($method === 'POST') {
    try {
        $target = $_POST['folder'] ?? '';
        if (!is_string($target)) throw new InvalidArgumentException('Vyber cílovou složku.');
        $mediaAction = $_POST['action'] ?? '';
        if ($mediaAction === 'media-library-folder') {
            $name = $_POST['name'] ?? ''; $id = $_POST['folder_id'] ?? '';
            if (!is_string($name) || !is_string($id)) throw new InvalidArgumentException('Vyplň název složky.');
            $target = $mediaCatalog->saveFolder($name, $target, $id);
            $mediaNotice = 'Složka byla uložena. Adresy obrázků zůstaly zachované.';
        } elseif ($mediaAction === 'media-library-upload') {
            $uploaded = $mediaCatalog->upload($_FILES['photos'] ?? [], $target);
            $mediaNotice = 'Nahráno fotografií: ' . count($uploaded) . '. Miniatury jsou připravené.';
        } elseif ($mediaAction === 'media-library-move') {
            $paths = $_POST['paths'] ?? [];
            if (!is_array($paths)) throw new InvalidArgumentException('Vyber obrázky.');
            $mediaCatalog->assign($paths, $target);
            $mediaNotice = 'Obrázky byly zařazeny. Jejich použití na webu se nezměnilo.';
        } elseif ($mediaAction === 'media-library-export') {
            $folders = $_POST['folders'] ?? []; $paths = $_POST['paths'] ?? [];
            if (!is_array($folders) || !is_array($paths)) throw new InvalidArgumentException('Vyber složky nebo obrázky.');
            $archive = (new MediaArchive(__DIR__ . '/../..'))->export($folders, $paths);
            try {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="media-' . date('Y-m-d') . '.zip"');
                header('Cache-Control: private, no-store');
                header('Content-Length: ' . filesize($archive));
                readfile($archive);
            } finally { unlink($archive); }
            exit;
        } elseif ($mediaAction === 'media-library-import') {
            $file = $_FILES['archive'] ?? [];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException('Vyber ZIP zálohu. Soubor musí odpovídat také limitu hostingu.');
            }
            $result = (new MediaArchive(__DIR__ . '/../..'))->import($file['tmp_name']);
            $mediaNotice = 'Import dokončen: ' . $result['added'] . ' nových fotografií, ' . $result['reused'] . ' již existujících. Složky byly obnoveny.';
            $target = '';
        }
        $_SESSION['media_library_notice'] = $mediaNotice;
        header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=media&folder=' . rawurlencode($target === '' ? 'all' : $target)), true, 303);
        exit;
    } catch (Throwable $error) {
        error_log((string) $error);
        $mediaError = $error instanceof InvalidArgumentException ? $error->getMessage() : 'Změnu se nepodařilo dokončit. Zkontroluj soubor a oprávnění úložiště.';
        $mediaFolder = 'all';
    }
}
$mediaListing = $mediaCatalog->listing($mediaFolder, $mediaSearch, $mediaOffset);
$mediaFolders = $mediaListing['folders'];
$mediaState = $mediaCatalog->state();
if (($_GET['api'] ?? '') === 'library') {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    echo json_encode($mediaListing, JSON_THROW_ON_ERROR); exit;
}
