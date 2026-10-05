<?php
declare(strict_types=1);

/**
 * Cross-project contract test with real HTTP, MariaDB, XPUB derivation and webhook handlers.
 * Fiat prices and blockchain observations are simulated; no Bitcoin is sent.
 * A loopback HTTP router instantiates the real Lite controller with test dependencies;
 * the production webhook cron and cURL transport use the explicit localhost opt-in.
 * This does not test Apache routing, public TLS, DNS, Electrum or SMTP delivery.
 * Mail is captured by a temporary sendmail command, never sent to a real recipient.
 *
 * BTCPAY_LITE_PATH=/path/to/BTCPayServerLite MYSQL_TEST_HOST=127.0.0.1 \
 * MYSQL_TEST_PORT=3306 MYSQL_TEST_USER=root MYSQL_TEST_PASSWORD=... \
 * php tests/btcpay-lite-integration.php
 *
 * Needs CREATE/DROP DATABASE permission and installed Composer dependencies in both repos.
 * Creates randomly named schemas and temporary configuration; existing data/config are untouched.
 */

use BtcPayLite\{AddressPaymentObservation, BitcoinAmount, BitcoinMarketDataProvider,
    BlockchainProviderInterface, BtcInvoiceManager, Database, ElectrumRPC, ElectrumWallet,
    GreenfieldApiController, GreenfieldApiException, GreenfieldApiRepository, GreenfieldApiService,
    InstallationManager, PaymentWorker, WebhookCronApplication, WebhookDeliveryRepository, WebhookEndpointPolicy};
use SimpleStore\Accounting\{InvoiceRepository, MailSettingsRepository};
use SimpleStore\Admin\AdminUserRepository;
use SimpleStore\Checkout\{BTCPayPaymentService, OrderRepository};
use SimpleStore\Database\{ConnectionFactory, SchemaUpdater};
use SimpleStore\Product\ProductStockRepository;

function liteIntegrationCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function liteIntegrationRequest(string $method, string $url, string $body = '', array $headers = [], ?string $cookieJar = null): array
{
    $curl = curl_init($url);
    $responseHeaders = [];
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $responseHeaders[] = trim($line); return strlen($line);
        }]);
    if ($method === 'POST') curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    if ($cookieJar !== null) curl_setopt_array($curl, [CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_COOKIEJAR => $cookieJar]);
    $reply = curl_exec($curl);
    if (!is_string($reply)) throw new RuntimeException('Local HTTP request failed: ' . curl_error($curl));
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status' => $code, 'body' => $reply, 'headers' => $responseHeaders];
}

/** Extract the real form rather than guessing its action, CSRF token or order ID. */
function liteIntegrationForm(string $html, string $action): array
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try { $document->loadHTML('<?xml encoding="UTF-8">' . $html); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    foreach ($document->getElementsByTagName('form') as $form) {
        $fields = [];
        foreach (['input', 'button'] as $tag) foreach ($form->getElementsByTagName($tag) as $input) {
            $name = $input->getAttribute('name');
            if ($name !== '') $fields[$name] = $input->getAttribute('value');
        }
        if (($fields['action'] ?? null) === $action) {
            liteIntegrationCheck(strtolower($form->getAttribute('method')) === 'post', 'Admin form must use POST.');
            return ['url' => $form->getAttribute('action'), 'fields' => $fields];
        }
    }
    throw new RuntimeException('Missing rendered admin form: ' . $action);
}

// The same test file acts as the temporary Lite server's router. Production routing,
// authentication, JSON contracts, persistence and XPUB address generation are used.
if (PHP_SAPI === 'cli-server') {
    // Match api.php: dependency diagnostics must not corrupt the JSON response.
    ini_set('display_errors', '0');
    $fixture = require (string) getenv('SIMPLE_STORE_LITE_FIXTURE');
    require $fixture['lite_root'] . '/vendor/autoload.php';
    $db = new Database($fixture['db']['host'], $fixture['lite_schema'], $fixture['db']['user'],
        $fixture['db']['password'], $fixture['db']['port']);
    $rpc = new class('127.0.0.1', 1) extends ElectrumRPC {
        public function call(string $method, array $params = []): mixed {
            throw new RuntimeException('XPUB contract test unexpectedly called Electrum: ' . $method);
        }
    };
    $wallet = new ElectrumWallet($rpc);
    $market = new class implements BitcoinMarketDataProvider {
        public function getRecommendedFees(): array { return ['economy' => 1, 'standard' => 2, 'priority' => 3]; }
        public function getFiatPrice(string $currency): ?float { return $currency === 'CZK' ? 1_000_000.0 : null; }
    };
    $service = new GreenfieldApiService(new GreenfieldApiRepository($db), $db, $wallet,
        new BtcInvoiceManager($wallet, str_repeat('s', 32), $db), '', $fixture['lite_url'],
        new WebhookEndpointPolicy(null, ($fixture['allow_local_webhooks'] ?? false) === true), $market);
    header('Content-Type: application/json');
    try {
        $server = array_replace($_SERVER, ['SCRIPT_NAME' => '/greenfield.php']);
        $result = (new GreenfieldApiController($service))->handleServerRequest($server, (string) file_get_contents('php://input'));
        http_response_code($result['status_code']);
        echo json_encode($result['body'], JSON_THROW_ON_ERROR);
    } catch (GreenfieldApiException $error) {
        http_response_code($error->getHttpStatus());
        echo json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR);
    }
    return true;
}

$storeRoot = dirname(__DIR__);
$liteRoot = realpath((string) (getenv('BTCPAY_LITE_PATH') ?: dirname($storeRoot) . '/BTCPayServerLite'));
liteIntegrationCheck(is_string($liteRoot) && is_file($liteRoot . '/vendor/autoload.php'),
    'Set BTCPAY_LITE_PATH to an installed BTCPayServerLite checkout.');
require $storeRoot . '/vendor/autoload.php';
require $liteRoot . '/vendor/autoload.php';

$connection = ['host' => getenv('MYSQL_TEST_HOST') ?: '127.0.0.1',
    'port' => (int) (getenv('MYSQL_TEST_PORT') ?: 3306), 'user' => getenv('MYSQL_TEST_USER') ?: 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD')];
$suffix = bin2hex(random_bytes(5));
$storeSchema = 'simple_store_lite_test_' . $suffix;
$liteSchema = 'btcpay_store_test_' . $suffix;
$temporary = sys_get_temp_dir() . '/simple-store-lite-' . $suffix;
mkdir($temporary, 0700);
$processes = [];
$admin = new PDO('mysql:host=' . $connection['host'] . ';port=' . $connection['port'],
    $connection['user'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function liteIntegrationFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    liteIntegrationCheck(is_resource($socket), 'Cannot allocate a local HTTP port.');
    $name = stream_socket_get_name($socket, false); fclose($socket);
    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

function liteIntegrationStart(array $command, string $log, array $environment): mixed
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'],
        2 => ['file', $log, 'a']], $pipes, null, array_replace(getenv(), $environment));
    liteIntegrationCheck(is_resource($process), 'Cannot start local PHP HTTP server.');
    return $process;
}

function liteIntegrationRemove(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) liteIntegrationRemove($entry->getPathname());
    rmdir($path);
}

try {
    $admin->exec('CREATE DATABASE `' . $storeSchema . '` CHARACTER SET utf8mb4');
    $admin->exec('CREATE DATABASE `' . $liteSchema . '` CHARACTER SET utf8mb4');
    $storeDb = ConnectionFactory::create($connection + ['database' => $storeSchema]);
    (new SchemaUpdater($storeDb, $storeRoot . '/database/schema.sql'))->apply();
    $liteDb = new Database($connection['host'], $liteSchema, $connection['user'], $connection['password'], $connection['port']);
    $pdo = $liteDb->getPdo();
    foreach (InstallationManager::splitSqlStatements((string) file_get_contents($liteRoot . '/sql.sql')) as $sql) $pdo->exec($sql);
    // Public, empty BIP32 test-vector XPUB. No seed or funded wallet is used.
    $xpub = 'xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8';
    $insert = $pdo->prepare("INSERT INTO stores (id,name,api_key,address_source,xpub) VALUES (?, ?, ?, 'xpub', ?)");
    foreach (['integration', 'other'] as $id) $insert->execute([$id, $id, $id . '-key', $xpub]);

    $liteUrl = 'http://127.0.0.1:' . liteIntegrationFreePort();
    $shopUrl = 'http://127.0.0.1:' . liteIntegrationFreePort();
    $secret = 'test-only-webhook-secret';
    $settings = ['enabled' => true, 'server_url' => $liteUrl, 'store_id' => 'integration',
        'api_key' => 'integration-key', 'webhook_secret' => $secret,
        'return_base_url' => $shopUrl];
    $fixturePath = $temporary . '/fixture.php';
    file_put_contents($fixturePath, '<?php return ' . var_export(['lite_root' => $liteRoot,
        'db' => $connection, 'lite_schema' => $liteSchema, 'lite_url' => $liteUrl,
        'allow_local_webhooks' => true], true) . ';');
    $webRoot = $temporary . '/shop'; mkdir($webRoot); mkdir($webRoot . '/config');
    symlink($storeRoot . '/src', $webRoot . '/src');
    symlink($storeRoot . '/vendor', $webRoot . '/vendor');
    // Run the exact current callback/return code, isolated from the user's config.
    foreach (['btcpay-callback.php', 'btcpay-return.php'] as $file) copy($storeRoot . '/' . $file, $webRoot . '/' . $file);
    copy($storeRoot . '/config/checkout.example.php', $webRoot . '/config/checkout.example.php');
    file_put_contents($webRoot . '/config/database.php', '<?php return ' . var_export($connection + ['database' => $storeSchema], true) . ';');
    file_put_contents($webRoot . '/config/checkout.php', '<?php return ' . var_export(['btcpay' => $settings], true) . ';');
    // Exercise the production admin entry point with the user's /simple-store/ cookie/form path.
    $adminRoot = $webRoot . '/simple-store'; mkdir($adminRoot);
    foreach (['src', 'vendor', 'config'] as $directory) symlink($webRoot . '/' . $directory, $adminRoot . '/' . $directory);
    symlink($storeRoot . '/view', $adminRoot . '/view');
    copy($storeRoot . '/admin.php', $adminRoot . '/admin.php');
    $storeDb->insert('shop_checkout_settings', ['id' => 1,
        'settings_json' => json_encode(['btcpay' => $settings], JSON_THROW_ON_ERROR)]);
    (new AdminUserRepository($storeDb))->createAdmin('integration-admin', password_hash('test-only-password', PASSWORD_DEFAULT));
    $mailbox = $temporary . '/mailbox';
    $sendmail = $temporary . '/sendmail';
    file_put_contents($sendmail, "#!/bin/sh\ncat >> '" . $mailbox . "'\nprintf '\\n--MAIL-END--\\n' >> '" . $mailbox . "'\n");
    chmod($sendmail, 0700);
    $processes[] = liteIntegrationStart([PHP_BINARY, '-S', substr($liteUrl, 7), __FILE__],
        $temporary . '/lite.log', ['SIMPLE_STORE_LITE_FIXTURE' => $fixturePath]);
    $processes[] = liteIntegrationStart([PHP_BINARY, '-d', 'sendmail_path=' . $sendmail,
        '-S', substr($shopUrl, 7), '-t', $webRoot], $temporary . '/shop.log', []);
    foreach ([$liteUrl . '/api/v1/health', $shopUrl . '/btcpay-callback.php'] as $url) {
        $ready = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            try { liteIntegrationRequest('GET', $url); $ready = true; break; }
            catch (Throwable) { usleep(50000); }
        }
        liteIntegrationCheck($ready, 'HTTP server did not start: ' . $url);
    }

    $webhookEndpoint = $liteUrl . '/api/v1/stores/integration/webhooks';
    $webhookHeaders = ['Authorization: token integration-key', 'Content-Type: application/json'];
    foreach (['http://public.example.test/hook', 'http://localhost.evil.test/hook',
        'http://192.168.1.2/hook', 'http://user@localhost/hook'] as $invalidUrl) {
        $rejected = liteIntegrationRequest('POST', $webhookEndpoint,
            json_encode(['url' => $invalidUrl], JSON_THROW_ON_ERROR), $webhookHeaders);
        liteIntegrationCheck($rejected['status'] === 400, 'Local opt-in accepted a non-loopback webhook.');
    }
    $webhookUrl = str_replace('127.0.0.1', 'localhost', $shopUrl) . '/btcpay-callback.php';
    $createdWebhook = liteIntegrationRequest('POST', $webhookEndpoint,
        json_encode(['url' => $webhookUrl, 'secret' => $secret], JSON_THROW_ON_ERROR), $webhookHeaders);
    $webhook = json_decode($createdWebhook['body'], true, 32, JSON_THROW_ON_ERROR);
    liteIntegrationCheck($createdWebhook['status'] === 200 && ($webhook['url'] ?? null) === $webhookUrl &&
        ($webhook['secret'] ?? null) === $secret &&
        (int) $pdo->query('SELECT COUNT(*) FROM webhooks')->fetchColumn() === 1,
        'HTTP localhost webhook could not be registered through the real Lite policy/API.');
    echo "[PASS] HTTP localhost webhook registration with explicit opt-in; remote/private HTTP rejected\n";
    $templates = [];
    foreach (MailSettingsRepository::EVENTS as $code => $definition) $templates[$code] = [
        'enabled' => '1', 'subject' => $definition['subject'], 'message' => $definition['message']];
    (new MailSettingsRepository($storeDb))->save(['from_email' => 'shop@example.test',
        'from_name' => 'Integration fixture', 'reply_to' => '', 'public_base_url' => 'https://shop.example.test',
        'automatic_enabled' => '1', 'templates' => $templates]);
    $productKey = bin2hex(random_bytes(16));
    $storeDb->insert('product_revisions', ['product_key' => $productKey, 'active_product_key' => $productKey,
        'language' => 'cs', 'revision_number' => 1, 'slug' => 'integration-tent', 'active_slug' => 'integration-tent',
        'name' => 'Stan', 'category' => 'vybaveni', 'price_czk' => 500, 'description' => '',
        'image_path' => '', 'stock_status' => 'in_stock', 'published' => 1]);
    $stock = new ProductStockRepository($storeDb); $stock->ensure($productKey); $stock->setAvailable($productKey, 0, 10);
    $orders = new OrderRepository($storeDb, null, 7, $stock);
    $payments = new BTCPayPaymentService($storeDb, $settings);
    liteIntegrationCheck($payments->canInitiate(), 'HTTP return URL disabled BTCPay checkout.');
    $items = [['product_key' => $productKey, 'language' => 'cs', 'slug' => 'integration-tent',
        'name' => 'Stan', 'quantity' => 2, 'unit_price_czk' => 500, 'image_path' => '', 'options' => []]];
    $shipping = ['method' => 'gls_home', 'label' => 'GLS', 'name' => 'Test Buyer', 'street' => 'Test 1',
        'city' => 'Praha', 'postal_code' => '11000', 'country' => 'CZ'];
    $submission = bin2hex(random_bytes(32));
    $order = $orders->create(null, 'buyer@example.test', $items, $shipping, 79, $submission, 'btcpay');
    liteIntegrationCheck($payments->receiptUrl($order, 'cs') ===
        $shopUrl . '/cs/objednavka/' . $order['order_token'], 'Receipt URL requires HTTPS on localhost.');
    liteIntegrationCheck($orders->create(null, 'buyer@example.test', $items, $shipping, 79, $submission, 'btcpay')['id'] === $order['id'],
        'Repeated checkout created a second order.');
    $checkout = $payments->initiate($order);
    $state = $payments->state((int) $order['id']); $invoiceId = (string) $state['invoice_id'];
    liteIntegrationCheck($checkout === $liteUrl . '/pay?id=' . $invoiceId && $payments->initiate($order) === $checkout,
        'Lite checkout link was not accepted/reused.');
    liteIntegrationCheck((int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() === 1,
        'Repeated checkout created a second Lite invoice.');
    liteIntegrationCheck((int) $pdo->query('SELECT expires_at-created_at FROM invoices')->fetchColumn() === 172800,
        'Shop invoice does not allow two days for on-chain payment.');
    $auth = ['Authorization: token integration-key'];
    $statusUrl = $liteUrl . '/api/v1/stores/integration/invoices/' . $invoiceId;
    $remote = json_decode(liteIntegrationRequest('GET', $statusUrl, '', $auth)['body'], true, 32, JSON_THROW_ON_ERROR);
    liteIntegrationCheck(in_array($remote['amount'], ['1079', '1079.00'], true) && $remote['currency'] === 'CZK' &&
        $remote['metadata']['orderId'] === $order['order_number'] && $remote['status'] === 'New',
        'Lite did not retain original CZK amount/order metadata.');
    $methods = json_decode(liteIntegrationRequest('GET', $statusUrl . '/payment-methods', '', $auth)['body'], true, 32, JSON_THROW_ON_ERROR);
    liteIntegrationCheck($methods[0]['amount'] === '0.00107900' && $methods[0]['paymentMethodId'] === 'BTC-CHAIN' &&
        str_starts_with($methods[0]['paymentLink'], 'bitcoin:') && $methods[0]['destination'] !== '',
        'Lite did not expose a usable Bitcoin payment method/BIP21 amount.');
    liteIntegrationCheck(liteIntegrationRequest('GET', $statusUrl, '', ['Authorization: token wrong-key'])['status'] === 401 &&
        liteIntegrationRequest('GET', str_replace('/integration/', '/other/', $statusUrl), '', ['Authorization: token other-key'])['status'] === 404,
        'Lite leaked an invoice across store authentication boundaries.');
    echo "[PASS] Real HTTP create/read/payment-methods, CZK metadata, XPUB/BIP21, scoped auth, one order/invoice\n";

    $adminUrl = $shopUrl . '/simple-store/admin.php';
    $adminCookies = $temporary . '/admin.cookies'; $anonymousCookies = $temporary . '/anonymous.cookies';
    $login = liteIntegrationForm(liteIntegrationRequest('GET', $adminUrl, '', [], $adminCookies)['body'], 'login');
    $loggedIn = liteIntegrationRequest('POST', $shopUrl . $login['url'], http_build_query(array_replace($login['fields'],
        ['username' => 'integration-admin', 'password' => 'test-only-password'])), [], $adminCookies);
    liteIntegrationCheck($loggedIn['status'] === 303 && in_array('Location: /simple-store/admin.php', $loggedIn['headers'], true),
        'Production administrator login did not retain the subdirectory session.');
    $detailUrl = $adminUrl . '?section=orders&id=' . $order['id'];
    $detail = liteIntegrationRequest('GET', $detailUrl, '', [], $adminCookies);
    $refreshForm = liteIntegrationForm($detail['body'], 'btcpay-refresh');
    liteIntegrationCheck($detail['status'] === 200 && $refreshForm['url'] === '/simple-store/admin.php?section=orders&id=' . $order['id'] &&
        $refreshForm['fields']['id'] === (string) $order['id'], 'Admin payment form lost its session path or order ID.');
    $refreshUrl = $shopUrl . $refreshForm['url'];
    $refresh = static fn (array $overrides = []): array => liteIntegrationRequest('POST', $refreshUrl,
        http_build_query(array_replace($refreshForm['fields'], $overrides)), [], $adminCookies);
    $anonymous = liteIntegrationForm(liteIntegrationRequest('GET', $adminUrl, '', [], $anonymousCookies)['body'], 'login');
    $blocked = liteIntegrationRequest('POST', $refreshUrl, http_build_query(array_replace($refreshForm['fields'],
        ['csrf' => $anonymous['fields']['csrf']])), [], $anonymousCookies);
    liteIntegrationCheck($blocked['status'] === 403 && str_contains($blocked['body'], 'přihlášení správce') &&
        $refresh(['csrf' => 'wrong-token'])['status'] === 403 && $refresh(['action' => 'unknown-admin-action'])['status'] === 403,
        'Payment refresh bypassed administrator authentication, CSRF or the action allowlist.');
    $checked = $refresh();
    liteIntegrationCheck($checked['status'] === 303 && in_array('Location: /simple-store/admin.php?section=orders&id=' . $order['id'] .
        '&payment_checked=1', $checked['headers'], true) && $orders->findById((int) $order['id'])['payment_status'] === 'pending',
        'Logged-in administrator cannot refresh the pending BTCPay invoice through the real entry point.');
    $checkedDetail = liteIntegrationRequest('GET', $detailUrl . '&payment_checked=1', '', [], $adminCookies);
    liteIntegrationCheck($checkedDetail['status'] === 200 && str_contains($checkedDetail['body'], 'Stav platby byl ověřen přímo u BTCPay Server'),
        'Admin refresh did not show its successful result.');
    // The other refresh actions must reach their order validation, without querying unrelated providers.
    foreach (['comgate-refresh' => 'Comgate', 'gopay-refresh' => 'GoPay'] as $action => $provider) {
        $invalid = $refresh(['action' => $action]);
        liteIntegrationCheck($invalid['status'] === 422 && str_contains($invalid['body'], 'transakce ' . $provider),
            'Authenticated ' . $provider . ' refresh was blocked or accepted an unrelated BTCPay order.');
    }
    echo "[PASS] Production admin login/form/BTCPay refresh under /simple-store/; anonymous, invalid CSRF/action and wrong provider rejected\n";

    $token = (string) $storeDb->queryFirstField('SELECT return_token FROM shop_btcpay_payments WHERE order_id=%i', $order['id']);
    $return = liteIntegrationRequest('GET', $shopUrl . '/btcpay-return.php?token=' . $token);
    liteIntegrationCheck($return['status'] === 303 &&
        in_array('Location: /cs/objednavka/' . $order['order_token'], $return['headers'], true) &&
        $orders->findById((int) $order['id'])['payment_status'] === 'pending' &&
        liteIntegrationRequest('GET', $shopUrl . '/btcpay-return.php?token=' . str_repeat('f', 64))['status'] === 404,
        'Browser return changed payment state or accepted an unknown token.');
    $callback = static function (array $event, ?string $signature = null) use ($shopUrl, $secret): array {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        return liteIntegrationRequest('POST', $shopUrl . '/btcpay-callback.php', $body,
            ['Content-Type: application/json', 'BTCPay-Sig: ' . ($signature ?? 'sha256=' . hash_hmac('sha256', $body, $secret))]);
    };
    $event = ['invoiceId' => $invoiceId, 'storeId' => 'integration', 'type' => 'InvoiceSettled'];
    liteIntegrationCheck($callback($event, 'sha256=' . str_repeat('0', 64))['status'] === 401 &&
        $callback(array_replace($event, ['storeId' => 'other']))['status'] === 400 &&
        $callback(array_replace($event, ['invoiceId' => 'unknown_invoice']))['status'] === 503 &&
        $callback($event)['status'] === 200 && $orders->findById((int) $order['id'])['payment_status'] === 'pending',
        'Callback accepted a forged signature/store/ID or trusted a signed event instead of the API.');
    echo "[PASS] Real return/callback endpoints: private token, HMAC rejection, store/unknown ID, signed event is only a hint\n";

    $observations = new class implements BlockchainProviderInterface {
        public int $confirmed = 0; public int $mempool = 0;
        public ?int $receivedConfirmed = null; public ?int $receivedPending = null;
        public function maxObservationDurationSeconds(): int { return 1; }
        public function observeAddress(string $address, int $expectedSatoshis = 0): AddressPaymentObservation {
            return new AddressPaymentObservation($address, $this->confirmed, $this->mempool,
                $this->confirmed + $this->mempool, time(), $this->receivedConfirmed, $this->receivedPending);
        }
    };
    $outbox = new WebhookDeliveryRepository($liteDb);
    $worker = new PaymentWorker($liteDb, $observations, $outbox);
    $processor = new WebhookCronApplication(['db_host' => $connection['host'], 'db_port' => $connection['port'],
        'db_name' => $liteSchema, 'db_user' => $connection['user'], 'db_pass' => $connection['password'],
        'allow_local_webhooks' => true]);
    $expectedSats = BitcoinAmount::fromBtc($methods[0]['amount'])->satoshis();
    $observations->mempool = $expectedSats;
    liteIntegrationCheck($worker->run(1)['deliveries_queued'] === 1 && $processor->run()['deliveries_delivered'] === 1 &&
        $payments->state((int) $order['id'])['status'] === 'processing' &&
        $orders->findById((int) $order['id'])['payment_status'] === 'pending',
        'Simulated mempool payment was not Processing/pending.');
    $observations->confirmed = intdiv($expectedSats, 2); $observations->mempool = 0;
    $pdo->prepare('UPDATE invoices SET next_check_at=0,last_checked_at=NULL WHERE id=?')->execute([$invoiceId]);
    $partial = $worker->run(1);
    liteIntegrationCheck($partial['scanned'] === 1 && $partial['failed'] === 0 &&
        $partial['deliveries_queued'] === 0 && $callback($event)['status'] === 200 &&
        $payments->state((int) $order['id'])['status'] === 'processing' &&
        $orders->findById((int) $order['id'])['payment_status'] === 'pending',
        'A confirmed partial Bitcoin payment settled the order or repeated its Processing event.');
    // Authenticated API responses must still match the saved order snapshot.
    $originalMetadata = (string) $pdo->query('SELECT metadata FROM invoices')->fetchColumn();
    foreach ([['orderId' => 'OTHER-ORDER'], ['_btcpaylite_original_currency' => 'EUR'],
        ['_btcpaylite_original_amount' => '1078.00']] as $mismatch) {
        $metadata = array_replace(json_decode($originalMetadata, true, 32, JSON_THROW_ON_ERROR), $mismatch);
        $pdo->prepare('UPDATE invoices SET metadata=? WHERE id=?')->execute([json_encode($metadata, JSON_THROW_ON_ERROR), $invoiceId]);
        liteIntegrationCheck($callback($event)['status'] === 503 && $orders->findById((int) $order['id'])['payment_status'] === 'pending',
            'Mismatched authenticated invoice data paid an order.');
    }
    $pdo->prepare('UPDATE invoices SET metadata=? WHERE id=?')->execute([$originalMetadata, $invoiceId]);
    $pdo->prepare('UPDATE invoices SET store_id=? WHERE id=?')->execute(['other', $invoiceId]);
    liteIntegrationCheck($callback($event)['status'] === 503, 'Invoice reassigned to another store was accepted.');
    $pdo->prepare('UPDATE invoices SET store_id=? WHERE id=?')->execute(['integration', $invoiceId]);
    echo "[PASS] Real worker/outbox/webhook Processing and confirmed partial payment stay unpaid; order/currency/amount/store mismatches rejected\n";

    $observations->confirmed = $expectedSats; $observations->mempool = 0;
    // The payment was spent between checks; history still proves the incoming amount.
    $observations->receivedConfirmed = $expectedSats; $observations->receivedPending = 0;
    $observations->confirmed = 0;
    $pdo->prepare('UPDATE invoices SET next_check_at=0,last_checked_at=NULL WHERE id=?')->execute([$invoiceId]);
    liteIntegrationCheck($worker->run(1)['deliveries_queued'] === 1 &&
        $orders->findById((int) $order['id'])['payment_status'] === 'pending',
        'Settled fixture changed the order before an API refresh or webhook.');
    liteIntegrationCheck($refresh()['status'] === 303 && $orders->findById((int) $order['id'])['payment_status'] === 'paid',
        'Administrator refresh did not reconcile a settled invoice through the real BTCPay API.');
    liteIntegrationCheck($processor->run()['deliveries_delivered'] === 1 &&
        $orders->findById((int) $order['id'])['payment_status'] === 'paid',
        'Confirmed simulated payment did not settle the order through the real webhook endpoint.');
    $paidAt = $orders->findById((int) $order['id'])['payment_paid_at'];
    $settlementPayload = (string) $pdo->query("SELECT payload FROM webhook_deliveries WHERE event_type='InvoiceSettled'")->fetchColumn();
    $settlementSignature = 'sha256=' . hash_hmac('sha256', $settlementPayload, $secret);
    liteIntegrationCheck($pdo->query("SELECT last_primary_ip FROM webhook_deliveries WHERE event_type='InvoiceSettled'")->fetchColumn() === '127.0.0.1',
        'Production cURL transport did not use the pinned localhost address.');
    for ($i = 0; $i < 3; $i++) liteIntegrationCheck(liteIntegrationRequest('POST', $shopUrl . '/btcpay-callback.php',
        $settlementPayload, ['BTCPay-Sig: ' . $settlementSignature])['status'] === 200, 'Settlement replay failed.');
    liteIntegrationCheck($orders->findById((int) $order['id'])['payment_paid_at'] === $paidAt &&
        (int) $storeDb->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s', $productKey) === 8 &&
        (int) $storeDb->queryFirstField('SELECT COUNT(*) FROM shop_btcpay_payments WHERE order_id=%i', $order['id']) === 1 &&
        (int) $storeDb->queryFirstField('SELECT COUNT(*) FROM shop_mail_outbox WHERE event_key=%s AND state=%s AND attempts=1', 'paid:' . $order['id'], 'sent') === 1 &&
        substr_count((string) file_get_contents($mailbox), '--MAIL-END--') === 1,
        'Settlement replay repeated inventory/invoice attempts/mail or changed payment timestamp.');
    $documents = new InvoiceRepository($storeDb);
    $seller = ['name' => 'Integration seller', 'ico' => '12345678', 'street' => 'Test 1', 'city' => 'Praha',
        'postal_code' => '11000', 'bank_account' => ''];
    $documents->issue((int) $order['id'], $seller, ['name' => 'Test Buyer']);
    $duplicateDocumentRejected = false;
    try { $documents->issue((int) $order['id'], $seller, ['name' => 'Test Buyer']); }
    catch (InvalidArgumentException) { $duplicateDocumentRejected = true; }
    liteIntegrationCheck($duplicateDocumentRejected && (int) $storeDb->queryFirstField('SELECT COUNT(*) FROM shop_invoices WHERE order_id=%i', $order['id']) === 1,
        'Settled order document guard allowed duplicate issuance.');
    $storedReceipt = $pdo->prepare('SELECT confirmed_balance_sats,confirmed_output_sats FROM invoices WHERE id=?');
    $storedReceipt->execute([$invoiceId]); $receipt = $storedReceipt->fetch(PDO::FETCH_ASSOC);
    liteIntegrationCheck((int) $receipt['confirmed_balance_sats'] === 0 && (int) $receipt['confirmed_output_sats'] === $expectedSats,
        'Spent incoming payment was confused with the current address balance.');
    echo "[PASS] Simulated spent-before-scan receipt -> admin API refresh Settled/paid, signed webhook replay: one stock reservation, invoice, document and captured mail\n";

    $late = $orders->create(null, 'late@example.test', $items, $shipping, 79, bin2hex(random_bytes(32)), 'btcpay');
    $payments->initiate($late); $lateId = (string) $payments->state((int) $late['id'])['invoice_id'];
    $observations->receivedConfirmed = null; $observations->receivedPending = null;
    $observations->confirmed = 0;
    // The expiry fixture is backdated; its webhook must still predate the invoice.
    $pdo->prepare('UPDATE webhooks SET created_at=? WHERE id=?')->execute([time() - 30, $webhook['id']]);
    $pdo->prepare('UPDATE invoices SET created_at=?, expires_at=?, next_check_at=0 WHERE id=?')->execute([time() - 20, time() - 1, $lateId]);
    liteIntegrationCheck($worker->run(1)['expired'] === 1 && $processor->run()['deliveries_delivered'] === 1 &&
        $payments->state((int) $late['id'])['status'] === 'expired' && $orders->findById((int) $late['id'])['payment_status'] === 'pending',
        'Expired invoice did not reconcile as expired/pending.');
    $pendingDocumentRejected = false;
    try { $documents->issue((int) $late['id'], $seller, ['name' => 'Late Buyer']); }
    catch (InvalidArgumentException) { $pendingDocumentRejected = true; }
    liteIntegrationCheck($pendingDocumentRejected, 'Unpaid expired order allowed document issuance.');
    $observations->confirmed = BitcoinAmount::fromBtc($methods[0]['amount'])->satoshis();
    $pdo->prepare('UPDATE invoices SET next_check_at=0,last_checked_at=NULL WHERE id=?')->execute([$lateId]);
    liteIntegrationCheck($worker->run(1)['deliveries_queued'] === 1 && $processor->run()['deliveries_delivered'] === 1 &&
        $orders->findById((int) $late['id'])['payment_status'] === 'paid', 'Late confirmation was lost after expiry.');
    echo "[PASS] Expired stays unpaid/document blocked; simulated late confirmation settles through actual HTTP webhook\n";
    echo "Cross-project integration passed. Bitcoin/fiat simulated; HTTP, SQL, XPUB, callbacks, signatures, order/stock/document/mail guards real.\n";
} catch (Throwable $error) {
    foreach (['lite.log', 'shop.log'] as $log) if (is_file($temporary . '/' . $log)) {
        fwrite(STDERR, "\n" . $log . ":\n" . file_get_contents($temporary . '/' . $log));
    }
    throw $error;
} finally {
    foreach ($processes as $process) { proc_terminate($process); proc_close($process); }
    foreach ([$storeSchema, $liteSchema] as $schema) $admin->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
    liteIntegrationRemove($temporary);
}
