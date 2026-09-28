<?php
declare(strict_types=1);

use SimpleStore\Content\ContentInlineEditor;

// This controller is reached only after admin.php has checked the administrator session.
$screen = 'editor';
if ($method === 'POST') {
    $language = $_POST['language'] ?? null;
    if (!is_string($language) || !in_array($language, $site['languages'], true)) {
        throw new InvalidArgumentException('Vyberte platný jazyk.');
    }
    if (($_POST['action'] ?? '') === 'create-translation') {
        $key = $_POST['key'] ?? null;
        $sourceLanguage = $_POST['source_language'] ?? null;
        if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
            !is_string($sourceLanguage) || !in_array($sourceLanguage, $site['languages'], true) ||
            $sourceLanguage === $language) {
            throw new InvalidArgumentException('Neplatný dokument pro překlad.');
        }
        $source = $content->currentDocument($key, $sourceLanguage);
        if ($source === null || $content->currentDocument($key, $language) !== null) {
            throw new InvalidArgumentException('Překlad už existuje nebo původní dokument nebyl nalezen.');
        }
        $type = $source['type'];
    } else {
        $type = $_POST['type'] ?? null;
        $key = null;
        if (!in_array($type, ['page', 'post'], true)) {
            throw new InvalidArgumentException('Vyberte stránku nebo článek.');
        }
    }
    $starter = ContentInlineEditor::starter($type, $language);
    $content->saveRevision($starter, $key, $key === null ? null : 0);
    header('Location: ' . $basePath . $language . ($type === 'post' ? '/blog' : '') .
        '/' . $starter['slug'] . '?edit=1', true, 303);
    exit;
}

// Old bookmarks into the form now lead to the document's on-page editor.
if (isset($_GET['key'])) {
    $key = $_GET['key'];
    $language = $_GET['language'] ?? $site['default_language'];
    if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
        !is_string($language) || !in_array($language, $site['languages'], true)) {
        throw new InvalidArgumentException('Neplatný dokument nebo jazyk.');
    }
    $current = $content->currentDocument($key, $language);
    if ($current === null) {
        throw new InvalidArgumentException('Dokument v tomto jazyce neexistuje.');
    }
    header('Location: ' . $basePath . $language . ($current['type'] === 'post' ? '/blog' : '') .
        '/' . rawurlencode($current['slug']) . '?edit=1', true, 303);
    exit;
}

$documents = $content->currentDocuments();
$translations = [];
foreach ($documents as $document) {
    $translations[$document['document_key']][] = $document['language'];
}
