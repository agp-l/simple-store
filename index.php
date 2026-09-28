<?php
declare(strict_types=1);

use SimpleStore\Content\ContentRepository;
use SimpleStore\Category\CategoryPath;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Navigation\MenuManager;
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Rendering\PageRenderer;

$site = require __DIR__ . '/src/bootstrap.php';
$renderer = new PageRenderer(__DIR__ . '/view');

try {
    $url = new UrlManager(
        $_SERVER['REQUEST_URI'] ?? '/',
        $_SERVER['SCRIPT_NAME'] ?? '/index.php',
        $site['languages'],
        $site['default_language']
    );
} catch (InvalidArgumentException $error) {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/') . '/';
    $renderer->render('not-found', ['basePath' => $base, 'showErrors' => $site['debug']], 404);
    exit;
}

$shared = ['language' => $url->getLanguage(), 'basePath' => $url->getBasePath(), 'showErrors' => $site['debug']];
$segments = $url->getSegments();
$databaseFile = __DIR__ . '/config/database.php';
$missing = [];
if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    $missing[] = 'Chybí knihovny Composeru. V kořeni projektu spusť: composer install';
}
if (!is_file($databaseFile)) {
    $missing[] = 'Chybí přístup k databázi. Spusť: cp config/database.example.php config/database.php — pak v config/database.php vyplň přihlašovací údaje.';
}

if ($missing !== []) {
    $renderer->render($segments === [] ? 'catalog' : 'unavailable', $shared + [
        'setupNotice' => implode("\n", $missing),
    ], $segments === [] ? 200 : 503);
    exit;
}

try {
    $database = require $databaseFile;
    $db = ConnectionFactory::create($database);
    $contents = new ContentRepository($db, $site['languages']);
    $categories = new CategoryRepository($db);
    $menus = new MenuManager($contents, $categories, $url, require __DIR__ . '/config/menus.php');
    $shared['primaryMenu'] = $menus->links('primary');
    $shared['utilityMenu'] = $menus->links('utility');
    $shared['footerMenu'] = $menus->links('footer');
    $shared['categoryLabels'] = array_column($categories->all($url->getLanguage()), 'title', 'path');

    if ($segments === [] || $url->categoryPath() !== null) {
        $path = $url->categoryPath();
        $selected = $path === null ? null : $categories->find($url->getLanguage(), $path);
        if ($path !== null && $selected === null) {
            $renderer->render('not-found', $shared, 404);
            exit;
        }
        $menuRoot = $path === null ? '' :
            ($categories->children($url->getLanguage(), $path) !== [] ? $path : CategoryPath::parent($path));
        $shared['currentCategory'] = $selected;
        $shared['categoryTrail'] = $path === null ? [] : $categories->trail($url->getLanguage(), $path);
        $shared['categoryMenuRoot'] = $menuRoot === '' ? null : $categories->find($url->getLanguage(), $menuRoot);
        $shared['categoryMenu'] = $path === null ? [] : $menus->links('category_tabs', $menuRoot);
        // Keep the sample catalog visible until the product table is installed and populated.
        $hasProducts = (int) $db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'product_revisions'
        ) > 0;
        $published = $hasProducts
            ? (new ProductRepository($db, $site['languages']))->published($url->getLanguage()) : [];
        $shared['products'] = $path === null ? $published : array_values(array_filter(
            $published,
            static fn (array $row): bool => CategoryPath::contains($path, CategoryPath::fromProduct($row))
        ));
        $shared['showSamples'] = $path === null && $published === [];
        if (!$hasProducts) {
            $shared['setupNotice'] = 'Pro správu produktů importuj aktuální database/schema.sql. Ukázkový katalog zůstává dostupný.';
        } elseif (!$categories->installed()) {
            $shared['setupNotice'] = 'Pro načtení kategorií znovu importuj aktuální database/schema.sql.';
        }
        if ($selected !== null) {
            $shared['title'] = $selected['title'] . ' — dobrodruzi.cz';
        }
        $renderer->render('catalog', $shared);
    } elseif (count($segments) === 2 && $segments[0] === 'produkt') {
        $item = (new ProductRepository($db, $site['languages']))
            ->findPublished($segments[1], $url->getLanguage());
        $renderer->render($item === null ? 'not-found' : 'product-record', $shared + [
            'title' => $item === null ? 'Produkt nenalezen — dobrodruzi.cz' : $item['name'] . ' — dobrodruzi.cz',
            'description' => $item['summary'] ?? '', 'product' => $item,
            'categoryTrail' => $item === null ? [] : $categories->trail(
                $url->getLanguage(), CategoryPath::fromProduct($item)
            ),
        ], $item === null ? 404 : 200);
    } elseif ($segments === ['blog']) {
        $renderer->render('blog', $shared + [
            'title' => 'Blog — dobrodruzi.cz',
            'posts' => $contents->publishedPosts($url->getLanguage()),
        ]);
    } elseif (count($segments) === 2 && $segments[0] === 'blog') {
        $post = $contents->findPublished('post', $segments[1], $url->getLanguage());
        $renderer->render($post === null ? 'not-found' : 'post', $shared + [
            'title' => $post === null ? 'Stránka nenalezena — dobrodruzi.cz' : $post['title'] . ' — dobrodruzi.cz',
            'description' => $post['summary'] ?? '', 'content' => $post,
            'backLink' => $url->path('blog'),
        ], $post === null ? 404 : 200);
    } elseif (count($segments) === 1) {
        $page = $contents->findPublished('page', $segments[0], $url->getLanguage());
        $renderer->render($page === null ? 'not-found' : 'page', $shared + [
            'title' => $page === null ? 'Stránka nenalezena — dobrodruzi.cz' : $page['title'] . ' — dobrodruzi.cz',
            'description' => $page['summary'] ?? '', 'content' => $page,
        ], $page === null ? 404 : 200);
    } else {
        $renderer->render('not-found', $shared, 404);
    }
} catch (Throwable $error) {
    error_log((string) $error);
    $renderer->render('unavailable', $shared + ['debugError' => (string) $error], 503);
}
