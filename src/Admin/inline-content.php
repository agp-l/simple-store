<?php
declare(strict_types=1);

use SimpleStore\Content\ContentInlineEditor;

// admin.php checks the administrator session and CSRF token before including this file.
header('Content-Type: application/json; charset=utf-8');

try {
    $key = $_POST['key'] ?? null;
    $language = $_POST['language'] ?? null;
    $type = $_POST['type'] ?? null;
    $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
    $operation = $_POST['operation'] ?? null;
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';
    $index = isset($_POST['index']) ? filter_var($_POST['index'], FILTER_VALIDATE_INT) : null;
    if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
        !is_string($language) || !in_array($language, $site['languages'], true) ||
        !in_array($type, ['page', 'post'], true) || $revision === false || $revision < 1 ||
        !is_string($operation) || !is_string($field) || !is_string($value) ||
        strlen($value) > 300000 || $index === false) {
        throw new InvalidArgumentException('Neplatná úprava stránky nebo článku.');
    }

    $current = $content->currentDocument($key, $language);
    if ($current === null || $current['type'] !== $type) {
        throw new InvalidArgumentException('Dokument neexistuje.');
    }
    if ($operation === 'restore') {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        $previous = $number === false ? null : $content->revision($key, $language, $number);
        if ($previous === null || $previous['type'] !== $type) {
            throw new InvalidArgumentException('Požadovaná revize neexistuje.');
        }
        $form = ContentInlineEditor::fromRevision($previous);
    } else {
        $form = ContentInlineEditor::change(
            ContentInlineEditor::fromRevision($current), $operation, $field, $value, $index
        );
    }

    $saved = $content->saveRevision(ContentInlineEditor::snapshot($form), $key, $revision);
    echo json_encode([
        'revision' => $saved['revision_number'],
        'url' => $basePath . $language . ($type === 'post' ? '/blog' : '') . '/' .
            rawurlencode($form['slug']) . '?edit=1',
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code($exception instanceof InvalidArgumentException ? 422 :
        ($exception->getMessage() === 'This document changed since you opened it. Reload before saving.' ? 409 : 500));
    echo json_encode(['error' => $site['debug'] ? $exception->getMessage() :
        'Úpravu se nepodařilo uložit. Obnovte stránku a zkuste to znovu.'], JSON_THROW_ON_ERROR);
}
