<?php
declare(strict_types=1);

use SimpleStore\Content\ContentRepository;
use SimpleStore\Admin\AdminAuth;
use SimpleStore\Admin\AdminUserRepository;
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
$route = $url->route();
$databaseFile = __DIR__ . '/config/database.php';
$missing = [];
if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    $missing[] = 'Chybí knihovny Composeru. V kořeni projektu spusť: composer install';
}
if (!is_file($databaseFile)) {
    $missing[] = 'Chybí přístup k databázi. Spusť: cp config/database.example.php config/database.php — pak v config/database.php vyplň přihlašovací údaje.';
}

if ($missing !== []) {
    $renderer->render($route['name'] === 'catalog' ? 'catalog' : 'unavailable', $shared + [
        'setupNotice' => implode("\n", $missing),
    ], $route['name'] === 'catalog' ? 200 : 503);
    exit;
}

try {
    $database = require $databaseFile;
    $db = ConnectionFactory::create($database);
    $contents = new ContentRepository($db, $site['languages'], $site['revision_limit']);
    $categories = new CategoryRepository($db);
    $menus = new MenuManager($contents, $categories, $url, require __DIR__ . '/config/menus.php');
    $hasContent = (int) $db->queryFirstField(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
        'content_revisions'
    ) > 0;
    $shared['primaryMenu'] = $menus->links('primary');
    $shared['utilityMenu'] = $hasContent ? $menus->links('utility') : [];
    $shared['footerMenu'] = $menus->links('footer');
    $shared['categoryLabels'] = array_column($categories->all($url->getLanguage()), 'title', 'path');

    // An authenticated preview may read drafts; ordinary routes never start an admin session.
    $editRequested = ($_GET['edit'] ?? '') === '1';
    $auth = null;
    if (in_array($route['name'], ['product', 'page', 'post'], true)) {
        if ($editRequested || isset($_COOKIE['simple_store_admin'])) {
            $users = new AdminUserRepository($db);
            if ($users->installed()) {
                $auth = new AdminAuth($users, $url->getBasePath());
            }
        }
    }
    $canEdit = $auth !== null && $auth->signedIn();
    if ($canEdit) {
        header('Cache-Control: private, no-store');
    }

    if ($route['name'] === 'catalog' || $route['name'] === 'category') {
        $path = $route['path'] ?? null;
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
        $hasProducts = (int) $db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'product_revisions'
        ) > 0;
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
        $rawSearch = $_GET['search'] ?? '';
        if ($offset === false || !is_string($rawSearch) || strlen($rawSearch) > 200) {
            $renderer->render('not-found', $shared, 400);
            exit;
        }
        $search = trim($rawSearch);
        $sort = $_GET['sort'] ?? 'default';
        if (!is_string($sort) || !in_array($sort, ['default', 'price-asc', 'price-desc', 'name'], true)) {
            $sort = 'default';
        }
        $batch = $hasProducts
            ? (new ProductRepository($db, $site['languages']))->publishedPage(
                $url->getLanguage(), $path, $search, $sort, $offset
            ) : ['items' => [], 'nextOffset' => null];
        $shared['products'] = $batch['items'];
        $shared['searchTerm'] = $search;
        $shared['sortChoice'] = $sort;
        $shared['searchAction'] = $path === null ? $url->path() : $url->category($path);
        $nextUrl = $batch['nextOffset'] === null ? '' : $shared['searchAction'] . '?' . http_build_query(
            array_filter(['search' => $search, 'sort' => $sort === 'default' ? '' : $sort,
                'offset' => $batch['nextOffset']], static fn (mixed $value): bool => $value !== '')
        );
        $shared['nextUrl'] = $nextUrl;
        if (($_GET['partial'] ?? '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'html' => $renderer->cards('product', $batch['items'], $shared),
                'nextUrl' => $nextUrl,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }
        if (!$hasProducts) {
            $shared['setupNotice'] = 'Pro správu produktů importuj aktuální database/schema.sql.';
        } elseif (!$categories->installed()) {
            $shared['setupNotice'] = 'Pro načtení kategorií znovu importuj aktuální database/schema.sql.';
        } elseif (!$hasContent) {
            $shared['setupNotice'] = 'Pro blog a stránky importuj aktuální database/schema.sql.';
        }
        if ($selected !== null) {
            $shared['title'] = $selected['title'] . ' — dobrodruzi.cz';
        }
        $renderer->render('catalog', $shared);
    } elseif ($route['name'] === 'product') {
        $repository = new ProductRepository($db, $site['languages']);
        $item = $editRequested && $canEdit
            ? $repository->findCurrentBySlug($route['slug'], $url->getLanguage())
            : $repository->findPublished($route['slug'], $url->getLanguage());
        $editMode = $editRequested && $canEdit && $item !== null;
        if ($editMode) {
            $rows = [];
            $addCategories = static function (array $nodes, int $depth) use (&$addCategories, &$rows): void {
                foreach ($nodes as $node) {
                    $rows[] = ['path' => $node['path'], 'title' => str_repeat('— ', $depth) . $node['title']];
                    $addCategories($node['children'], $depth + 1);
                }
            };
            $addCategories($categories->tree($url->getLanguage()), 0);
            $shared['editorCategories'] = $rows;
            $shared['productHistory'] = $repository->historySummary($item['product_key'], $url->getLanguage());
            $shared['editToken'] = $auth->token();
        }
        $renderer->render($item === null ? 'not-found' : 'product-record', $shared + [
            'title' => $item === null ? 'Produkt nenalezen — dobrodruzi.cz' : $item['name'] . ' — dobrodruzi.cz',
            'description' => $item['summary'] ?? '', 'product' => $item,
            'canEditProduct' => $canEdit, 'editMode' => $editMode,
            'categoryTrail' => $item === null ? [] : $categories->trail(
                $url->getLanguage(), CategoryPath::fromProduct($item)
            ),
        ], $item === null ? 404 : 200);
    } elseif ($route['name'] === 'blog') {
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
        if ($offset === false) {
            $renderer->render('not-found', $shared, 400);
            exit;
        }
        $batch = $hasContent ? $contents->publishedPostsPage($url->getLanguage(), $offset)
            : ['items' => [], 'nextOffset' => null];
        $nextUrl = $batch['nextOffset'] === null ? '' : $url->path('blog') . '?offset=' . $batch['nextOffset'];
        if (($_GET['partial'] ?? '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'html' => $renderer->cards('post', $batch['items'], $shared),
                'nextUrl' => $nextUrl,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }
        $renderer->render('blog', $shared + [
            'title' => 'Blog — dobrodruzi.cz',
            'posts' => $batch['items'], 'nextUrl' => $nextUrl,
        ]);
    } elseif ($route['name'] === 'page' || $route['name'] === 'post') {
        $type = $route['name'];
        $slug = $route['slug'];
        $item = $editRequested && $canEdit
            ? $contents->findCurrentBySlug($type, $slug, $url->getLanguage())
            : $contents->findPublished($type, $slug, $url->getLanguage());
        $contentEditMode = $editRequested && $canEdit && $item !== null;
        if ($contentEditMode) {
            $shared['contentHistory'] = $contents->historySummary($item['document_key'], $url->getLanguage());
            $shared['editToken'] = $auth->token();
        }
        $renderer->render($item === null ? 'not-found' : $type, $shared + [
            'title' => $item === null ? 'Stránka nenalezena — dobrodruzi.cz' : $item['title'] . ' — dobrodruzi.cz',
            'description' => $item['summary'] ?? '', 'content' => $item,
            'canEditContent' => $canEdit, 'contentEditMode' => $contentEditMode,
            'backLink' => $type === 'post' ? $url->path('blog') : '',
        ], $item === null ? 404 : 200);
    } else {
        $renderer->render('not-found', $shared, 404);
    }
} catch (Throwable $error) {
    error_log((string) $error);
    $renderer->render('unavailable', $shared + ['debugError' => (string) $error], 503);
}
