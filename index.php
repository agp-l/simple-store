<?php
declare(strict_types=1);

use SimpleStore\Content\ContentRepository;
use SimpleStore\Checkout\BankTransferPayment;
use SimpleStore\Checkout\CartService;
use SimpleStore\Checkout\CartSession;
use SimpleStore\Checkout\CheckoutController;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Checkout\LocalCheckoutPreview;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\ComgatePaymentService;
use SimpleStore\Checkout\GoPayPaymentService;
use SimpleStore\Checkout\BTCPayPaymentService;
use SimpleStore\Checkout\PacketaPickupPoint;
use SimpleStore\Checkout\PplPickupPoint;
use SimpleStore\Checkout\ShippingPolicy;
use SimpleStore\Admin\AdminAuth;
use SimpleStore\Admin\AdminUserRepository;
use SimpleStore\Category\CategoryPath;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Customer\CustomerAuth;
use SimpleStore\Customer\CustomerRepository;
use SimpleStore\Navigation\StorefrontMenus;
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductStockRepository;
use SimpleStore\Product\ProductSupplierLinkRepository;
use SimpleStore\Product\HomepageProductSelection;
use SimpleStore\Rendering\PageRenderer;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Accounting\OrderMailQueue;
use SimpleStore\Accounting\InvoiceRepository;
use SimpleStore\Pricing\BitcoinPriceDisplay;

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
    $stock = new ProductStockRepository($db);
    $stockReady = $stock->installed();
    $productStock = $stockReady ? $stock : null;
    $contents = new ContentRepository($db, $site['languages'], $site['revision_limit']);
    $categories = new CategoryRepository($db);
    $navigation = StorefrontMenus::load($db, $url, $contents, $categories);
    $menus = $navigation['manager'];
    $hasContent = $navigation['hasContent'];
    unset($navigation['manager'], $navigation['hasContent']);
    $shared = array_merge($shared, $navigation);
    if (in_array($route['name'], ['catalog', 'category', 'product'], true)) {
        $checkoutFile = __DIR__ . '/config/checkout.php';
        $exampleCheckout = require __DIR__ . '/config/checkout.example.php';
        $checkoutConfig = is_file($checkoutFile) ? require $checkoutFile : $exampleCheckout;
        $checkoutConfig = (new CheckoutSettingsRepository($db))->load(
            CheckoutSettingsRepository::withDefaults($checkoutConfig, $exampleCheckout));
        $shared['priceDisplay'] = BitcoinPriceDisplay::fromSettings($db, $checkoutConfig);
    }

    // Cart sessions are short-lived and separate from administrator/customer login.
    // Read and close the cart session before opening either account session.
    $cart = new CartSession($url->getBasePath());
    $shared['cartUrl'] = $url->path('kosik');
    $shared['checkoutUrl'] = $url->path('pokladna');
    $shared['cartToken'] = $cart->token();
    $shared['cartCount'] = $cart->count();
    // Public product HTML includes a session-specific CSRF token and cart count.
    header('Cache-Control: private, no-store');

    if (in_array($route['name'], ['cart', 'checkout', 'order'], true)) {
        $checkoutFile = __DIR__ . '/config/checkout.php';
        $exampleCheckout = require __DIR__ . '/config/checkout.example.php';
        $checkoutConfig = is_file($checkoutFile) ? require $checkoutFile : $exampleCheckout;
        $checkoutConfig = (new CheckoutSettingsRepository($db))->load(
            CheckoutSettingsRepository::withDefaults($checkoutConfig, $exampleCheckout));
        $shared['priceDisplay'] = BitcoinPriceDisplay::fromSettings($db, $checkoutConfig);
        $bankSettings = $checkoutConfig['bank_transfer'] ?? [];
        $bank = null;
        if (is_array($bankSettings) &&
            ($bankSettings['account_display'] ?? '') !== '' && ($bankSettings['recipient'] ?? '') !== '') {
            $bank = new BankTransferPayment((string) ($bankSettings['iban'] ?? ''),
                (string) $bankSettings['account_display'], (string) $bankSettings['recipient']);
        }
        $shippingMethods = $checkoutConfig['shipping_methods'] ?? [];
        $shipping = new ShippingPolicy($shippingMethods);
        $packeta = new PacketaPickupPoint((string) ($checkoutConfig['packeta']['api_key'] ?? ''));
        $ppl = new PplPickupPoint((string) ($checkoutConfig['ppl']['widget_key'] ?? ''));
        $dueDays = $bankSettings['payment_due_days'] ?? 7;
        $orders = new OrderRepository($db, $bank, $dueDays, $stock);
        $comgate = null;
        $comgateSettings = $checkoutConfig['comgate'] ?? [];
        if (is_array($comgateSettings) &&
            ($comgateSettings['merchant'] ?? '') !== '' && ($comgateSettings['secret'] ?? '') !== '') {
            $candidate = new ComgatePaymentService($db, $comgateSettings);
            if ($candidate->installed()) $comgate = $candidate;
        }
        $gopay = null;
        $gopaySettings = $checkoutConfig['gopay'] ?? [];
        if (is_array($gopaySettings) &&
            ($gopaySettings['goid'] ?? '') !== '' && ($gopaySettings['client_id'] ?? '') !== '' &&
            ($gopaySettings['client_secret'] ?? '') !== '') {
            $candidate = new GoPayPaymentService($db, $gopaySettings);
            if ($candidate->installed()) $gopay = $candidate;
        }
        $btcpay = null;
        $btcpaySettings = $checkoutConfig['btcpay'] ?? [];
        if (is_array($btcpaySettings) && ($btcpaySettings['server_url'] ?? '') !== '' &&
            ($btcpaySettings['store_id'] ?? '') !== '' && ($btcpaySettings['api_key'] ?? '') !== '') {
            $candidate = new BTCPayPaymentService($db, $btcpaySettings);
            if ($candidate->installed()) $btcpay = $candidate;
        }
        $localPreview = LocalCheckoutPreview::available($_SERVER, (bool) $site['debug'],
            ($checkoutConfig['local_test_checkout'] ?? true) === true);
        $customerId = null;
        $customerProfile = [];
        $customerAddresses = [];
        if ($route['name'] !== 'order' && isset($_COOKIE['simple_store_customer'])) {
            $customers = new CustomerRepository($db);
            if ($customers->installed()) {
                $customerAuth = new CustomerAuth($customers, $url->getBasePath());
                $customerId = $customerAuth->user()['id'] ?? null;
                $customerId = $customerId === null ? null : (int) $customerId;
                if ($customerId !== null) {
                    $customerProfile = $customers->byId($customerId) ?? [];
                    $customerAddresses = $customers->addresses($customerId);
                }
                session_write_close();
                session_id('');
            }
        }
        $taxSettings = (new TaxEvidenceRepository($db))->settings();
        $controller = new CheckoutController($url, $renderer, $shared, $cart,
            new CartService(new ProductRepository($db, $site['languages'], null, 50, $productStock), $site['languages']),
            $shipping, $orders, $bank, $customerId, (string) ($checkoutConfig['terms_url'] ?? ''),
            $localPreview, $customerProfile, $customerAddresses, $packeta, $ppl, null, null,
            new OrderMailQueue($db), (string) ($taxSettings['mail_from'] ?? ''),
            new InvoiceRepository($db), $comgate, $gopay, $btcpay);
        $controller->handle($route);
        exit;
    }

    // An authenticated preview may read drafts; ordinary routes never start an admin session.
    $editRequested = ($_GET['edit'] ?? '') === '1';
    $auth = null;
    if (in_array($route['name'], ['product', 'page', 'post', 'blog', 'catalog', 'category'], true)) {
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
    $shared['canManageMenu'] = $canEdit;
    $shared['adminCreate'] = $canEdit ? ['csrf' => $auth->token(), 'language' => $url->getLanguage()] : null;
    $shared['menuAdminUrl'] = $url->getBasePath() . 'admin.php?' . http_build_query([
        'section' => 'menus', 'language' => $url->getLanguage(), 'slot' => 'primary',
    ]);

    if ($route['name'] === 'catalog' || $route['name'] === 'category') {
        $managingCatalog = ($_GET['manage'] ?? '') === '1';
        if ($managingCatalog && !$canEdit) {
            $renderer->render('not-found', $shared, 404);
            exit;
        }
        $visibility = $_GET['visibility'] ?? 'all';
        if ($managingCatalog && (!is_string($visibility) || !in_array($visibility, ['all', 'draft', 'published'], true))) {
            $renderer->render('not-found', $shared, 400);
            exit;
        }
        $homepageEditing = $route['name'] === 'catalog' && ($_GET['homepage_edit'] ?? '') === '1';
        if ($homepageEditing && (!$canEdit || $managingCatalog)) {
            $renderer->render('not-found', $shared, 404);
            exit;
        }
        $path = $route['path'] ?? null;
        $selected = $path === null ? null : $categories->find($url->getLanguage(), $path);
        if ($path !== null && $selected === null) {
            $renderer->render('not-found', $shared, 404);
            exit;
        }
        $menuRoot = $path === null ? '' :
            ($categories->children($url->getLanguage(), $path) !== [] ? $path : CategoryPath::parent($path));
        $shared['currentCategory'] = $selected;
        $shared['canManageCatalog'] = $canEdit;
        $categoryAdminParams = ['section' => 'categories', 'language' => $url->getLanguage()];
        if ($path !== null) $categoryAdminParams['edit'] = $path;
        $shared['categoryAdminUrl'] = $url->getBasePath() . 'admin.php?' . http_build_query($categoryAdminParams);
        $shared['newSubcategoryUrl'] = $path === null ? '' : $url->getBasePath() . 'admin.php?' . http_build_query([
            'section' => 'categories', 'language' => $url->getLanguage(), 'parent' => $path,
        ]);
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
        $repository = new ProductRepository($db, $site['languages'], null, 50, $productStock);
        $homepage = $route['name'] === 'catalog' ? new HomepageProductSelection($db, $site['languages']) : null;
        $homepageKeys = $homepage?->keys($url->getLanguage());
        $homepageSelectionActive = $homepageKeys !== null && !$managingCatalog &&
            $search === '' && $sort === 'default' && $offset === 0 && ($_GET['all'] ?? '') !== '1';
        $batch = !$hasProducts ? ['items' => [], 'nextOffset' => null] :
            ($homepageSelectionActive
                ? ['items' => $homepage->products($url->getLanguage(), $homepageKeys, $productStock), 'nextOffset' => null]
                : ($managingCatalog
                    ? $repository->managementPage($url->getLanguage(), $path, $search, $visibility, $offset)
                    : $repository->publishedPage($url->getLanguage(), $path, $search, $sort, $offset)));
        if ($homepageEditing) {
            $pick = $_GET['pick'] ?? '';
            $pickOffset = filter_var($_GET['pick_offset'] ?? 0, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 100000]]);
            if (!is_string($pick) || strlen($pick) > 200 || $pickOffset === false) {
                $renderer->render('not-found', $shared, 400);
                exit;
            }
            $pick = trim($pick);
            $candidates = $hasProducts ? $repository->publishedPage($url->getLanguage(), null, $pick, 'name', $pickOffset) :
                ['items' => [], 'nextOffset' => null];
            $shared['homepageEditing'] = true;
            $shared['homepageReady'] = $homepage->installed();
            $shared['homepageKeys'] = $homepageKeys ?? [];
            $shared['homepageSelected'] = $homepageKeys === null ? [] :
                $homepage->products($url->getLanguage(), $homepageKeys, null, true);
            $shared['homepageCandidates'] = $candidates['items'];
            $shared['homepagePick'] = $pick;
            $shared['homepageCandidateNext'] = $candidates['nextOffset'] === null ? '' :
                $url->path() . '?' . http_build_query(['homepage_edit' => '1', 'pick' => $pick,
                    'pick_offset' => $candidates['nextOffset']]);
            $shared['adminCsrf'] = $auth->token();
            $shared['privatePage'] = true;
        }
        $shared['homepageSelectionActive'] = $homepageSelectionActive;
        $shared['homepageConfigured'] = $homepageKeys !== null;
        $shared['products'] = $batch['items'];
        $shared['managingCatalog'] = $managingCatalog;
        $shared['catalogVisibility'] = $visibility;
        $shared['managementCategories'] = $managingCatalog ? $categories->all($url->getLanguage()) : [];
        $shared['privatePage'] = $managingCatalog;
        $shared['productDeleted'] = $managingCatalog && ($_GET['deleted'] ?? '') === '1';
        $shared['searchTerm'] = $search;
        $shared['sortChoice'] = $sort;
        $shared['searchAction'] = $path === null ? $url->path() : $url->category($path);
        $nextUrl = $batch['nextOffset'] === null ? '' : $shared['searchAction'] . '?' . http_build_query(
            array_filter(['manage' => $managingCatalog ? '1' : '', 'visibility' => $managingCatalog && $visibility !== 'all' ? $visibility : '',
                'search' => $search, 'sort' => !$managingCatalog && $sort !== 'default' ? $sort : '',
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
        if ($managingCatalog) {
            $shared['title'] = 'Správa produktů — dobrodruzi.cz';
        } elseif ($homepageEditing) {
            $shared['title'] = 'Výběr produktů na úvodní stránce — dobrodruzi.cz';
        } elseif ($selected !== null) {
            $shared['title'] = $selected['title'] . ' — dobrodruzi.cz';
        }
        $renderer->render('catalog', $shared);
    } elseif ($route['name'] === 'product') {
        $repository = new ProductRepository($db, $site['languages'], null, 50, $productStock);
        $item = $editRequested && $canEdit
            ? $repository->findCurrentBySlug($route['slug'], $url->getLanguage())
            : $repository->findPublished($route['slug'], $url->getLanguage());
        $editMode = $editRequested && $canEdit && $item !== null;
        $supplierLinks = [];
        $supplierLinksReady = false;
        if ($canEdit && $item !== null) {
            $supplierRepository = new ProductSupplierLinkRepository($db);
            $supplierLinksReady = $supplierRepository->installed();
            if ($supplierLinksReady) $supplierLinks = $supplierRepository->forProduct($item['product_key']);
            $shared['editToken'] = $auth->token();
            $shared['privatePage'] = true;
        }
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
            'canEditProduct' => $canEdit, 'editMode' => $editMode, 'stockReady' => $stockReady,
            'supplierLinks' => $supplierLinks, 'supplierLinksReady' => $supplierLinksReady,
            'categoryTrail' => $item === null ? [] : $categories->trail(
                $url->getLanguage(), CategoryPath::fromProduct($item)
            ),
        ], $item === null ? 404 : 200);
    } elseif ($route['name'] === 'blog') {
        $managingBlog = ($_GET['manage'] ?? '') === '1';
        if ($managingBlog && !$canEdit) {
            $renderer->render('not-found', $shared, 404);
            exit;
        }
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
        $draftOffset = filter_var($_GET['draft_offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
        if ($offset === false || $draftOffset === false) {
            $renderer->render('not-found', $shared, 400);
            exit;
        }
        $batch = $hasContent ? $contents->publishedPostsPage($url->getLanguage(), $offset)
            : ['items' => [], 'nextOffset' => null];
        $draftBatch = $managingBlog && $hasContent
            ? $contents->unpublishedPostsPage($url->getLanguage(), $draftOffset, 24)
            : ['items' => [], 'nextOffset' => null];
        $nextUrl = $batch['nextOffset'] === null ? '' : $url->path('blog') . '?' . http_build_query([
            'manage' => $managingBlog ? '1' : null, 'draft_offset' => $managingBlog && $draftOffset > 0 ? $draftOffset : null,
            'offset' => $batch['nextOffset'],
        ]);
        $shared['canManageContent'] = $canEdit;
        if (($_GET['partial'] ?? '') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'html' => $renderer->cards('post', $batch['items'], $shared),
                'nextUrl' => $nextUrl,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        }
        $renderer->render('blog', $shared + [
            'title' => $managingBlog ? 'Správa blogu — dobrodruzi.cz' : 'Blog — dobrodruzi.cz',
            'posts' => $batch['items'], 'nextUrl' => $nextUrl,
            'canManageContent' => $canEdit, 'adminCsrf' => $canEdit ? $auth->token() : '',
            'draftPosts' => $draftBatch['items'],
            'draftNextUrl' => $draftBatch['nextOffset'] === null ? '' : $url->path('blog') . '?manage=1&draft_offset=' . $draftBatch['nextOffset'],
            'managingBlog' => $managingBlog, 'privatePage' => $managingBlog,
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
