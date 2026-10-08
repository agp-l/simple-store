<?php
declare(strict_types=1);

// Exercise the cart without an SQL server or external payment provider.
class MeekroDB
{
    public array $products = [];

    public function queryFirstRow(string $sql, mixed ...$parameters): ?array
    {
        if (!str_contains($sql, 'shop_product_revisions') || !str_contains($sql, 'published=1') ||
            !str_contains($sql, 'active_product_key IS NOT NULL')) {
            throw new RuntimeException('Checkout must only load the current published product.');
        }
        $product = $this->products[$parameters[0] . ':' . $parameters[1]] ?? null;
        return $product !== null && ($product['published'] ?? 0) == 1 ? $product : null;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\CartService;
use SimpleStore\Checkout\CartSession;
use SimpleStore\Checkout\ShippingPolicy;
use SimpleStore\Product\ProductRepository;

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$invalid = static function (callable $operation): void {
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        return;
    }
    throw new RuntimeException('Invalid checkout input was accepted.');
};

$key = str_repeat('a', 32);
$db = new MeekroDB();
$db->products[$key . ':cs'] = [
    'product_key' => $key, 'slug' => 'batoh', 'name' => 'Batoh',
    'image_path' => 'images/batoh.webp', 'price_czk' => 2400,
    'stock_status' => 'in_stock', 'published' => 1, 'sizes' => '',
    'details_json' => json_encode(['options' => [
        ['name' => 'Barva', 'values' => ['Černá', 'Modrá']],
        ['name' => 'Velikost', 'values' => ['S', 'L']],
    ], 'specifications' => [], 'sections' => [], 'gallery' => []], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
];
$cart = new CartSession('/simple-store/');
$cart->clear();
$service = new CartService(new ProductRepository($db), ['cs']);
$token = $cart->token();
$checkoutKey = $cart->checkoutKey();
$check(strlen($token) === 64 && $cart->validToken($token) && !$cart->validToken('wrong'),
    'Anonymous form CSRF token was not retained.');
$check(strlen($checkoutKey) === 64 && $checkoutKey !== $token,
    'A checkout submission needs a separate idempotency key.');
// Emulate the browser returning the Set-Cookie header on its next HTTP request.
$sessionIdProperty = new ReflectionProperty(CartSession::class, 'requestSessionId');
$sessionIdProperty->setAccessible(true);
$_COOKIE['simple_store_cart'] = $sessionIdProperty->getValue($cart);
$cart = new CartSession('/simple-store/');
$check($cart->validToken($token) && $cart->checkoutKey() === $checkoutKey,
    'A new request did not restore the cart session from its cookie.');
$invalid(static fn () => $service->add($cart, $key, 'cs', [0 => 'Černá'], 1));
$invalid(static fn () => $service->add($cart, $key, 'cs', [0 => 'Černá', 1 => 'Neexistující'], 1));
$invalid(static fn () => $service->add($cart, $key, 'en', [0 => 'Černá', 1 => 'S'], 1));
$check($cart->count() === 0, 'Invalid choices changed the cart.');

$id = $service->add($cart, $key, 'cs', [0 => 'Černá', 1 => 'S'], 2);
$firstAddKey = $cart->checkoutKey();
$sameId = $service->add($cart, $key, 'cs', [0 => 'Černá', 1 => 'S'], 1);
$check($id === $sameId && strlen($id) === 64 && $cart->count() === 3,
    'Equal products and options must merge into one line.');
$check($firstAddKey !== $checkoutKey && $cart->checkoutKey() !== $firstAddKey,
    'Changing a cart must rotate the order submission key.');
session_name('independent_login_test');
session_id('');
if (!session_start()) throw new RuntimeException('Cannot start a second session.');
$loginSessionId = session_id();
$_SESSION['login_marker'] = 'separate';
$blocked = false;
try {
    $cart->state();
} catch (RuntimeException $error) {
    $blocked = true;
}
$check($blocked, 'Cart accepted a simultaneously active login session.');
session_write_close();
session_id('');
$check($cart->count() === 3, 'Cart must survive a switch to the login session.');
session_name('independent_login_test');
session_id($loginSessionId);
if (!session_start()) throw new RuntimeException('Cannot reopen the login session.');
$isolated = ($_SESSION['login_marker'] ?? null) === 'separate' && !isset($_SESSION['checkout_items']);
session_write_close();
session_id('');
$check($isolated,
    'Cart session was replaced by an authentication session.');
$otherId = $service->add($cart, $key, 'cs', [0 => 'Modrá', 1 => 'S'], 1);
$check($id !== $otherId, 'Different variants must not merge.');
$summary = $service->summary($cart);
$check(count($summary['items']) === 2 && $summary['count'] === 4 &&
    $summary['subtotal_czk'] === 9600 && $summary['can_continue'] &&
    $summary['items'][0]['options'] === ['Barva' => 'Černá', 'Velikost' => 'S'],
    'Cart totals or canonical variant selections are wrong.');
$originalDetails = $db->products[$key . ':cs']['details_json'];
$reordered = json_decode($originalDetails, true, 512, JSON_THROW_ON_ERROR);
$reordered['options'] = array_reverse($reordered['options']);
$db->products[$key . ':cs']['details_json'] = json_encode($reordered, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check($service->summary($cart)['can_continue'], 'Reordering options must preserve selected variants.');
$reordered['options'][1]['values'] = ['Modrá'];
$db->products[$key . ':cs']['details_json'] = json_encode($reordered, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$check(!$service->summary($cart)['can_continue'], 'Removed product variants must block checkout.');
$db->products[$key . ':cs']['details_json'] = $originalDetails;

$cart->update($id, 4);
$check($service->summary($cart)['subtotal_czk'] === 12000, 'Quantity change was not repriced.');
$afterQuantityKey = $cart->checkoutKey();
$cart->update($id, 4);
$check($cart->checkoutKey() === $afterQuantityKey,
    'Submitting the same quantity twice must preserve order idempotency.');
$db->products[$key . ':cs']['price_czk'] = 2750;
$check($service->summary($cart)['subtotal_czk'] === 13750,
    'A product price change must be read from the server, not a stale cart snapshot.');
$db->products[$key . ':cs']['stock_status'] = 'out_of_stock';
$summary = $service->summary($cart);
$check($summary['subtotal_czk'] === null && !$summary['can_continue'] && count($summary['issues']) === 2,
    'Unavailable products must block the checkout total.');
$db->products[$key . ':cs']['stock_status'] = 'on_order';
$check($service->summary($cart)['can_continue'], 'Products on order may be purchased.');
$db->products[$key . ':cs']['published'] = 0;
$check(!$service->summary($cart)['can_continue'], 'A hidden product must not be purchased.');
$db->products[$key . ':cs']['published'] = 1;

$db->products[$key . ':cs']['stock_status'] = 'in_stock';
$db->products[$key . ':cs']['stock_quantity'] = 4;
$check(!$service->summary($cart)['can_continue'],
    'A cart with different variants exceeding the shared stock must block checkout.');
$invalid(static fn () => $service->add($cart, $key, 'cs', [0 => 'Černá', 1 => 'S'], 1));
$db->products[$key . ':cs']['stock_quantity'] = 5;
$check($service->summary($cart)['can_continue'], 'Available pieces must permit checkout.');
$db->products[$key . ':cs']['availability_status'] = 'out_of_stock';
$check(!$service->summary($cart)['can_continue'], 'Effective unavailable state must block checkout.');
unset($db->products[$key . ':cs']['availability_status']);
unset($db->products[$key . ':cs']['stock_quantity']);

$cart->remove($otherId);
$invalid(static fn () => $cart->update($id, 100));
$beforeDeliveryKey = $cart->checkoutKey();
$cart->setDelivery(['method' => 'home', 'name' => 'A G', 'company' => ' Dobrodruzi s.r.o. ',
    'email' => 'ag@example.com',
    'phone' => '+420 123 456 789', 'street' => 'Ulice 1', 'city' => 'Praha',
    'postal_code' => '11000', 'country' => 'CZ']);
$check($cart->state()['delivery']['street'] === 'Ulice 1' &&
    $cart->state()['delivery']['company'] === 'Dobrodruzi s.r.o.' &&
    $cart->checkoutKey() !== $beforeDeliveryKey, 'Delivery details did not rotate submission identity.');
$invalid(static fn () => $cart->setDelivery(['method' => 'home', 'name' => 'A G',
    'company' => str_repeat('x', 121), 'email' => 'ag@example.com', 'phone' => '123',
    'street' => 'Ulice 1', 'city' => 'Praha', 'postal_code' => '11000', 'country' => 'CZ']));
$invalid(static fn () => $cart->setDelivery(['method' => 'home', 'email' => 'invalid']));
$check($cart->state()['delivery']['street'] === 'Ulice 1', 'Invalid delivery replaced valid details.');
$pickup = ['method' => 'gls_pickup', 'name' => 'A G', 'email' => 'ag@example.com',
    'phone' => '+420 123 456 789', 'country' => 'CZ', 'pickup_point' => 'GLS ParcelShop Brno',
    'pickup_address' => 'Nádražní 1, 602 00 Brno', 'pickup_code' => 'BRN1'];
$invalid(static fn () => $cart->setDelivery(array_replace($pickup, ['pickup_address' => ''])));
$cart->setDelivery($pickup);
$check($cart->state()['delivery']['pickup_address'] === 'Nádražní 1, 602 00 Brno' &&
    $cart->state()['delivery']['method'] === 'gls_pickup',
    'Pickup point name, address and carrier must survive checkout.');
$cart->setDelivery(array_replace($pickup, [
    'method' => 'zasilkovna_pickup', 'pickup_code' => '',
]));
$check($cart->state()['delivery']['pickup_code'] === '' &&
    $cart->state()['delivery']['pickup_address'] === $pickup['pickup_address'],
    'Manual Packeta pickup must accept a branch address even without its code.');

$policy = new ShippingPolicy();
$check($policy->options() === [] && $policy->quote('home') === null && $policy->quote('pickup') === null,
    'Shipping must not imply an unconfigured price.');
$policy = new ShippingPolicy([
    'home' => ['label' => 'Doručení na adresu', 'price_czk' => 89, 'requires_address' => true],
    'pickup' => ['label' => 'Výdejní místo', 'price_czk' => 0, 'requires_address' => false],
]);
$check(count($policy->options()) === 2 && $policy->quote('home') === 89 && $policy->quote('pickup') === 0,
    'Explicit free pickup should be possible.');
$invalid(static fn () => new ShippingPolicy(['home' => [
    'label' => 'Doručení na adresu', 'price_czk' => -1, 'requires_address' => true,
]]));
$invalid(static fn () => $policy->quote('express'));
$allCarriers = new ShippingPolicy(ShippingPolicy::defaults());
$check(count($allCarriers->options()) === 9 && $allCarriers->quote('gls_pickup') === 59 &&
    $allCarriers->quote('balikovna_pickup') === 120 && $allCarriers->quote('gls_home') === 79 &&
    $allCarriers->quote('ceska_posta_home') === 121,
    'Configured carrier prices differ from the nine requested defaults.');

$cart->clear();
$check($cart->count() === 0 && $cart->state()['delivery'] === null && $cart->validToken($token) &&
    $cart->checkoutKey() !== $checkoutKey,
    'Clearing a cart must renew checkout identity without invalidating cached product forms.');

echo "Checkout cart tests passed.\n";
