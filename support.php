<?php
declare(strict_types=1);

use SimpleStore\AfterSales\CaseNotificationService;
use SimpleStore\AfterSales\CaseRepository;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Checkout\CartSession;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Content\SiteCopyRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Navigation\StorefrontMenus;
use SimpleStore\Navigation\UrlManager;

$site = require __DIR__ . '/src/bootstrap.php';
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/support.php'), '/') . '/';
$supportUrl = $basePath . 'support.php';
$language = $site['default_language'];
$pageTitle = 'Reklamace a vrácení · dobrodruzi.cz';
$pageDescription = 'Podání a stav reklamace či vrácení zboží';
$heroTitle = 'Pomůžeme s objednávkou';
$heroSubtitle = 'Reklamace a vrácení zboží na jednom místě.';
$privatePage = true;
$panelStylesheet = true;
$compactHeader = true;
$skipTarget = 'obsah';
$bodyView = __DIR__ . '/view/after-sales/public.php';
$case = $order = null;
$cases = [];
$error = '';
$mailState = 'missing';
$preview = null;
$ready = false;
$cartCount = 0;
$primaryMenu = $utilityMenu = $footerMenu = [];
$footerTitle = 'Informace';
$requestKey = bin2hex(random_bytes(32));
$orderToken = is_string($_GET['order'] ?? null) ? $_GET['order'] : '';
$caseToken = is_string($_GET['case'] ?? null) ? $_GET['case'] : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if (!is_file(__DIR__ . '/config/database.php') || !is_file(__DIR__ . '/vendor/autoload.php')) {
        throw new RuntimeException('Obchod zatím není připravený. Kontaktujte nás prosím e-mailem.');
    }
    $db = ConnectionFactory::create(require __DIR__ . '/config/database.php');
    $siteCopy = (new SiteCopyRepository($db))->load($language);
    $repo = new CaseRepository($db);
    if (!$repo->installed()) throw new RuntimeException('Formulář se připravuje. Kontaktujte nás prosím e-mailem.');
    $url = new UrlManager($basePath . $language, $_SERVER['SCRIPT_NAME'] ?? '/support.php',
        $site['languages'], $language);
    $chrome = StorefrontMenus::load($db, $url,
        new ContentRepository($db, $site['languages'], $site['revision_limit']), new CategoryRepository($db));
    $primaryMenu = $chrome['primaryMenu'] ?? [];
    $utilityMenu = $chrome['utilityMenu'] ?? [];
    $footerMenu = $chrome['footerMenu'] ?? [];
    $footerTitle = $chrome['footerTitle'] ?? 'Informace';
    $manualPrimaryMenu = $chrome['manualPrimaryMenu'] ?? false;
    $manualUtilityMenu = $chrome['manualUtilityMenu'] ?? false;
    $manualFooterMenu = $chrome['manualFooterMenu'] ?? false;
    $cartCount = (new CartSession($basePath))->count();

    // A dedicated session keeps the withdrawal confirmation independent of the cart and account.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_id('');
    session_name('simple_store_support');
    $incomingId = $_COOKIE['simple_store_support'] ?? null;
    if (is_string($incomingId) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $incomingId) === 1) {
        session_id($incomingId);
    }
    session_set_cookie_params(['lifetime' => 0, 'path' => $basePath,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) throw new RuntimeException('Formulář teď není dostupný. Zkus to později.');
    $_SESSION['support_csrf'] ??= bin2hex(random_bytes(32));
    $csrf = $_SESSION['support_csrf'];

    if ($method === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) {
            http_response_code(403);
            throw new InvalidArgumentException('Platnost formuláře vypršela. Obnov stránku.');
        }
        $action = $_POST['action'] ?? null;
        if ($action === 'review-withdrawal') {
            $token = $_POST['order_token'] ?? null;
            $line = $_POST['item_line'] ?? null;
            $quantity = $_POST['quantity'] ?? null;
            $requestKey = $_POST['request_key'] ?? null;
            if (!is_string($token) || !is_string($line) || !is_string($quantity) ||
                !is_string($requestKey) || preg_match('/^[a-f0-9]{64}$/D', $requestKey) !== 1 ||
                !is_string($_POST['description'] ?? null) || strlen($_POST['description']) > 5000 ||
                !is_string($_POST['delivered_on'] ?? null) || strlen($_POST['delivered_on']) > 10 ||
                ($order = $repo->orderForToken($token)) === null ||
                !ctype_digit($line) || !isset($order['items'][(int) $line - 1]) ||
                !ctype_digit($quantity) || (int) $quantity < 1 ||
                (int) $quantity > (int) ($order['items'][(int) $line - 1]['quantity'] ?? 0)) {
                throw new InvalidArgumentException('Zkontroluj zboží a množství v objednávce.');
            }
            $_SESSION['support_draft'] = ['order_token' => $token, 'kind' => 'withdrawal',
                'item_line' => $line, 'quantity' => $quantity,
                'description' => $_POST['description'], 'delivered_on' => $_POST['delivered_on'],
                'request_key' => $requestKey, 'created_at' => time()];
            header('Location: ' . $supportUrl . '?confirm=1', true, 303);
            exit;
        }
        if ($action === 'confirm-withdrawal') {
            $draft = $_SESSION['support_draft'] ?? null;
            if (!is_array($draft) || !is_int($draft['created_at'] ?? null) ||
                $draft['created_at'] < time() - 1800 || ($_POST['confirmed'] ?? '') !== '1') {
                throw new InvalidArgumentException('Potvrzení vypršelo. Otevři formulář znovu.');
            }
            $case = $repo->submit($draft['order_token'], $draft + ['confirmed' => '1']);
            unset($_SESSION['support_draft']);
        } elseif ($action === 'submit-complaint') {
            $token = $_POST['order_token'] ?? null;
            if (!is_string($token) || $repo->orderForToken($token) === null) {
                throw new InvalidArgumentException('Soukromý odkaz na objednávku není platný.');
            }
            $case = $repo->submit($token, array_replace($_POST, ['kind' => 'complaint']));
        } else {
            http_response_code(400);
            throw new InvalidArgumentException('Neznámá akce formuláře.');
        }
        try { (new CaseNotificationService($db))->receipt($case); }
        catch (Throwable $mailError) { error_log('Case receipt queue: ' . $mailError->getMessage()); }
        header('Location: ' . $supportUrl . '?case=' . rawurlencode((string) $case['case_token']), true, 303);
        exit;
    }
    if ($method !== 'GET') {
        http_response_code(405);
        throw new InvalidArgumentException('Tato metoda není podporovaná.');
    }
    if (($_GET['confirm'] ?? null) === '1') {
        $draft = $_SESSION['support_draft'] ?? null;
        if (!is_array($draft) || !is_int($draft['created_at'] ?? null) ||
            $draft['created_at'] < time() - 1800 ||
            ($order = $repo->orderForToken((string) ($draft['order_token'] ?? ''))) === null) {
            throw new InvalidArgumentException('Potvrzení vypršelo. Otevři objednávku a začni znovu.');
        }
        $preview = $draft;
    } elseif ($caseToken !== '') {
        $case = $repo->byToken($caseToken);
        if ($case === null) {
            http_response_code(404);
            throw new InvalidArgumentException('Případ nebyl nalezen. Zkontroluj soukromý odkaz.');
        }
        $mailState = (new CaseNotificationService($db))->state($case);
    } elseif ($orderToken !== '') {
        $order = $repo->orderForToken($orderToken);
        if ($order === null) {
            http_response_code(404);
            throw new InvalidArgumentException('Objednávka nebyla nalezena. Zkontroluj soukromý odkaz.');
        }
        $cases = $repo->forOrderToken($orderToken);
        $ready = $order['status'] !== 'test' &&
            ($order['status'] !== 'cancelled' || $order['payment_status'] === 'paid');
    }
} catch (InvalidArgumentException $exception) {
    if (http_response_code() < 400) http_response_code(422);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('After-sales page: ' . $exception->getMessage());
    http_response_code(503);
    $error = 'Formulář se nepodařilo načíst. Zkus to prosím později nebo kontaktuj obchod.';
}

require __DIR__ . '/view/shell.php';
