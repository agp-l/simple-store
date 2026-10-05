<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$site = ['default_language' => 'cs'];
$basePath = '/simple-store/';
$accountUrl = $basePath . 'account.php';
$screen = 'register';
$registrationAllowed = true;
$error = '';
$csrf = 'test-token';
$chrome = [];
$user = null;
$section = 'overview';
$addresses = [];
$orders = [];
$editAddress = null;
$priceDisplay = null;

ob_start();
require dirname(__DIR__) . '/view/account/layout.php';
$html = ob_get_clean();
if (substr_count($html, '<footer class="foot">') !== 1 ||
    substr_count($html, '<header id="nahoru"') !== 1 ||
    !str_contains($html, 'name="password_confirm"') ||
    !str_contains($html, 'href="/simple-store/account.php"')) {
    throw new RuntimeException('Registration must reuse the store shell and provide an account form.');
}
$checkoutReturn = '/simple-store/cs/pokladna?step=shipping';
ob_start();
require dirname(__DIR__) . '/view/account/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'action="/simple-store/account.php?mode=register&amp;checkout=1"') ||
    !str_contains($html, '/simple-store/account.php?checkout=1')) {
    throw new RuntimeException('Registration during checkout lost its return destination.');
}

$screen = 'account';
$section = 'addresses';
$user = ['id' => 7, 'email' => 'test@example.org', 'display_name' => 'Míša <script>', 'phone' => ''];
$addresses = [['id' => 4, 'label' => 'Domů', 'recipient' => 'Míša', 'street' => 'Polní 1',
    'city' => 'Brno', 'postal_code' => '602 00', 'country' => 'CZ', 'phone' => '']];
ob_start();
require dirname(__DIR__) . '/view/account/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'aria-current="page"') ||
    !str_contains($html, 'name="action" value="address-save"') ||
    !str_contains($html, 'name="action" value="address-remove"') ||
    str_contains($html, '<script>')) {
    // Real shell scripts carry a defer attribute; an unescaped user script does not.
    throw new RuntimeException('Customer address view is incomplete or rendered an unsafe user name.');
}
$section = 'orders';
$orderHistory = false;
$orderOffset = 0;
$orderPage = ['items' => [], 'nextOffset' => null];
$orders = [];
$orderDetail = null;
ob_start();
require dirname(__DIR__) . '/view/account/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Aktivní objednávky') ||
    !str_contains($html, 'Historie') || !str_contains($html, 'name="action" value="claim-order"')) {
    throw new RuntimeException('Customer orders screen is incomplete.');
}
$orderDetail = ['id' => 8, 'order_number' => 'DB-20260929-8',
    'status' => 'ready_to_ship', 'payment_status' => 'paid', 'created_at' => '2026-09-29',
    'items' => [], 'subtotal_czk' => 100, 'shipping_czk' => 90, 'total_czk' => 190,
    'shipping' => ['label' => 'Zásilkovna', 'pickup_point' => 'Praha', 'pickup_address' => 'Ulice 1']];
$orderTrackingUrl = 'https://tracking.packeta.com/cs/?id=1234567890';
ob_start();
require dirname(__DIR__) . '/view/account/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Připraveno k odeslání') ||
    !str_contains($html, 'tracking.packeta.com/cs/?id=1234567890')) {
    throw new RuntimeException('Customer order did not show fulfillment and tracking.');
}
$orderDetail = null;
$section = 'settings';
ob_start();
require dirname(__DIR__) . '/view/account/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'name="action" value="email"') ||
    !str_contains($html, 'name="action" value="password"')) {
    throw new RuntimeException('Customer email or password settings are missing.');
}
echo "Customer rendering tests passed.\n";
