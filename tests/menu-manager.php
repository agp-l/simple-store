<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Configuration/Installation.php';

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Navigation\MenuManager;
use SimpleStore\Navigation\UrlManager;

// A database stand-in verifies menu placement and nesting without changing real content.
class MeekroDB
{
    public array $pages = [['slug' => 'o-nas', 'title' => 'O nás', 'menu_order' => 10]];
    public function queryFirstField(string $sql, mixed ...$values): int
    {
        return 1;
    }

    public function query(string $sql, mixed ...$values): array
    {
        if (str_contains($sql, 'FROM shop_product_revisions')) {
            return [['category' => 'obleceni', 'subcategory' => 'muzi/bundy', 'total' => 1]];
        }
        if (str_contains($sql, 'FROM shop_catalog_categories')) {
            return [
                ['path' => 'spani', 'title' => 'Spaní', 'sort_order' => 1],
                ['path' => 'obleceni', 'title' => 'Oblečení', 'sort_order' => 2],
                ['path' => 'obleceni/muzi', 'title' => 'Muži', 'sort_order' => 1],
                ['path' => 'obleceni/muzi/bundy', 'title' => 'Bundy', 'sort_order' => 1],
            ];
        }
        if (!str_contains($sql, 'SELECT slug, title, menu_order')) {
            throw new RuntimeException('Page ordering must be available when merging menu sources.');
        }
        return $this->pages;
    }
}

foreach (['Category/CategoryPath', 'Category/CategoryRepository', 'Content/ContentRepository',
    'Navigation/UrlManager', 'Navigation/MenuDefinitionRepository', 'Navigation/MenuManager'] as $file) {
    require dirname(__DIR__) . '/src/' . $file . '.php';
}

$db = new MeekroDB();
$url = new UrlManager('/shop/cs/kategorie-produktu/obleceni/muzi/bundy', '/shop/index.php');
$settings = require dirname(__DIR__) . '/config/menus.php';
$settings['custom'] = ['source' => 'manual', 'items' => [
    ['label' => 'Výpravy', 'path' => 'blog', 'children' => [
        ['label' => 'Bundy', 'category' => 'obleceni/muzi/bundy'],
    ]],
]];
$settings['primary']['items'] = [
    ['label' => 'Cestovní deník', 'external' => 'https://example.org/vypravy', 'children' => []],
];
$settings['utility']['items'] = [
    ['label' => 'Kontakt', 'external' => 'mailto:info@example.org', 'children' => []],
];
$menus = new MenuManager(new ContentRepository($db), new CategoryRepository($db), $url, $settings);

$primary = $menus->links('primary');
if (array_column($primary, 'label') !== ['Spaní', 'Oblečení', 'Cestovní deník'] || !$primary[1]['active']
    || $primary[2]['href'] !== 'https://example.org/vypravy' || !$primary[2]['newTab']
    || $primary[1]['children'][0]['children'][0]['href'] !== '/shop/cs/kategorie-produktu/obleceni/muzi/bundy') {
    throw new RuntimeException('Category menu did not preserve nesting and the current page.');
}
$tabs = $menus->links('category_tabs', 'obleceni/muzi');
if (array_column($tabs, 'label') !== ['Bundy'] || !$tabs[0]['active']) {
    throw new RuntimeException('Nested category tabs were not loaded.');
}
if (array_column($menus->links('utility'), 'label') !== ['Blog', 'O nás', 'Kontakt'] ||
    $menus->links('utility')[2]['newTab']) {
    throw new RuntimeException('Pages appeared in the wrong menu placement.');
}
if (!$menus->links('custom')[0]['children'][0]['active']) {
    throw new RuntimeException('Manual menus lost their nested category links.');
}
if (!$menus->links('custom')[0]['active']) {
    throw new RuntimeException('A parent menu item should show when its child is active.');
}
$blogUrl = new UrlManager('/shop/cs/blog/na-ceste', '/shop/index.php');
$blogMenu = new MenuManager(new ContentRepository($db), new CategoryRepository($db), $blogUrl, $settings);
if (!$blogMenu->links('utility')[0]['active'] || !$blogMenu->links('custom')[0]['active']) {
    throw new RuntimeException('Blog navigation must remain active on an article.');
}

$db->pages[] = ['slug' => 'kontakt', 'title' => 'Kontakt', 'menu_order' => 35];
$settings['utility']['blog_order'] = 20;
$settings['utility']['items'] = [['label' => 'Expedice', 'external' => 'https://example.org/expedice',
    'sort_order' => 1, 'children' => [['label' => 'Tábor', 'path' => 'tabor', 'sort_order' => 50]]]];
$orderedMenu = new MenuManager(new ContentRepository($db), new CategoryRepository($db), $blogUrl, $settings);
$ordered = $orderedMenu->links('utility');
if (array_column($ordered, 'label') !== ['Expedice', 'O nás', 'Blog', 'Kontakt'] ||
    !$ordered[2]['active'] || $ordered[0]['children'][0]['label'] !== 'Tábor') {
    throw new RuntimeException('Blog, pages and custom roots must share order without detaching children or losing active state.');
}
$settings['utility']['blog_order'] = 10;
$settings['utility']['items'][0]['sort_order'] = 10;
$tiedMenu = new MenuManager(new ContentRepository($db), new CategoryRepository($db), $url, $settings);
if (array_column($tiedMenu->links('utility'), 'label') !== ['Blog', 'O nás', 'Expedice', 'Kontakt']) {
    throw new RuntimeException('Equal positions must keep deterministic source order.');
}
$settings['utility']['include_blog'] = false;
$withoutBlog = new MenuManager(new ContentRepository($db), new CategoryRepository($db), $url, $settings);
if (array_column($withoutBlog->links('utility'), 'label') !== ['O nás', 'Expedice', 'Kontakt']) {
    throw new RuntimeException('Hiding Blog must preserve the shared order of the remaining links.');
}

$primaryMenu = $primary;
ob_start();
require dirname(__DIR__) . '/view/menu.php';
$html = ob_get_clean();
if (!str_contains($html, 'href="https://example.org/vypravy"') ||
    !str_contains($html, 'target="_blank" rel="noopener noreferrer"')) {
    throw new RuntimeException('The top menu must open external HTTPS links in a safe new tab.');
}

echo "Menu manager tests passed.\n";
