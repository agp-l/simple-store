<?php
declare(strict_types=1);

use SimpleStore\Admin\AdminAuth;
use SimpleStore\Admin\AdminUserRepository;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Checkout\CartSession;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Navigation\StorefrontMenus;
use SimpleStore\Navigation\UrlManager;

$site = require __DIR__ . '/src/bootstrap.php';
// PHP notices must not be printed into JSON returned to the editor or media manager.
// They remain in the PHP error log; caught exceptions still provide JSON error messages.
if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin.php'), '/') . '/';
$adminUrl = $basePath . 'admin.php';
$screen = 'login';
$error = '';
$documents = [];
$csrf = '';
$chrome = [];
$cartCount = 0;

if (!is_file(__DIR__ . '/config/database.php') || !is_file(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(503);
    $screen = 'error';
    $error = 'Nejdřív nastav config/database.php a spusť composer install.';
    require __DIR__ . '/view/admin/layout.php';
    exit;
}

try {
    $db = ConnectionFactory::create(require __DIR__ . '/config/database.php');
    $menuUrl = new UrlManager($basePath . $site['default_language'],
        $_SERVER['SCRIPT_NAME'] ?? '/admin.php', $site['languages'], $site['default_language']);
    $chrome = StorefrontMenus::load($db, $menuUrl,
        new ContentRepository($db, $site['languages'], $site['revision_limit']),
        new CategoryRepository($db));
    $cartCount = (new CartSession($basePath))->count();
    $users = new AdminUserRepository($db);
    if (!$users->installed() || !$users->hasAdmin()) {
        $screen = 'setup';
        require __DIR__ . '/view/admin/layout.php';
        exit;
    }
    $auth = new AdminAuth($users, $basePath);
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(503);
    $screen = 'error';
    $error = $site['debug'] ? (string) $exception : 'Databázi nebo přihlášení se nepodařilo načíst.';
    require __DIR__ . '/view/admin/layout.php';
    exit;
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    $csrf = $auth->token();
    if ($method === 'POST' && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 &&
        str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(413);
        echo json_encode(['error' => 'Dávka přesahuje limit PHP post_max_size. Zmenši výběr nebo zvyš limit v php.ini.']);
        exit;
    }
    if ($method === 'POST') {
        if (!$auth->validToken($_POST['csrf'] ?? null)) {
            if (in_array($_POST['action'] ?? null, ['inline-product', 'inline-content', 'media-upload', 'media-attach'], true)) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['error' => 'Platnost přihlášení vypršela. Znovu se přihlas.']);
                exit;
            }
            http_response_code(403);
            $screen = 'forbidden';
            $error = 'Platnost administrátorského formuláře vypršela. Obnov stránku, znovu se přihlas a akci opakuj.';
            require __DIR__ . '/view/admin/layout.php';
            exit;
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'login' && !$auth->signedIn()) {
            $username = $_POST['username'] ?? null;
            $password = $_POST['password'] ?? null;
            if (!is_string($username) || !is_string($password) || !$auth->signIn($username, $password)) {
                $error = 'Nesprávné přihlašovací údaje. Po pěti pokusech počkej pět minut.';
            } else {
                header('Location: ' . $adminUrl, true, 303);
                exit;
            }
        } elseif ($action === 'logout' && $auth->signedIn()) {
            $auth->signOut();
            header('Location: ' . $adminUrl, true, 303);
            exit;
        } elseif (!in_array($action, ['create-content', 'create-translation', 'inline-content', 'create-product',
            'inline-product', 'category-create', 'category-update', 'menu-slot', 'menu-item-save',
            'menu-item-remove', 'page-menu', 'media-upload', 'media-attach', 'delete-product',
            'mark-order-paid', 'set-order-status', 'packeta-create', 'packeta-courier',
            'packeta-reconcile', 'packeta-retry', 'save-checkout-settings', 'customer-create',
            'customer-update', 'customer-active', 'customer-password'], true) ||
            !$auth->signedIn()) {
            if (in_array($action, ['inline-product', 'inline-content', 'media-upload', 'media-attach'], true)) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['error' => 'Pro úpravu obsahu se přihlas do administrace.']);
                exit;
            }
            http_response_code(403);
            $screen = 'forbidden';
            $error = 'Pro tuto akci je potřeba přihlášení správce. Otevři administraci a přihlas se.';
            require __DIR__ . '/view/admin/layout.php';
            exit;
        }
    }

    if (!$auth->signedIn()) {
        require __DIR__ . '/view/admin/layout.php';
        exit;
    }

    $content = new ContentRepository($db, $site['languages'], $site['revision_limit']);
    $screen = 'editor';

    if ($method === 'POST' && ($_POST['action'] ?? '') === 'inline-product') {
        require __DIR__ . '/src/Admin/inline-product.php';
        exit;
    }
    if ($method === 'POST' && ($_POST['action'] ?? '') === 'inline-content') {
        require __DIR__ . '/src/Admin/inline-content.php';
        exit;
    }
    if ($method === 'POST' && ($_POST['action'] ?? '') === 'delete-product') {
        require __DIR__ . '/src/Admin/delete-product.php';
        exit;
    }
    if (($method === 'POST' && in_array($_POST['action'] ?? '', ['media-upload', 'media-attach'], true)) ||
        ($method === 'GET' && ($_GET['section'] ?? '') === 'media' && ($_GET['api'] ?? '') === 'list')) {
        require __DIR__ . '/src/Admin/media-api.php';
        exit;
    }

    $action = $method === 'POST' ? ($_POST['action'] ?? '') : '';
    $section = $_GET['section'] ?? '';
    if (in_array($action, ['category-create', 'category-update'], true) ||
        ($method !== 'POST' && $section === 'categories')) {
        require __DIR__ . '/src/Admin/categories.php';
        require __DIR__ . '/view/admin/layout.php';
        exit;
    }
    if (in_array($action, ['menu-slot', 'menu-item-save', 'menu-item-remove', 'page-menu'], true) ||
        ($method !== 'POST' && $section === 'menus')) {
        require __DIR__ . '/src/Admin/menus.php';
        require __DIR__ . '/view/admin/layout.php';
        exit;
    }
    if ($method !== 'POST' && $section === 'media') {
        require __DIR__ . '/src/Admin/media.php';
        require __DIR__ . '/view/admin/layout.php';
        exit;
    }

    if ($action === 'create-product' || ($method !== 'POST' && $section === 'products')) {
        require __DIR__ . '/src/Admin/products.php';
        require __DIR__ . '/view/admin/layout.php';
        exit;
    }
    if (in_array($action, ['mark-order-paid', 'set-order-status', 'packeta-create',
        'packeta-courier', 'packeta-reconcile', 'packeta-retry'], true) ||
        ($method !== 'POST' && $section === 'orders')) {
        require __DIR__ . '/src/Admin/orders.php';
        require __DIR__ . '/view/admin/layout.php';
        exit;
    }
    if ($action === 'save-checkout-settings' || ($method !== 'POST' && $section === 'settings')) {
        require __DIR__ . '/src/Admin/settings.php';
        require __DIR__ . '/view/admin/layout.php';
        exit;
    }
    if (in_array($action, ['customer-create', 'customer-update', 'customer-active', 'customer-password'], true) ||
        ($method !== 'POST' && $section === 'users')) {
        require __DIR__ . '/src/Admin/users.php';
        require __DIR__ . '/view/admin/layout.php';
        exit;
    }

    require __DIR__ . '/src/Admin/contents.php';
} catch (Throwable $exception) {
    error_log((string) $exception);
    if ($method === 'POST' && in_array($_POST['action'] ?? null, ['inline-product', 'inline-content', 'media-upload', 'media-attach'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => $site['debug'] ? (string) $exception : 'Databázi se nepodařilo načíst.']);
        exit;
    }
    $deletingProduct = $method === 'POST' && ($_POST['action'] ?? '') === 'delete-product';
    $knownDeleteError = $deletingProduct && ($exception instanceof InvalidArgumentException ||
        ($exception instanceof RuntimeException && str_contains($exception->getMessage(), 'mezi')));
    http_response_code($knownDeleteError
        ? ($exception instanceof InvalidArgumentException ? 422 : 409) : 500);
    $screen = 'error';
    $error = $knownDeleteError ? $exception->getMessage() :
        ($site['debug'] ? (string) $exception : 'Administraci se nepodařilo načíst.');
}

require __DIR__ . '/view/admin/layout.php';
