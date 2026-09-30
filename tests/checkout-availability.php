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
use SimpleStore\Checkout\PacketaPickupPoint;
use SimpleStore\Checkout\ShippingPolicy;
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Rendering\PageRenderer;

$db = new MeekroDB();
$cart = new CartSession('/simple-store/');
$cart->clear();
$cart->add(str_repeat('a', 32), 'cs', [], 1);
$cart->setDelivery(['method' => 'home', 'name' => 'Eva Nová', 'email' => 'eva@example.org',
    'phone' => '123', 'street' => 'Polní 1', 'city' => 'Praha',
    'postal_code' => '11000', 'country' => 'CZ']);
$bank = new BankTransferPayment('CZ5855000000001265098001', '1265098001/5500', 'Test');
$url = new UrlManager('/simple-store/cs/pokladna?step=payment', '/simple-store/index.php');
$renderer = new PageRenderer(dirname(__DIR__) . '/view');
$controller = new CheckoutController($url, $renderer,
    ['basePath' => '/simple-store/', 'language' => 'cs'], $cart,
    new CartService(new ProductRepository($db), ['cs']),
    new ShippingPolicy(['home' => ['label' => 'Doručení na adresu',
        'price_czk' => 99, 'requires_address' => true]]),
    new OrderRepository($db, $bank), $bank, null, '', false);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['step'] = 'payment';
ob_start();
$controller->handle(['name' => 'checkout']);
$html = ob_get_clean();
if (!str_contains($html, 'Bankovní převod') ||
    !str_contains($html, 'Zkontrolovat objednávku') ||
    str_contains($html, 'Testovací objednávka') ||
    str_contains($html, 'Bankovní převod není nastavený')) {
    throw new RuntimeException('Real bank transfer must be available when test mode is off and terms are unset.');
}
$previewEnabled = new CheckoutController($url, $renderer,
    ['basePath' => '/simple-store/', 'language' => 'cs'], $cart,
    new CartService(new ProductRepository($db), ['cs']),
    new ShippingPolicy(['home' => ['label' => 'Doručení na adresu',
        'price_czk' => 99, 'requires_address' => true]]),
    new OrderRepository($db, $bank), $bank, null, '', true);
ob_start();
$previewEnabled->handle(['name' => 'checkout']);
$html = ob_get_clean();
if (!str_contains($html, 'Zkontrolovat objednávku') ||
    str_contains($html, 'Testovací objednávka')) {
    throw new RuntimeException('Local preview must not replace a configured bank transfer.');
}

$methods = new ShippingPolicy(ShippingPolicy::defaults());
$withoutKey = new CheckoutController($url, $renderer,
    ['basePath' => '/simple-store/', 'language' => 'cs'], $cart,
    new CartService(new ProductRepository($db), ['cs']),
    $methods, new OrderRepository($db, $bank), $bank, null, '');
$_GET['step'] = 'shipping';
ob_start();
$withoutKey->handle(['name' => 'checkout']);
$html = ob_get_clean();
if (str_contains($html, 'value="zasilkovna_pickup"') || !str_contains($html, 'value="ppl_pickup"')) {
    throw new RuntimeException('Packeta may not be offered before the public widget key is configured.');
}
$withKey = new CheckoutController($url, $renderer,
    ['basePath' => '/simple-store/', 'language' => 'cs'], $cart,
    new CartService(new ProductRepository($db), ['cs']),
    $methods, new OrderRepository($db, $bank), $bank, null, '', false, [], [],
    new PacketaPickupPoint('ABCDEF1234567890'));
ob_start();
$withKey->handle(['name' => 'checkout']);
$html = ob_get_clean();
if (!str_contains($html, 'value="zasilkovna_pickup"') ||
    !str_contains($html, 'data-packeta-key="ABCDEF1234567890"')) {
    throw new RuntimeException('Configured Packeta widget is missing from delivery.');
}
$_POST = ['method' => 'zasilkovna_pickup', 'name' => 'Eva Nová',
    'email' => 'eva@example.org', 'phone' => '123', 'country' => 'CZ',
    'street' => '', 'city' => '', 'postal_code' => '',
    'pickup_point' => 'Podvržená pobočka', 'pickup_address' => 'Podvržená adresa',
    'pickup_code' => 'PODVRH', 'packeta_point_id' => '123456'];
$verified = new CheckoutController($url, $renderer,
    ['basePath' => '/simple-store/', 'language' => 'cs'], $cart,
    new CartService(new ProductRepository($db), ['cs']),
    $methods, new OrderRepository($db, $bank), $bank, null, '', false, [], [],
    new PacketaPickupPoint('ABCDEF1234567890', static fn (): array => [
        'status' => 200, 'body' => '{"isValid":true,"point":{"name":"Praha Hl. nádraží","address":{"street":"Wilsonova 1","city":"Praha","zip":"110 00","country":"cz"}}}',
    ]));
$saveDelivery = new ReflectionMethod(CheckoutController::class, 'saveDelivery');
$saveDelivery->setAccessible(true); // PHP 8.0 still requires this for private methods.
$saveDelivery->invoke($verified);
$delivery = $cart->state()['delivery'];
if ($delivery['pickup_point'] !== 'Praha Hl. nádraží' ||
    $delivery['pickup_address'] !== 'Wilsonova 1, Praha, 110 00' ||
    $delivery['pickup_code'] !== '123456') {
    throw new RuntimeException('Checkout trusted a spoofed pickup label instead of verified Packeta data.');
}
try {
    $saveDelivery->invoke($withoutKey);
    throw new RuntimeException('Checkout accepted Packeta without a widget key.');
} catch (InvalidArgumentException $expected) {
}
$ppl = new \SimpleStore\Checkout\PplPickupPoint('public-ppl-key-123');
$_POST = ['method' => 'ppl_pickup', 'name' => 'Eva Nová',
    'email' => 'eva@example.org', 'phone' => '123', 'country' => 'CZ',
    'street' => '', 'city' => '', 'postal_code' => '',
    'pickup_point' => 'Podvržená pobočka', 'pickup_address' => 'Podvržená adresa',
    'pickup_code' => 'PODVRH', 'ppl_point_code' => 'KM1234567',
    'ppl_point_name' => 'PPL ParcelShop Brno',
    'ppl_point_address' => 'Nádražní 12, Brno, 60200', 'ppl_point_country' => 'CZ'];
$pplCheckout = new CheckoutController($url, $renderer,
    ['basePath' => '/simple-store/', 'language' => 'cs'], $cart,
    new CartService(new ProductRepository($db), ['cs']),
    $methods, new OrderRepository($db, $bank), $bank, null, '', false, [], [], null, $ppl);
$saveDelivery->invoke($pplCheckout);
$delivery = $cart->state()['delivery'];
if ($delivery['pickup_code'] !== 'KM1234567' ||
    $delivery['pickup_point'] !== 'PPL ParcelShop Brno' ||
    $delivery['pickup_address'] !== 'Nádražní 12, Brno, 60200') {
    throw new RuntimeException('PPL selection did not replace manual pickup fields.');
}
$_POST['ppl_point_code'] = 'KM12345';
try {
    $saveDelivery->invoke($pplCheckout);
    throw new RuntimeException('Invalid PPL code reached the checkout session.');
} catch (InvalidArgumentException $expected) {
}
if ($cart->state()['delivery']['pickup_code'] !== 'KM1234567') {
    throw new RuntimeException('Invalid PPL selection overwrote the last valid delivery.');
}
$_POST['ppl_point_code'] = 'KM1234567';
$_POST['ppl_point_country'] = 'AT';
try {
    $saveDelivery->invoke($pplCheckout);
    throw new RuntimeException('Foreign PPL pickup point reached the checkout session.');
} catch (InvalidArgumentException $expected) {
}

echo "Checkout availability tests passed.\n";
