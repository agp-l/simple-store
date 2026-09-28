<?php
declare(strict_types=1);

use SimpleStore\Content\ContentInlineEditor;

// This controller is reached only after admin.php has checked the administrator session.
$screen = 'editor';
if ($method === 'POST') {
    $type = $_POST['type'] ?? null;
    $language = $_POST['language'] ?? null;
    if (!in_array($type, ['page', 'post'], true) ||
        !is_string($language) || !in_array($language, $site['languages'], true)) {
        throw new InvalidArgumentException('Vyberte stránku nebo článek a platný jazyk.');
    }
    $starter = ContentInlineEditor::starter($type, $language);
    $content->saveRevision($starter);
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
