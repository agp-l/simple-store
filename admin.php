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
$notice = '';
$documents = [];
$history = [];
$current = null;
$form = [];
$language = $site['default_language'];
$csrf = '';

if (!is_file($adminFile)) {
    $screen = 'setup';
    require __DIR__ . '/view/admin/layout.php';
    exit;
}

$auth = new AdminAuth(require $adminFile, $basePath);
$csrf = $auth->token();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    if (!$auth->validToken($_POST['csrf'] ?? null)) {
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
    } elseif ($action !== 'save' || !$auth->signedIn()) {
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
    $content = new ContentRepository($db, $site['languages']);
    $screen = 'editor';

    if ($method === 'POST') {
        $key = $_POST['key'] ?? '';
        $revision = $_POST['revision'] ?? '';
        $language = $_POST['language'] ?? '';
        $form = [
            'type' => $_POST['type'] ?? '',
            'language' => $language,
            'slug' => $_POST['slug'] ?? '',
            'title' => $_POST['title'] ?? '',
            'summary' => $_POST['summary'] ?? '',
            'body' => $_POST['body'] ?? '',
            'menu_order' => $_POST['menu_order'] ?? 0,
            'published' => isset($_POST['published']),
            'visible_in_menu' => isset($_POST['visible_in_menu']),
        ];
        try {
            if (!is_string($key) || !is_string($language) || !in_array($language, $site['languages'], true) ||
                !is_string($form['type']) || !is_string($form['slug']) || !is_string($form['title']) ||
                !is_string($form['summary']) || !is_string($form['body']) ||
                !is_string($form['menu_order']) ||
                !is_string($revision) || ($key !== '' && (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
                    filter_var($revision, FILTER_VALIDATE_INT) === false))) {
                throw new InvalidArgumentException('Neplatný dokument, jazyk nebo číslo revize.');
            }
            $saved = $content->saveRevision($form, $key === '' ? null : $key,
                $key === '' ? null : (int) $revision);
            header('Location: ' . $adminUrl . '?' . http_build_query([
                'key' => $saved['document_key'], 'language' => $saved['language'], 'saved' => 1,
            ]), true, 303);
            exit;
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $error = $exception->getMessage() === 'This document changed since you opened it. Reload before saving.'
                ? 'Dokument se mezitím změnil. Znovu ho načti před uložením.'
                : ($site['debug'] ? $exception->getMessage() : 'Nepodařilo se uložit obsah. Zkontroluj údaje a zkus to znovu.');
            $form['document_key'] = is_string($key) ? $key : '';
            $form['revision_number'] = is_string($revision) ? $revision : '';
            if (is_string($key) && preg_match('/^[a-f0-9]{32}$/D', $key) === 1 &&
                is_string($language) && in_array($language, $site['languages'], true)) {
                $current = $content->currentDocument($key, $language);
                $history = $content->history($key, $language);
            }
        }
    } else {
        $key = $_GET['key'] ?? '';
        $language = $_GET['language'] ?? $site['default_language'];
        if (!is_string($key) || !is_string($language) || !in_array($language, $site['languages'], true) ||
            ($key !== '' && preg_match('/^[a-f0-9]{32}$/D', $key) !== 1)) {
            throw new InvalidArgumentException('Neplatný dokument nebo jazyk.');
        }
        if ($key !== '') {
            $current = $content->currentDocument($key, $language);
            if ($current === null) {
                throw new InvalidArgumentException('Dokument v tomto jazyce neexistuje.');
            }
            $history = $content->history($key, $language);
            $form = $current;
            if (isset($_GET['restore'])) {
                $number = filter_var($_GET['restore'], FILTER_VALIDATE_INT);
                $old = $number === false ? null : $content->revision($key, $language, (int) $number);
                if ($old === null) {
                    throw new InvalidArgumentException('Požadovaná revize neexistuje.');
                }
                $form = array_merge($old, [
                    'document_key' => $key,
                    'revision_number' => $current['revision_number'],
                ]);
                $notice = 'Zobrazuje se starší verze. Uložením vznikne nová revize; nic se nemaže.';
            }
        } else {
            $type = $_GET['type'] ?? 'page';
            if (!in_array($type, ['page', 'post'], true)) {
                throw new InvalidArgumentException('Neplatný druh obsahu.');
            }
            $form = ['type' => $type, 'language' => $language, 'published' => true,
                'visible_in_menu' => $type === 'page'];
        }
    }

    if (isset($_GET['saved'])) {
        $notice = 'Uloženo jako nová revize.';
    }
    $documents = $content->currentDocuments();
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(500);
    $screen = 'error';
    $error = $site['debug'] ? (string) $exception : 'Administraci se nepodařilo načíst.';
}

require __DIR__ . '/view/admin/layout.php';
