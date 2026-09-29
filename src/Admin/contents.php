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

$filterLanguage = $_GET['language'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterSearch = $_GET['q'] ?? '';
$rawOffset = $_GET['offset'] ?? '0';
$offset = filter_var($rawOffset, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
if (!is_string($filterLanguage) || ($filterLanguage !== '' && !in_array($filterLanguage, $site['languages'], true)) ||
    !is_string($filterType) || !in_array($filterType, ['', 'page', 'post'], true) ||
    !is_string($filterStatus) || !in_array($filterStatus, ['', 'draft', 'published'], true) ||
    !is_string($filterSearch) || strlen($filterSearch) > 200 || $offset === false) {
    throw new InvalidArgumentException('Neplatný filtr obsahu.');
}
$filterSearch = trim($filterSearch);
$pageSize = 24;
$page = $content->managementPage(
    $filterLanguage === '' ? null : $filterLanguage,
    $filterType === '' ? null : $filterType,
    $filterStatus === '' ? null : $filterStatus === 'published',
    $filterSearch, $offset, $pageSize
);
$documents = $page['items'];
$translations = $content->translationLanguages(array_column($documents, 'document_key'));
$filters = ['section' => 'contents'];
foreach (['language' => $filterLanguage, 'type' => $filterType,
    'status' => $filterStatus, 'q' => $filterSearch] as $name => $value) {
    if ($value !== '') {
        $filters[$name] = $value;
    }
}
$previousUrl = $offset > 0 ? $adminUrl . '?' . http_build_query($filters +
    ['offset' => max(0, $offset - $pageSize)], '', '&', PHP_QUERY_RFC3986) : '';
$nextUrl = $page['nextOffset'] === null ? '' : $adminUrl . '?' . http_build_query($filters +
    ['offset' => $page['nextOffset']], '', '&', PHP_QUERY_RFC3986);
