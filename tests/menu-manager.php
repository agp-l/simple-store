<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Navigation\MenuManager;
use SimpleStore\Navigation\UrlManager;

// A database stand-in verifies menu placement and nesting without changing real content.
class MeekroDB
{
    public function queryFirstField(string $sql, mixed ...$values): int
    {
        return 1;
    }

    public function query(string $sql, mixed ...$values): array
    {
        if (str_contains($sql, 'FROM shop_catalog_categories')) {
            return [
                ['path' => 'spani', 'title' => 'Spaní', 'sort_order' => 1],
                ['path' => 'obleceni', 'title' => 'Oblečení', 'sort_order' => 2],
                ['path' => 'obleceni/muzi', 'title' => 'Muži', 'sort_order' => 1],
                ['path' => 'obleceni/muzi/bundy', 'title' => 'Bundy', 'sort_order' => 1],
            ];
        }
        return [['slug' => 'o-nas', 'title' => 'O nás']];
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

$primaryMenu = $primary;
ob_start();
require dirname(__DIR__) . '/view/menu.php';
$html = ob_get_clean();
if (!str_contains($html, 'href="https://example.org/vypravy"') ||
    !str_contains($html, 'target="_blank" rel="noopener noreferrer"')) {
    throw new RuntimeException('The top menu must open external HTTPS links in a safe new tab.');
}

echo "Menu manager tests passed.\n";
