<?php
declare(strict_types=1);

// Exercise the real controller, cart, product lookup and order boundary. No writes or payment calls.
final class OrderWriteReached extends RuntimeException {}
class MeekroDB
{
    public int $price = 1800;
    public int $writes = 0;
    public function queryFirstField(string $sql, mixed ...$values): int { return 1; }
    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        return ['product_key' => $values[0], 'slug' => 'bunda', 'name' => 'Bunda', 'image_path' => '',
            'price_czk' => $this->price, 'stock_status' => 'on_order', 'published' => 1, 'sizes' => '',
            'details_json' => '{"options":[],"specifications":[],"sections":[],"gallery":[]}'];
    }
    public function startTransaction(): void { $this->writes++; throw new OrderWriteReached('Reached order persistence.'); }
}
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\{BankTransferPayment, CartService, CartSession, CheckoutController, OrderRepository, ShippingPolicy, PacketaPickupPoint};
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Rendering\PageRenderer;

$db = new MeekroDB();
$cart = new CartSession('/'); $cart->clear();
$lineId = $cart->add(str_repeat('a', 32), 'cs', [], 1);
$delivery = ['method' => 'home', 'name' => 'Eva Test', 'email' => 'eva@example.test', 'phone' => '123456789',
    'street' => 'Test 1', 'city' => 'Praha', 'postal_code' => '11000', 'country' => 'CZ'];
$cart->setDelivery($delivery); $cart->setPaymentMethod('bank_transfer');
$bank = new BankTransferPayment('', '1004823033/3030', 'Test');
$cartService = new CartService(new ProductRepository($db), ['cs']);
$make = static function (int $fee = 79, string $label = 'Doručení domů', ?PacketaPickupPoint $packeta = null) use ($db, $bank, $cart, $cartService): CheckoutController {
    return new CheckoutController(new UrlManager('/cs/pokladna', '/index.php', ['cs'], 'cs'),
        new PageRenderer(dirname(__DIR__) . '/view'), ['basePath' => '/', 'language' => 'cs'], $cart, $cartService,
        new ShippingPolicy(['home' => ['price_czk' => $fee, 'label' => $label, 'requires_address' => true],
            'zasilkovna_pickup' => ['price_czk' => 79, 'label' => 'Zásilkovna', 'requires_address' => false]]),
        new OrderRepository($db, $bank), $bank, null, '', [], [], $packeta);
};
$review = static function (CheckoutController $controller): array {
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_GET = ['step' => 'review']; $_POST = [];
    ob_start(); $controller->handle(['name' => 'checkout']); $html = ob_get_clean();
    preg_match('/name="review_token" value="([a-f0-9]{64})"/', $html, $match);
    return [$html, $match[1] ?? null];
};
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$place = new ReflectionMethod(CheckoutController::class, 'placeOrder'); $place->setAccessible(true);
$reject = static function (CheckoutController $controller, array $post) use ($place, $db): void {
    $_POST = $post; $before = $db->writes;
    try { $place->invoke($controller); throw new RuntimeException('Changed review accepted.'); }
    catch (InvalidArgumentException $expected) {
        if ($db->writes !== $before) throw new RuntimeException('Invalid review reached a database write.');
    }
};
$accept = static function (CheckoutController $controller, string $token) use ($place, $db): void {
    $_POST = ['review_token' => $token]; $before = $db->writes;
    try { $place->invoke($controller); throw new RuntimeException('Missing order write.'); }
    catch (OrderWriteReached $expected) {
        if ($db->writes !== $before + 1) throw new RuntimeException('Valid review did not reach order persistence once.');
    }
};
$controller = $make();
[$html, $token] = $review($controller);
$post = $token === null ? [] : ['review_token' => $token];
$db->price = 2200;
$reject($controller, $post); // Regression: ordinary checkout used to silently place the higher-priced order.
$check($token !== null, 'Own-sale review must carry its approval token.');
$check($cart->count() === 1 && $cart->state()['delivery']['email'] === $delivery['email'], 'Rejected review discarded the cart.');
[$html, $newToken] = $review($controller);
$check(str_contains($html, '2 200 Kč') && $newToken !== $token, 'Changed price was not shown for fresh approval.');
$accept($controller, $newToken);
foreach ([[], ['review_token' => ''], ['review_token' => []], ['review_token' => str_repeat('0', 64)]] as $bad) $reject($controller, $bad);

$reject($make(99), ['review_token' => $newToken]);
$reject($make(79, 'Jiný dopravce'), ['review_token' => $newToken]);
$cart->update($lineId, 2); $reject($controller, ['review_token' => $newToken]);
[, $quantityToken] = $review($controller); $accept($controller, $quantityToken);
$cart->setDelivery(array_replace($delivery, ['street' => 'Nová 2']));
$reject($controller, ['review_token' => $quantityToken]);
[, $addressToken] = $review($controller); $accept($controller, $addressToken);
// A cleared/rebuilt cart is a new checkout attempt even with identical visible values.
$cart->clear(); $cart->add(str_repeat('a', 32), 'cs', [], 2);
$cart->setDelivery(array_replace($delivery, ['street' => 'Nová 2'])); $cart->setPaymentMethod('bank_transfer');
$reject($controller, ['review_token' => $addressToken]);
[, $freshToken] = $review($controller); $accept($controller, $freshToken);
$forged = \SimpleStore\Checkout\CheckoutReview::token($cartService->summary($cart), $cart->state()['delivery'],
    79, 'bank_transfer', json_encode(['', '']), $cart->token(), 'Doručení domů');
$reject($controller, ['review_token' => $forged]); // The browser-visible CSRF token is not a signing secret.

// The public HTTP boundary renders the current review after rejecting an old submission.
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'place', 'csrf' => $cart->token(), 'review_token' => $addressToken];
$before = $db->writes;
ob_start(); $controller->handle(['name' => 'checkout']); $errorHtml = ob_get_clean();
$check(http_response_code() === 422 && $db->writes === $before && str_contains($errorHtml, 'Zkontrolujte souhrn') &&
    str_contains($errorHtml, 'name="review_token" value="' . $freshToken . '"'), 'Stale submission did not return a usable current review.');
$check(!str_contains($errorHtml, $cart->checkoutKey()), 'The server signing key was exposed in HTML.');

// A carrier can change a pickup address while the review page is open.
$cart->setDelivery(array_replace($delivery, ['method' => 'zasilkovna_pickup', 'pickup_code' => '123456',
    'pickup_point' => 'Původní místo', 'pickup_address' => 'Původní 1, Praha, 110 00']));
$pickupController = $make(79, 'Doručení domů', new PacketaPickupPoint('ABCDEF1234567890', static fn (): array => [
    'status' => 200, 'body' => '{"isValid":true,"point":{"name":"Nové místo","address":{"street":"Nová 2","city":"Praha","zip":"110 00","country":"cz"}}}',
]));
[, $pickupToken] = $review($pickupController);
$reject($pickupController, ['review_token' => $pickupToken]);
$check($cart->state()['delivery']['pickup_point'] === 'Nové místo', 'Changed carrier address causes an endless stale-review loop.');
[$pickupHtml, $pickupToken] = $review($pickupController);
$check(str_contains($pickupHtml, 'Nové místo'), 'Updated pickup point is not visible before approval.');
$accept($pickupController, $pickupToken);
echo "Checkout review: stale prices, shipping, quantities, address, attempt, missing tokens and pickup recovery passed.\n";
