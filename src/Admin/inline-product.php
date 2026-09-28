<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Product\ProductInlineEditor;
use SimpleStore\Product\ProductRepository;

// admin.php has already checked the administrator session and CSRF token.
header('Content-Type: application/json; charset=utf-8');

try {
    $key = $_POST['key'] ?? null;
    $language = $_POST['language'] ?? null;
    $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
    $operation = $_POST['operation'] ?? null;
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';
    $index = isset($_POST['index']) ? filter_var($_POST['index'], FILTER_VALIDATE_INT) : null;
    if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
        !is_string($language) || !in_array($language, $site['languages'], true) ||
        $revision === false || $revision < 1 || !is_string($operation) ||
        !is_string($field) || !is_string($value) || strlen($value) > 30000 ||
        $index === false) {
        throw new InvalidArgumentException('Neplatná úprava produktu.');
    }

    $categories = new CategoryRepository($db);
    $repository = new ProductRepository($db, $site['languages'], $categories);
    $current = $repository->current($key, $language);
    if ($current === null) {
        throw new InvalidArgumentException('Produkt neexistuje.');
    }
    if ($operation === 'restore') {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        $previous = $number === false ? null : $repository->revision($key, $language, $number);
        if ($previous === null) {
            throw new InvalidArgumentException('Požadovaná revize neexistuje.');
        }
        $form = ProductInlineEditor::fromRevision($previous);
    } else {
        $form = ProductInlineEditor::change(
            ProductInlineEditor::fromRevision($current), $operation, $field, $value, $index
        );
    }
    $saved = $repository->saveRevision($form, $key, $revision);
    echo json_encode([
        'revision' => $saved['revision_number'],
        'url' => $basePath . $language . '/produkt/' . rawurlencode($form['slug']) . '?edit=1',
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code($exception instanceof InvalidArgumentException ? 422 : 409);
    echo json_encode(['error' => $site['debug'] ? $exception->getMessage() :
        'Úpravu se nepodařilo uložit. Obnovte stránku a zkuste to znovu.'], JSON_THROW_ON_ERROR);
}
