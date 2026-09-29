<?php
declare(strict_types=1);

use SimpleStore\Navigation\MenuDefinitionRepository;
use SimpleStore\Navigation\MenuManager;
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Category\CategoryRepository;

class MeekroDB
{
    public bool $installed = false;
    public array $menus = [];

    public function queryFirstField(string $sql, mixed ...$args): int
    {
        return $this->installed ? 1 : 0;
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, 'SELECT slot, source, parent_path')) {
            $rows = [];
            foreach ($this->menus as $row) {
                if ($row['language'] === $args[0]) $rows[] = $row;
            }
            return $rows;
        }
        if (str_contains($sql, 'INSERT INTO navigation_menus')) {
            [$language, $slot, $source, $parent, $blog, $items] = $args;
            $key = $language . ':' . $slot;
            $this->menus[$key] = [
                'language' => $language, 'slot' => $slot, 'source' => $source,
                'parent_path' => $parent, 'include_blog' => $blog,
                'items_json' => $items,
            ];
        }
        if (str_contains($sql, 'UPDATE navigation_menus SET items_json')) {
            $this->menus[$args[1] . ':' . $args[2]]['items_json'] = $args[0];
        }
        return [];
    }

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        return $this->menus[$args[0] . ':' . $args[1]] ?? null;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

$db = new MeekroDB();
$defaults = require dirname(__DIR__) . '/config/menus.php';
$menus = new MenuDefinitionRepository($db, $defaults);
if ($menus->settings('cs') !== $defaults) {
    throw new RuntimeException('Missing menu table must preserve default storefront menus.');
}
$db->installed = true;
$menus->saveSlot('cs', 'primary', 'manual', '', false);
$first = $menus->saveItem('cs', 'primary', null, [
    'label' => 'Výpravy', 'target_type' => 'path', 'target' => 'blog',
    'parent_id' => '', 'sort_order' => '5',
]);
$second = $menus->saveItem('cs', 'primary', null, [
    'label' => 'Boty', 'target_type' => 'category', 'target' => 'boty',
    'parent_id' => $first, 'sort_order' => '1',
]);
$links = $menus->settings('cs')['primary']['items'];
if ($links[0]['path'] !== 'blog' || $links[0]['children'][0]['category'] !== 'boty') {
    throw new RuntimeException('Manual links lost their nested destination.');
}
$public = new MenuManager(new ContentRepository($db), new CategoryRepository($db),
    new UrlManager('/shop/cs/blog', '/shop/index.php'), $menus->settings('cs'));
if ($public->links('primary')[0]['children'][0]['href'] !== '/shop/cs/kategorie-produktu/boty') {
    throw new RuntimeException('Saved menu settings must produce usable storefront URLs.');
}
try {
    $menus->saveItem('cs', 'primary', $first, [
        'label' => 'Výpravy', 'target_type' => 'path', 'target' => 'blog',
        'parent_id' => $second, 'sort_order' => '5',
    ]);
    throw new RuntimeException('A cyclic menu was accepted.');
} catch (InvalidArgumentException $expected) {
}
$menus->removeItem('cs', 'primary', $first);
if ($menus->settings('cs')['primary']['items'][0]['category'] !== 'boty') {
    throw new RuntimeException('Removing a menu parent must keep its child.');
}
$menus->saveSlot('cs', 'primary', 'categories', '', false);
if ($menus->settings('cs')['primary']['source'] !== 'categories' ||
    $menus->settings('cs')['primary']['parent'] !== '') {
    throw new RuntimeException('Switching to category source must restore the automatic menu.');
}
$menus->saveSlot('en', 'utility', 'content', '', false);
if ($menus->settings('en')['utility']['include_blog'] || !$menus->settings('cs')['utility']['include_blog']) {
    throw new RuntimeException('Menu settings must be separate per language.');
}

$footer = $menus->settings('cs')['footer'];
if ($footer['source'] !== 'manual' || count($footer['items']) !== 6 ||
    count($menus->itemsForAdmin('cs', 'footer')) !== 6) {
    throw new RuntimeException('Suggested footer links must be editable before first save.');
}
$db->menus['en:footer'] = ['language' => 'en', 'slot' => 'footer', 'source' => 'categories',
    'parent_path' => '', 'include_blog' => 0, 'items_json' => '[]'];
if ($menus->settings('en')['footer']['source'] !== 'manual' ||
    count($menus->itemsForAdmin('en', 'footer')) !== 6) {
    throw new RuntimeException('The former footer category default must become editable legal links.');
}
$footerItem = $menus->itemsForAdmin('cs', 'footer')[0];
$menus->saveItem('cs', 'footer', $footerItem['id'], [
    'label' => 'Doručení', 'target_type' => 'path', 'target' => 'doprava-a-platba',
    'parent_id' => '', 'sort_order' => '5',
]);
$menus->saveSlot('cs', 'footer', 'manual', '', false, 'Nákup');
if ($menus->settings('cs')['footer']['title'] !== 'Nákup' ||
    $menus->settings('cs')['footer']['items'][0]['label'] !== 'Doručení') {
    throw new RuntimeException('Editing the footer heading must preserve customized links.');
}
$menus->saveItem('cs', 'footer', null, [
    'label' => 'Externí', 'target_type' => 'external', 'target' => 'https://example.org/',
    'parent_id' => '', 'sort_order' => '100',
]);
$footerLinks = (new MenuManager(new ContentRepository($db), new CategoryRepository($db),
    new UrlManager('/shop/cs', '/shop/index.php'), $menus->settings('cs')))->links('footer');
if ($footerLinks[count($footerLinks) - 1]['href'] !== 'https://example.org/') {
    throw new RuntimeException('The footer must render a configured external URL.');
}
try {
    $menus->saveItem('cs', 'footer', null, [
        'label' => 'Nebezpečný', 'target_type' => 'external', 'target' => 'javascript:alert(1)',
        'parent_id' => '', 'sort_order' => '110',
    ]);
    throw new RuntimeException('Unsafe external menu URL was accepted.');
} catch (InvalidArgumentException $expected) {
}

echo "Menu definition tests passed.\n";
