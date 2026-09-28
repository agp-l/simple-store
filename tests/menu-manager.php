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
        if (str_contains($sql, 'FROM catalog_categories')) {
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
    'Navigation/UrlManager', 'Navigation/MenuManager'] as $file) {
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
$menus = new MenuManager(new ContentRepository($db), new CategoryRepository($db), $url, $settings);

$primary = $menus->links('primary');
if (array_column($primary, 'label') !== ['Spaní', 'Oblečení'] || !$primary[1]['active']
    || $primary[1]['children'][0]['children'][0]['href'] !== '/shop/cs/kategorie-produktu/obleceni/muzi/bundy') {
    throw new RuntimeException('Category menu did not preserve nesting and the current page.');
}
$tabs = $menus->links('category_tabs', 'obleceni/muzi');
if (array_column($tabs, 'label') !== ['Bundy'] || !$tabs[0]['active']) {
    throw new RuntimeException('Nested category tabs were not loaded.');
}
if (array_column($menus->links('utility'), 'label') !== ['Blog', 'O nás']) {
    throw new RuntimeException('Pages appeared in the wrong menu placement.');
}
if (!$menus->links('custom')[0]['children'][0]['active']) {
    throw new RuntimeException('Manual menus lost their nested category links.');
}

echo "Menu manager tests passed.\n";
