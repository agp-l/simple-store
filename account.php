<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Checkout\CartSession;
use SimpleStore\Checkout\PacketaShipmentRepository;
use SimpleStore\Customer\CustomerAuth;
use SimpleStore\Customer\CustomerRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Navigation\StorefrontMenus;
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Pricing\BitcoinPriceDisplay;
use SimpleStore\Auth\PasswordResetService;

$site = require __DIR__ . '/src/bootstrap.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/account.php'), '/') . '/';
$accountUrl = $basePath . 'account.php';
$checkoutReturn = ($_GET['checkout'] ?? '') === '1'
    ? $basePath . $site['default_language'] . '/pokladna?step=shipping' : $accountUrl;
$screen = 'login';
$error = '';
$chrome = [];
$cartCount = 0;
$csrf = '';
$resetToken = '';
$user = null;
$addresses = [];
$orders = [];
$orderPage = ['items' => [], 'nextOffset' => null];
$orderDetail = null;
$priceDisplay = null;
$orderTrackingUrl = null;
$orderHistory = ($_GET['history'] ?? '') === '1';
$orderOffset = filter_var($_GET['offset'] ?? '0', FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 100000]]);
if ($orderOffset === false) $orderOffset = 0;
$editAddress = null;
$registrationAllowed = (bool) ($site['customer_registration'] ?? true);
$section = $_GET['section'] ?? 'overview';
if (!is_string($section) || !in_array($section, ['overview', 'orders', 'addresses', 'payments', 'settings'], true)) {
    $section = 'overview';
}
$mode = $_GET['mode'] ?? 'login';
if (!is_string($mode) || !in_array($mode, ['login', 'register', 'forgot', 'reset'], true)) $mode = 'login';

if (!is_file(__DIR__ . '/config/database.php') || !is_file(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(503);
    $screen = 'error';
    $error = 'Nejdřív nastav config/database.php a spusť composer install.';
    require __DIR__ . '/view/account/layout.php';
    exit;
}

try {
    $db = ConnectionFactory::create(require __DIR__ . '/config/database.php');
    if ($section === 'orders' || $section === 'overview') {
        $checkoutFile = __DIR__ . '/config/checkout.php';
        $exampleCheckout = require __DIR__ . '/config/checkout.example.php';
        $checkoutConfig = is_file($checkoutFile) ? require $checkoutFile : $exampleCheckout;
        $checkoutConfig = (new CheckoutSettingsRepository($db))->load(
            CheckoutSettingsRepository::withDefaults($checkoutConfig, $exampleCheckout));
        $priceDisplay = BitcoinPriceDisplay::fromSettings($db, $checkoutConfig);
    }
    $menuUrl = new UrlManager($basePath . $site['default_language'],
        $_SERVER['SCRIPT_NAME'] ?? '/account.php', $site['languages'], $site['default_language']);
    $chrome = StorefrontMenus::load($db, $menuUrl,
        new ContentRepository($db, $site['languages'], $site['revision_limit']),
        new CategoryRepository($db));
    $cartCount = (new CartSession($basePath))->count();
    $customers = new CustomerRepository($db);
    if (!$customers->installed()) {
        http_response_code(503);
        $screen = 'setup';
        require __DIR__ . '/view/account/layout.php';
        exit;
    }
    $auth = new CustomerAuth($customers, $basePath);
    $csrf = $auth->token();
    $user = $auth->user();
    $input = static function (string $key): string {
        $value = $_POST[$key] ?? null;
        if (!is_string($value)) throw new InvalidArgumentException('Neplatné pole: ' . $key . '.');
        return $value;
    };
    $redirect = static function (string $url): void {
        header('Location: ' . $url, true, 303);
        exit;
    };

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$auth->validToken($_POST['csrf'] ?? null)) {
            http_response_code(403);
            throw new InvalidArgumentException('Platnost formuláře vypršela. Obnov stránku.');
        }
        $action = $input('action');
        try {
            if ($action === 'reset-request' && $user === null) {
                (new PasswordResetService($db))->request('customer', $input('email'), 'account.php');
                $redirect($accountUrl . '?mode=forgot&sent=1');
            }
            if ($action === 'reset-complete' && $user === null) {
                (new PasswordResetService($db))->complete('customer', $input('token'),
                    $input('password'), $input('password_confirm'));
                $redirect($accountUrl . '?mode=reset&done=1');
            }
            if ($action === 'register' && $user === null && $registrationAllowed) {
                $password = $input('password');
                if ($password !== $input('password_confirm')) {
                    throw new InvalidArgumentException('Hesla se neshodují.');
                }
                $customers->register($input('email'), $password, $input('display_name'));
                $auth->signIn($input('email'), $password);
                $redirect($checkoutReturn);
            }
            if ($action === 'login' && $user === null) {
                if (!$auth->signIn($input('email'), $input('password'))) {
                    throw new InvalidArgumentException('E-mail nebo heslo není správné. Po pěti pokusech počkej pět minut.');
                }
                $redirect($checkoutReturn);
            }
            if ($action === 'logout' && $user !== null) {
                $auth->signOut();
                $redirect($accountUrl);
            }
            if ($user === null) {
                http_response_code(403);
                throw new InvalidArgumentException('Přihlas se ke svému účtu.');
            }
            $userId = (int) $user['id'];
            if ($action === 'profile') {
                $customers->updateProfile($userId, $input('display_name'), $input('phone'));
                $redirect($accountUrl . '?section=settings&saved=1');
            }
            if ($action === 'email') {
                $newEmail = $input('new_email');
                if (strcasecmp(trim($newEmail), trim($input('email_confirm'))) !== 0) {
                    throw new InvalidArgumentException('Nové e-mailové adresy se neshodují.');
                }
                $customers->changeEmail($userId, $input('current_password'), $newEmail);
                $redirect($accountUrl . '?section=settings&saved=1');
            }
            if ($action === 'password') {
                $replacement = $input('new_password');
                if ($replacement !== $input('password_confirm')) {
                    throw new InvalidArgumentException('Nová hesla se neshodují.');
                }
                $customers->changePassword($userId, $input('current_password'), $replacement);
                $auth->signIn($user['email'], $replacement);
                $redirect($accountUrl . '?section=settings&saved=1');
            }
            if ($action === 'address-save' || $action === 'address-remove') {
                $idRaw = $input('id');
                $id = $idRaw === '' ? null : filter_var($idRaw, FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]);
                if ($id === false || ($action === 'address-remove' && $id === null)) {
                    throw new InvalidArgumentException('Neplatná adresa.');
                }
                if ($action === 'address-remove') {
                    $customers->removeAddress($userId, $id);
                } else {
                    $fields = [];
                    foreach (['label', 'recipient', 'street', 'city', 'postal_code', 'country', 'phone'] as $field) {
                        $fields[$field] = $input($field);
                    }
                    $customers->saveAddress($userId, $id, $fields);
                }
                $redirect($accountUrl . '?section=addresses&saved=1');
            }
            if ($action === 'claim-order') {
                $reference = $input('order_reference');
                $path = parse_url($reference, PHP_URL_PATH);
                $token = preg_match('/^[a-f0-9]{64}$/D', $reference) === 1 ? $reference :
                    (is_string($path) && preg_match('~(?:^|/)([a-f0-9]{64})/?$~D', $path, $matches) === 1
                        ? $matches[1] : '');
                $customers->claimGuestOrder($userId, $token);
                $redirect($accountUrl . '?section=orders&saved=1');
            }
            http_response_code(400);
            throw new InvalidArgumentException('Neznámá akce účtu.');
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }

    if ($user !== null) {
        $screen = 'account';
        $user = $auth->user();
        if ($section === 'addresses' || $section === 'overview') {
            $addresses = $customers->addresses((int) $user['id']);
        }
        if ($section === 'orders' || $section === 'overview') {
            if ($section === 'orders') {
                $orderPage = $customers->orderPage((int) $user['id'], $orderHistory, $orderOffset);
                $orders = $orderPage['items'];
                if (isset($_GET['id'])) {
                    $rawId = $_GET['id'];
                    $id = is_string($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1]]) : false;
                    $orderDetail = $id === false ? null : $customers->order((int) $user['id'], $id);
                    if ($orderDetail === null) {
                        http_response_code(404);
                        $error = 'Objednávka nebyla nalezena.';
                    } elseif (in_array($orderDetail['shipping']['method'] ?? '',
                        ['zasilkovna_pickup', 'zasilkovna_home'], true)) {
                        $shipments = new PacketaShipmentRepository($db);
                        if ($shipments->installed()) {
                            $orderTrackingUrl = PacketaShipmentRepository::trackingUrl($shipments->find($id));
                        }
                    }
                }
            } else {
                $orders = $customers->orderPage((int) $user['id'], false, 0, 5)['items'];
            }
        }
        if ($section === 'addresses' && isset($_GET['edit'])) {
            $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($editId !== false) $editAddress = $customers->address((int) $user['id'], $editId);
        }
    } else {
        $screen = match ($mode) {
            'register' => $registrationAllowed ? 'register' : 'login',
            'forgot' => 'reset-request',
            'reset' => 'reset-complete',
            default => 'login',
        };
        if ($screen === 'reset-complete') {
            $resetToken = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && is_string($_POST['token'] ?? null)
                ? $_POST['token'] : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
            if (($_GET['done'] ?? '') !== '1' && !(new PasswordResetService($db))->valid('customer', $resetToken)) {
                $error = 'Odkaz pro obnovu vypršel nebo už byl použit.';
                $resetToken = '';
            }
        }
    }
} catch (Throwable $exception) {
    error_log((string) $exception);
    if ($error === '') $error = $site['debug'] ? (string) $exception : 'Účet se nepodařilo načíst.';
    $screen = 'error';
    http_response_code(http_response_code() >= 400 ? http_response_code() : 503);
}

require __DIR__ . '/view/account/layout.php';
