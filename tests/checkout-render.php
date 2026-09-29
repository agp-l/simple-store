<?php
declare(strict_types=1);

use SimpleStore\Checkout\ShippingPolicy;
use SimpleStore\Rendering\PageRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

$config = require dirname(__DIR__) . '/config/checkout.example.php';
$options = (new ShippingPolicy($config['shipping_methods']))->options();
if (count($options) !== 9 ||
    !in_array(['code' => 'gls_pickup', 'price_czk' => 59],
        array_map(static fn (array $row): array => ['code' => $row['code'], 'price_czk' => $row['price_czk']], $options), true) ||
    !in_array(['code' => 'ppl_home', 'price_czk' => 99],
        array_map(static fn (array $row): array => ['code' => $row['code'], 'price_czk' => $row['price_czk']], $options), true)) {
    throw new RuntimeException('All nine carrier methods must be offered at documented defaults.');
}

$renderer = new PageRenderer(dirname(__DIR__) . '/view');
$data = [
    'basePath' => '/simple-store/', 'language' => 'cs',
    'cartUrl' => '/simple-store/cs/kosik', 'checkoutUrl' => '/simple-store/cs/pokladna',
    'cartToken' => 'test-token', 'shippingOptions' => $options, 'shippingConfigured' => true,
    'packetaApiKey' => 'ABCDEF1234567890',
    'packetaOptions' => \SimpleStore\Checkout\PacketaPickupPoint::options(),
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
if (!str_contains($shipping, 'value="ppl_home"') || !str_contains($shipping, '99 Kč') ||
    !str_contains($shipping, 'value="gls_pickup"') || !str_contains($shipping, '59 Kč') ||
    !str_contains($shipping, 'Vybrat adresu výdejního místa') ||
    !str_contains($shipping, 'Pokračovat k platbě') ||
    !str_contains($shipping, 'Vybrat výdejní místo Zásilkovny') ||
    !str_contains($shipping, 'name="packeta_point_id"') ||
    !str_contains($shipping, 'assets/packeta-checkout.js') ||
    !str_contains($shipping, 'widget.packeta.com/v6/www/js/library.js')) {
    throw new RuntimeException('Carrier choices, pickup map and prices are missing.');
}
$data['customerAddresses'] = [['id' => 4, 'label' => 'Domů', 'street' => 'Polní 1',
    'city' => 'Brno', 'country' => 'CZ']];
ob_start();
$renderer->render('shipping', $data);
$shipping = ob_get_clean();
if (!str_contains($shipping, '?step=shipping&amp;address=4') ||
    !str_contains($shipping, 'Polní 1')) {
    throw new RuntimeException('The saved address cannot be selected in checkout.');
}

ob_start();
$renderer->render('payment', $data + ['delivery' => ['method' => 'home'], 'selectedShippingPrice' => 99]);
$payment = ob_get_clean();
if (!str_contains($payment, 'Bankovní převod není nastavený') ||
    str_contains($payment, 'Zkontrolovat objednávku')) {
    throw new RuntimeException('Checkout without bank settings must explain what to configure.');
}

$bankData = $data + ['delivery' => ['method' => 'ppl_home', 'name' => 'Eva Nová',
    'street' => 'Polní 1', 'postal_code' => '11000', 'city' => 'Praha',
    'email' => 'eva@example.org', 'phone' => '123'], 'selectedShippingPrice' => 99];
$bankData['bankConfigured'] = true;
$bankData['checkoutReady'] = true;
ob_start();
$renderer->render('payment', $bankData);
$payment = ob_get_clean();
if (!str_contains($payment, 'Zkontrolovat objednávku') ||
    str_contains($payment, 'Bankovní převod není nastavený')) {
    throw new RuntimeException('Configured bank transfer must continue without a terms page.');
}
ob_start();
$renderer->render('review', $bankData);
$review = ob_get_clean();
if (!str_contains($review, 'Objednat s povinností platby') ||
    str_contains($review, 'name="terms"') ||
    str_contains($review, 'Vytvořit testovací objednávku')) {
    throw new RuntimeException('Bank checkout without a terms page must submit as a real order.');
}

$testData = $data + ['delivery' => ['method' => 'ppl_home', 'name' => 'Eva Nová',
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
