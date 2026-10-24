<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;

// admin.php has already checked the session and CSRF token.
$screen = 'categories';
$categories = new CategoryRepository($db);
$categoryReady = $categories->installed();
$categoryError = '';
$language = $_POST['language'] ?? $_GET['language'] ?? $site['default_language'];
if (!is_string($language) || !in_array($language, $site['languages'], true)) {
    throw new InvalidArgumentException('Neplatný jazyk kategorií.');
}

$backupKind = 'navigation';
require __DIR__ . '/configuration-transfer.php';
if ($method === 'POST' && in_array($_POST['action'] ?? '', ['category-create', 'category-update', 'category-order'], true)) {
    try {
        if (!$categoryReady) throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        if ($_POST['action'] === 'category-order') {
            $positions = $_POST['positions'] ?? null;
            $version = $_POST['order_version'] ?? null;
            if (!is_array($positions) || !is_string($version)) throw new InvalidArgumentException('Neplatné pořadí kategorií.');
            $categories->saveOrder($language, $positions, $version);
            header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?' . http_build_query([
                'section' => 'categories', 'language' => $language, 'ordered' => 1,
            ])), true, 303);
            exit;
        }
        $order = filter_var($_POST['sort_order'] ?? null, FILTER_VALIDATE_INT);
        $title = $_POST['title'] ?? null;
        if ($order === false || !is_string($title)) {
            throw new InvalidArgumentException('Vyplň název a platné pořadí.');
        }
        if ($_POST['action'] === 'category-create') {
            $parent = $_POST['parent'] ?? null;
            $slug = $_POST['slug'] ?? null;
            if (!is_string($parent) || !is_string($slug)) {
                throw new InvalidArgumentException('Neplatný rodič nebo adresa.');
            }
            $path = $categories->create($language, $parent, $slug, $title, $order);
        } else {
            $path = $_POST['path'] ?? null;
            if (!is_string($path)) throw new InvalidArgumentException('Neplatná kategorie.');
            $categories->update($language, $path, $title, $order, isset($_POST['enabled']));
        }
        header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?' . http_build_query([
            'section' => 'categories', 'language' => $language, 'edit' => $path, 'saved' => 1,
        ])), true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $categoryError = $exception->getMessage();
    }
}

$categoryRows = $categoryReady ? $categories->allForAdmin($language) : [];
$categoryOrderVersion = CategoryRepository::orderVersion($categoryRows);
$selectedPath = $_GET['edit'] ?? null;
$selectedCategory = $categoryReady && is_string($selectedPath)
    ? $categories->findForAdmin($language, $selectedPath) : null;
$newParent = $_GET['parent'] ?? '';
if (!is_string($newParent) || ($newParent !== '' && $categories->findForAdmin($language, $newParent) === null)) {
    $newParent = '';
}
