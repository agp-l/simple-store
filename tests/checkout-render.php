<?php
declare(strict_types=1);

use SimpleStore\Checkout\ShippingPolicy;
use SimpleStore\Rendering\PageRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

$config = require dirname(__DIR__) . '/config/checkout.example.php';
$options = (new ShippingPolicy($config['shipping_methods']))->options();
if (count($options) !== 1 || $options[0]['code'] !== 'home' || $options[0]['price_czk'] !== 99) {
    throw new RuntimeException('Default checkout must offer home delivery at its documented price.');
}

$renderer = new PageRenderer(dirname(__DIR__) . '/view');
$data = [
    'basePath' => '/simple-store/', 'language' => 'cs',
    'cartUrl' => '/simple-store/cs/kosik', 'checkoutUrl' => '/simple-store/cs/pokladna',
    'cartToken' => 'test-token', 'shippingOptions' => $options, 'shippingConfigured' => true,
    'bankConfigured' => false, 'checkoutReady' => false, 'termsUrl' => '',
    'checkout' => [
        'items' => [['line_id' => str_repeat('a', 64), 'name' => 'Batoh', 'slug' => 'batoh',
            'image_path' => '', 'quantity' => 1, 'unit_price_czk' => 1000,
            'line_total_czk' => 1000, 'issue' => '', 'options' => []]],
        'count' => 1, 'subtotal_czk' => 1000, 'issues' => [], 'can_continue' => true,
    ],
];

ob_start();
$renderer->render('cart', $data);
$cart = ob_get_clean();
if (!str_contains($cart, 'href="/simple-store/cs/pokladna?step=shipping"') ||
    str_contains($cart, 'Doprava na adresu zatím není nastavená')) {
    throw new RuntimeException('Cart must lead to home delivery before payment settings are complete.');
}

ob_start();
$renderer->render('shipping', $data);
$shipping = ob_get_clean();
if (!str_contains($shipping, 'value="home"') || !str_contains($shipping, '99 Kč') ||
    !str_contains($shipping, 'Pokračovat k platbě')) {
    throw new RuntimeException('Home delivery form must show the configured price and continue action.');
}

ob_start();
$renderer->render('payment', $data + ['delivery' => ['method' => 'home'], 'selectedShippingPrice' => 99]);
$payment = ob_get_clean();
if (!str_contains($payment, 'Platba nebo obchodní podmínky zatím nejsou nastavené') ||
    str_contains($payment, 'Zkontrolovat objednávku')) {
    throw new RuntimeException('Only order submission needs the real bank and legal settings.');
}

$testData = $data + ['delivery' => ['method' => 'home', 'name' => 'Eva Nová',
    'street' => 'Polní 1', 'postal_code' => '11000', 'city' => 'Praha',
    'email' => 'eva@example.org', 'phone' => '123'], 'selectedShippingPrice' => 99];
$testData['testCheckout'] = true;
$testData['checkoutReady'] = true;
ob_start();
$renderer->render('review', $testData);
$review = ob_get_clean();
if (!str_contains($review, 'Vytvořit testovací objednávku') ||
    str_contains($review, 'Objednat s povinností platby') ||
    str_contains($review, 'name="terms"')) {
    throw new RuntimeException('Local preview must clearly distinguish test orders from payments.');
}
ob_start();
$renderer->render('complete', $testData + ['order' => ['payment_method' => 'test',
    'order_number' => 'DB-TEST', 'payment_status' => 'test']]);
$complete = ob_get_clean();
if (!str_contains($complete, 'Testovací objednávka vytvořena') ||
    str_contains($complete, 'Naskenovat QR platbu') || str_contains($complete, 'Číslo účtu')) {
    throw new RuntimeException('Test order confirmation must never suggest a bank payment.');
}

echo "Checkout rendering tests passed.\n";
