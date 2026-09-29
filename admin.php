<?php
declare(strict_types=1);

use SimpleStore\Admin\AdminAuth;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Database\ConnectionFactory;

$site = require __DIR__ . '/src/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin.php'), '/') . '/';
$adminUrl = $basePath . 'admin.php';
$adminFile = __DIR__ . '/config/admin.php';
$screen = 'login';
$error = '';
$documents = [];
$csrf = '';

if (!is_file($adminFile)) {
    if (!is_executable(dirname($adminFile))) {
        http_response_code(503);
        $screen = 'error';
        $error = 'Apache nemůže procházet adresář config/. Zkontroluj jeho přístupová práva.';
    } else {
        $screen = 'setup';
    }
    require __DIR__ . '/view/admin/layout.php';
    exit;
}

if (!is_readable($adminFile)) {
    http_response_code(503);
    $screen = 'error';
    $error = 'Apache nemůže číst config/admin.php. V terminálu spusť: chmod 644 config/admin.php';
    require __DIR__ . '/view/admin/layout.php';
    exit;
}

$auth = new AdminAuth(require $adminFile, $basePath);
$csrf = $auth->token();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
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
    } elseif (!in_array($action, ['create-content', 'create-translation', 'inline-content', 'create-product', 'inline-product'], true) ||
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

try {
    if (!is_file(__DIR__ . '/config/database.php') || !is_file(__DIR__ . '/vendor/autoload.php')) {
        throw new RuntimeException('Nejdřív nastav databázi a spusť composer install.');
    }
    $db = ConnectionFactory::create(require __DIR__ . '/config/database.php');
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

    if (($method === 'POST' && ($_POST['action'] ?? '') === 'create-product') ||
        ($method !== 'POST' && ($_GET['section'] ?? '') === 'products')) {
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
