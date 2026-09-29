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

if ($method === 'POST' && in_array($_POST['action'] ?? '', ['category-create', 'category-update'], true)) {
    try {
        if (!$categoryReady) throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
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
        header('Location: ' . $adminUrl . '?' . http_build_query([
            'section' => 'categories', 'language' => $language, 'edit' => $path, 'saved' => 1,
        ]), true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        $categoryError = $exception->getMessage();
    }
}

$categoryRows = $categoryReady ? $categories->allForAdmin($language) : [];
$selectedPath = $_GET['edit'] ?? null;
$selectedCategory = $categoryReady && is_string($selectedPath)
    ? $categories->findForAdmin($language, $selectedPath) : null;
$newParent = $_GET['parent'] ?? '';
if (!is_string($newParent) || ($newParent !== '' && $categories->find($language, $newParent) === null)) {
    $newParent = '';
}
