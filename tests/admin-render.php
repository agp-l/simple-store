<?php
declare(strict_types=1);

$basePath = '/shop/';
$adminUrl = $basePath . 'admin.php';
$site = ['languages' => ['cs'], 'default_language' => 'cs'];
$csrf = 'test-token';
$error = '';
$language = 'cs';
$categoryReady = true;
$categoryError = '';
$categoryRows = [['path' => 'spani', 'title' => 'Spaní', 'sort_order' => 1, 'enabled' => 1, 'depth' => 0]];
$selectedCategory = $categoryRows[0];
$newParent = 'spani';
$categories = new class {
    public function find(string $language, string $path): ?array
    {
        return $path === 'spani' ? ['path' => $path] : null;
    }
};

$screen = 'categories';
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Kategorie a podkategorie') ||
    !str_contains($html, 'value="category-create"') ||
    !str_contains($html, 'value="category-update"') ||
    !str_contains($html, 'aria-current="page"')) {
    throw new RuntimeException('Category administration must provide the new and edit controls.');
}

$screen = 'menus';
$slot = 'utility';
$menuSlots = ['utility' => ['source' => 'content', 'include_blog' => true]];
$activeSlot = $menuSlots[$slot];
$menuReady = true;
$menuError = '';
$menuItems = [];
$selectedItem = null;
$categoryOptions = $categoryRows;
$pageRows = [['document_key' => str_repeat('a', 32), 'revision_number' => 2,
    'title' => 'O nás', 'slug' => 'o-nas', 'published' => 1,
    'visible_in_menu' => 1, 'menu_order' => 5]];
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'value="menu-slot"') ||
    !str_contains($html, 'value="page-menu"') ||
    !str_contains($html, 'Pořadí horních odkazů')) {
    throw new RuntimeException('Menu administration must include placement and page ordering.');
}

echo "Admin rendering tests passed.\n";
