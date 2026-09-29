<?php
declare(strict_types=1);

class MeekroDB
{
    public function queryFirstField(string $sql, mixed ...$values): int
    {
        return 1;
    }

    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        return [
            'product_key' => $values[0], 'slug' => 'batoh', 'name' => 'Batoh',
            'image_path' => '', 'price_czk' => 1000, 'stock_status' => 'in_stock',
            'published' => 1, 'sizes' => '',
            'details_json' => '{"options":[],"specifications":[],"sections":[],"gallery":[]}',
        ];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\BankTransferPayment;
use SimpleStore\Checkout\CartService;
use SimpleStore\Checkout\CartSession;
use SimpleStore\Checkout\CheckoutController;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Checkout\ShippingPolicy;
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Rendering\PageRenderer;

class CaptureRenderer extends PageRenderer
{
    public string $page = '';
    public array $data = [];

    public function render(string $page, array $data = [], int $status = 200): void
    {
        $this->page = $page;
        $this->data = $data;
    }
}

$db = new MeekroDB();
$cart = new CartSession('/simple-store/');
$cart->clear();
$cart->add(str_repeat('a', 32), 'cs', [], 1);
$cart->setDelivery(['method' => 'home', 'name' => 'Eva Nová', 'email' => 'eva@example.org',
    'phone' => '123', 'street' => 'Polní 1', 'city' => 'Praha',
    'postal_code' => '11000', 'country' => 'CZ']);
$bank = new BankTransferPayment('CZ5855000000001265098001', '1265098001/5500', 'Test');
$url = new UrlManager('/simple-store/cs/pokladna?step=payment', '/simple-store/index.php');
$renderer = new CaptureRenderer(dirname(__DIR__) . '/view');
$controller = new CheckoutController($url, $renderer,
    ['basePath' => '/simple-store/', 'language' => 'cs'], $cart,
    new CartService(new ProductRepository($db), ['cs']),
    new ShippingPolicy(['home' => ['label' => 'Doručení na adresu',
        'price_czk' => 99, 'requires_address' => true]]),
    new OrderRepository($db, $bank), $bank, null, '', false);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['step'] = 'payment';
$controller->handle(['name' => 'checkout']);
if ($renderer->page !== 'payment' || !$renderer->data['checkoutReady'] ||
    $renderer->data['testCheckout'] || !$renderer->data['bankConfigured'] ||
    $renderer->data['termsUrl'] !== '') {
    throw new RuntimeException('Real bank transfer must be available when test mode is off and terms are unset.');
}
$previewEnabled = new CheckoutController($url, $renderer,
    ['basePath' => '/simple-store/', 'language' => 'cs'], $cart,
    new CartService(new ProductRepository($db), ['cs']),
    new ShippingPolicy(['home' => ['label' => 'Doručení na adresu',
        'price_czk' => 99, 'requires_address' => true]]),
    new OrderRepository($db, $bank), $bank, null, '', true);
$previewEnabled->handle(['name' => 'checkout']);
if (!$renderer->data['checkoutReady'] || $renderer->data['testCheckout']) {
    throw new RuntimeException('Local preview must not replace a configured bank transfer.');
}

echo "Checkout availability tests passed.\n";
