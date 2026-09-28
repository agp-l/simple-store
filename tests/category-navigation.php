<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Navigation\MenuManager;
use SimpleStore\Navigation\UrlManager;

$site = require dirname(__DIR__) . '/src/bootstrap.php';
$db = ConnectionFactory::create(require dirname(__DIR__) . '/config/database.php');
$categories = new CategoryRepository($db);
$language = $site['default_language'];

if (!$categories->installed()) {
    throw new RuntimeException('First import database/schema.sql.');
}
$url = new UrlManager('/simple-store/cs/kategorie-produktu/spani/spacaky',
    '/simple-store/index.php', $site['languages'], $language);
$menus = new MenuManager(new ContentRepository($db, $site['languages']), $categories,
    $url, require dirname(__DIR__) . '/config/menus.php');

$roots = $menus->links('primary');
$names = array_column($roots, 'label');
if ($names !== ['Spaní', 'Batohy', 'Vybavení', 'Vaření', 'Oblečení', 'Boty']) {
    throw new RuntimeException('Incorrect root categories: ' . implode(', ', $names));
}
$sleeping = $menus->links('category_tabs', 'spani');
if (!in_array('Spacáky', array_column($sleeping, 'label'), true)
    || !in_array('Quilty', array_column($sleeping, 'label'), true)) {
    throw new RuntimeException('Missing sleeping subcategories.');
}
$clothing = $categories->trail($language, 'obleceni/muzi/bundy');
if (array_column($clothing, 'title') !== ['Oblečení', 'Muži', 'Bundy']) {
    throw new RuntimeException('Incorrect three-level breadcrumb.');
}
if ($categories->find($language, 'spani/neznama-kategorie') !== null) {
    throw new RuntimeException('Unknown category unexpectedly resolved.');
}

echo "Category navigation tests passed.\n";
