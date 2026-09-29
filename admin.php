<?php
declare(strict_types=1);

use SimpleStore\Admin\AdminAuth;
use SimpleStore\Admin\AdminUserRepository;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Database\ConnectionFactory;

$site = require __DIR__ . '/src/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin.php'), '/') . '/';
$adminUrl = $basePath . 'admin.php';
$screen = 'login';
$error = '';
$documents = [];
$csrf = '';

if (!is_file(__DIR__ . '/config/database.php') || !is_file(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(503);
    $screen = 'error';
    $error = 'Nejdřív nastav config/database.php a spusť composer install.';
    require __DIR__ . '/view/admin/layout.php';
    exit;
}

try {
    $db = ConnectionFactory::create(require __DIR__ . '/config/database.php');
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
    if ($method === 'POST') {
        if (!$auth->validToken($_POST['csrf'] ?? null)) {
            if (in_array($_POST['action'] ?? null, ['inline-product', 'inline-content'], true)) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['error' => 'Platnost přihlášení vypršela. Znovu se přihlas.']);
                exit;
            }
            http_response_code(403);
            $screen = 'forbidden';
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
            'menu-item-remove', 'page-menu'], true) ||
            !$auth->signedIn()) {
            if (in_array($action, ['inline-product', 'inline-content'], true)) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['error' => 'Pro úpravu obsahu se přihlas do administrace.']);
                exit;
            }
            http_response_code(403);
            $screen = 'forbidden';
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

    if ($action === 'create-product' || ($method !== 'POST' && $section === 'products')) {
        require __DIR__ . '/src/Admin/products.php';
        require __DIR__ . '/view/admin/layout.php';
        exit;
    }

    require __DIR__ . '/src/Admin/contents.php';
} catch (Throwable $exception) {
    error_log((string) $exception);
    if ($method === 'POST' && in_array($_POST['action'] ?? null, ['inline-product', 'inline-content'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => $site['debug'] ? (string) $exception : 'Databázi se nepodařilo načíst.']);
        exit;
    }
    http_response_code(500);
    $screen = 'error';
    $error = $site['debug'] ? (string) $exception : 'Administraci se nepodařilo načíst.';
}

require __DIR__ . '/view/admin/layout.php';
