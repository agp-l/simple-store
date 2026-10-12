<?php
declare(strict_types=1);

use SimpleStore\Content\ContentImport;

// admin.php has already checked the administrator session and POST CSRF token.
$screen = 'content-import';
$importError = '';
$importPreview = null;
$importToken = '';
$importMode = is_string($_POST['mode'] ?? null) ? $_POST['mode'] : 'new';
$importJson = is_string($_POST['json'] ?? null) ? $_POST['json'] : '';
$exportUrl = '';
$language = $site['default_language'];

try {
    if ($method === 'GET' && isset($_GET['key'])) {
        $key = $_GET['key'];
        $language = $_GET['language'] ?? $language;
        if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
            !is_string($language) || !in_array($language, $site['languages'], true)) {
            throw new InvalidArgumentException('Neplatný dokument nebo jazyk.');
        }
        $current = $content->currentDocument($key, $language);
        if ($current === null) throw new InvalidArgumentException('Stránka nebo článek neexistuje.');
        $importJson = ContentImport::export($current);
        $importMode = 'update';
        $exportUrl = $adminUrl . '?' . http_build_query([
            'section' => 'content-import', 'key' => $key, 'language' => $language, 'download' => '1',
        ], '', '&', PHP_QUERY_RFC3986);
        if (($_GET['download'] ?? '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $current['type'] . '-' .
                preg_replace('/[^a-zA-Z0-9_-]/', '-', $current['slug'] . '-' . $language) . '.json"');
            echo $importJson;
            exit;
        }
    } elseif ($method === 'GET') {
        $type = ($_GET['type'] ?? '') === 'post' ? 'post' : 'page';
        $requestedLanguage = $_GET['language'] ?? $language;
        if (is_string($requestedLanguage) && in_array($requestedLanguage, $site['languages'], true)) $language = $requestedLanguage;
        $importJson = json_encode([
            'format' => ContentImport::FORMAT, 'type' => $type, 'language' => $language,
            'content' => ['title' => $type === 'post' ? 'Nový článek' : 'Nová stránka', 'slug' => '',
                'summary' => 'Krátký úvod.', 'sections' => [
                    ['type' => 'text', 'heading' => 'Úvod', 'body' => 'Sem napiš text.'],
                ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } elseif ($method === 'POST') {
        $upload = $_FILES['json_file'] ?? null;
        if ($upload !== null) {
            if (!is_array($upload) || !is_int($upload['error'] ?? null)) throw new InvalidArgumentException('Neplatný soubor JSON.');
            if ($upload['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($upload['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Soubor se nepodařilo nahrát. Ověř velikost souboru a limit upload_max_filesize serveru.');
                if (!is_string($upload['name'] ?? null) || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'json' ||
                    !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
                    throw new InvalidArgumentException('Vyber soubor s příponou .json.');
                }
                if (($upload['size'] ?? ContentImport::LIMIT + 1) > ContentImport::LIMIT) {
                    throw new InvalidArgumentException('JSON může mít nejvýše 4 MiB.');
                }
                $uploadedJson = file_get_contents($upload['tmp_name'], false, null, 0, ContentImport::LIMIT + 1);
                if ($uploadedJson === false) throw new InvalidArgumentException('Soubor JSON se nepodařilo přečíst.');
                $importJson = $uploadedJson;
            }
        }
        $data = ContentImport::decode($importJson);
        $current = $importMode === 'update' && isset($data['document_key'])
            ? $content->currentDocument($data['document_key'], $data['language']) : null;
        $importPreview = ContentImport::fields($data, $site['languages'], $importMode, $current);
        $key = $importMode === 'update' ? $data['document_key'] : null;
        $revision = $importMode === 'update' ? $data['revision'] : null;
        $duplicate = $content->findCurrentBySlug($data['type'], $importPreview['slug'], $data['language']);
        if ($duplicate !== null && $duplicate['document_key'] !== $key) {
            throw new InvalidArgumentException('Tato adresa už existuje. Pro kopii změň slug v JSON; pro úpravu původního dokumentu použij jeho export a volbu Aktualizovat existující.');
        }
        $importToken = hash_hmac('sha256', $importMode . "\0" . $importJson, $csrf);
        if ($action === 'content-import-save') {
            if (!is_string($_POST['preview_token'] ?? null) || !hash_equals($importToken, $_POST['preview_token'])) {
                throw new InvalidArgumentException('JSON nebo způsob importu se změnil. Nejdřív znovu zobraz náhled.');
            }
            $content->saveRevision($importPreview, $key, $revision);
            header('Location: ' . $basePath . $data['language'] . ($data['type'] === 'post' ? '/blog' : '') .
                '/' . rawurlencode($importPreview['slug']) . '?edit=1', true, 303);
            exit;
        }
    }
} catch (Throwable $exception) {
    error_log('Content import: ' . $exception->getMessage());
    $importError = $exception instanceof InvalidArgumentException ? $exception->getMessage() :
        ($exception->getMessage() === 'This document changed since you opened it. Reload before saving.'
            ? 'Obsah se mezitím změnil. Získej nový export a zopakuj úpravy.'
            : 'Obsah se nepodařilo načíst nebo uložit. JSON zůstal ve formuláři; zkontroluj připojení k databázi.');
    $importPreview = null;
    if ($method === 'POST') http_response_code($exception instanceof InvalidArgumentException ? 422 : 409);
}
if (strlen($importJson) > ContentImport::LIMIT) $importJson = '';
