<?php
declare(strict_types=1);

use SimpleStore\Content\ContentRepository;
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
    $shared['menuLinks'] = (new MenuManager($contents, $url))->links();

    if ($segments === []) {
        // Keep the sample catalog visible until the product table is installed and populated.
        $hasProducts = (int) $db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'product_revisions'
        ) > 0;
        $shared['products'] = $hasProducts
            ? (new ProductRepository($db, $site['languages']))->published($url->getLanguage()) : [];
        if (!$hasProducts) {
            $shared['setupNotice'] = 'Pro správu produktů importuj aktuální database/schema.sql. Ukázkový katalog zůstává dostupný.';
        }
        $renderer->render('catalog', $shared);
    } elseif (count($segments) === 2 && $segments[0] === 'produkt') {
        $item = (new ProductRepository($db, $site['languages']))
            ->findPublished($segments[1], $url->getLanguage());
        $renderer->render($item === null ? 'not-found' : 'product-record', $shared + [
            'title' => $item === null ? 'Produkt nenalezen — dobrodruzi.cz' : $item['name'] . ' — dobrodruzi.cz',
            'description' => $item['summary'] ?? '', 'product' => $item,
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
