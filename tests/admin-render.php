<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$basePath = '/shop/';
$adminUrl = $basePath . 'admin.php';
$site = ['languages' => ['cs'], 'default_language' => 'cs'];
$csrf = 'test-token';
$error = '';
$language = 'cs';
$categoryReady = true;
$categoryError = '';
$categoryRows = [['path' => 'spani', 'title' => 'Spaní', 'sort_order' => 1, 'enabled' => 1, 'depth' => 0]];
$selectedCategory = $categoryRows[0];
$newParent = 'spani';
$categories = new class {
    public function find(string $language, string $path): ?array
    {
        return $path === 'spani' ? ['path' => $path] : null;
    }
};

$screen = 'categories';
set_error_handler(static function (int $severity, string $message): never {
    throw new RuntimeException('A shared admin view emitted a PHP warning: ' . $message);
});
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Kategorie a podkategorie') ||
    !str_contains($html, 'value="category-create"') ||
    !str_contains($html, 'value="category-update"') ||
    !str_contains($html, 'aria-current="page"') ||
    substr_count($html, '<footer class="foot">') !== 1 ||
    !str_contains($html, 'class="nav-band"')) {
    throw new RuntimeException('Category administration must provide the new and edit controls.');
}

$screen = 'menus';
$slot = 'utility';
$menuSlots = ['utility' => ['source' => 'content', 'include_blog' => true]];
$activeSlot = $menuSlots[$slot];
$menuReady = true;
$menuError = '';
$menuItems = [];
$selectedItem = null;
$categoryOptions = $categoryRows;
$pageRows = [['document_key' => str_repeat('a', 32), 'revision_number' => 2,
    'title' => 'O nás', 'slug' => 'o-nas', 'published' => 1,
    'visible_in_menu' => 1, 'menu_order' => 5]];
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'value="menu-slot"') ||
    !str_contains($html, 'value="page-menu"') ||
    !str_contains($html, 'Pořadí horních odkazů') ||
    str_contains($html, 'Prozkoumat') || str_contains($html, 'Na cestu')) {
    throw new RuntimeException('Menu administration must include placement and page ordering.');
}
$slot = 'footer';
$menuSlots = ['footer' => ['source' => 'manual', 'title' => 'Informace']];
$activeSlot = $menuSlots[$slot];
$menuItems = [['id' => str_repeat('a', 16), 'label' => 'Kontakt', 'target_type' => 'path',
    'target' => 'kontakt', 'parent_id' => '', 'sort_order' => 10, 'depth' => 0]];
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'name="title"') || !str_contains($html, 'destination_page') ||
    !str_contains($html, 'destination_external') || !str_contains($html, 'Kontakt')) {
    throw new RuntimeException('Footer editing must offer a heading and selectable destinations.');
}
restore_error_handler();

$screen = 'database';
$databaseError = '';
$databaseStatus = ['database' => 'hosting_ag_shop', 'count' => 136,
    'current' => false, 'record' => null];
set_error_handler(static function (int $severity, string $message): never {
    throw new RuntimeException('Database view emitted a PHP warning: ' . $message);
});
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'name="action" value="schema-apply"') ||
    !str_contains($html, 'hosting_ag_shop') || !str_contains($html, 'name="csrf" value="test-token"') ||
    !str_contains($html, '?section=database')) {
    throw new RuntimeException('Database update screen is not integrated into the admin panel.');
}
$databaseStatus['current'] = true;
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (str_contains($html, 'name="action" value="schema-apply"') || !str_contains($html, 'Aktuální')) {
    throw new RuntimeException('Current schema should not offer another update.');
}
restore_error_handler();

$screen = 'orders';
$ordersReady = true;
$orderError = '';
$orderBaseUrl = $adminUrl . '?section=orders';
$statusFilter = 'pending';
$ordersPreviousUrl = '';
$ordersNextUrl = '';
$orderPage = ['items' => [], 'nextOffset' => null];
$order = [
    'id' => 12, 'order_number' => 'DB-20260929-1', 'created_at' => '2026-09-29 11:00:00',
    'status' => 'awaiting_payment', 'payment_method' => 'bank_transfer',
    'payment_status' => 'pending', 'total_czk' => 1350, 'subtotal_czk' => 1250,
    'shipping_czk' => 100, 'customer_email' => 'customer@example.test',
    'variable_symbol' => '1234567890', 'payment_due_at' => '2026-10-06 11:00:00',
    'shipping' => ['label' => 'Kurýr', 'recipient' => '<img src=x onerror=alert(1)>',
        'street' => 'Ulice 1', 'postal_code' => '10000', 'city' => 'Praha', 'country' => 'CZ'],
    'payment_details' => ['account_display' => '123/0100', 'iban' => 'CZ0000000000000000000000'],
    'items' => [['name' => 'Batoh', 'quantity' => 1, 'unit_price_czk' => 1250, 'options' => []]],
];
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'name="action" value="mark-order-paid"') ||
    !str_contains($html, 'name="action" value="set-order-status"') ||
    !str_contains($html, 'name="csrf" value="test-token"') ||
    !str_contains($html, 'bank_checked') || !str_contains($html, '&lt;img src=x onerror=alert(1)&gt;') ||
    str_contains($html, '<img src=x onerror=alert(1)>')) {
    throw new RuntimeException('Order detail must require explicit bank verification and escape customer data.');
}
$order['shipping'] = ['method' => 'zasilkovna_pickup', 'label' => 'Zásilkovna',
    'recipient' => 'Eva Nová', 'phone' => '+420123456789',
    'pickup_code' => '123456', 'pickup_point' => 'Praha', 'pickup_address' => 'Ulice 1, Praha, 110 00',
    'pickup_verified' => true];
$packetaReady = true;
$packetaConfigured = true;
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Podání zásilky Zásilkovně') ||
    !str_contains($html, 'Nejdřív ověř platbu')) {
    throw new RuntimeException('Packeta dispatch should wait for the payment.');
}
$order['payment_status'] = 'paid';
$fulfillmentSourceReady = true;
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (str_contains($html, 'name="action" value="mark-order-paid"') ||
    !str_contains($html, 'name="action" value="set-order-status"') ||
    !str_contains($html, 'Zaplaceno') ||
    !str_contains($html, 'name="action" value="packeta-create"') ||
    !str_contains($html, 'Původní místo: Praha (ID 123456)') ||
    !str_contains($html, 'value="ready_to_ship"') ||
    !str_contains($html, 'name="fulfillment_source"') ||
    !str_contains($html, 'value="external"')) {
    throw new RuntimeException('Paid bank transfers must not show the confirmation form.');
}
$order['shipping'] = ['method' => 'gls_pickup', 'label' => 'GLS ParcelShop',
    'recipient' => 'Eva Nová', 'phone' => '+420777123456',
    'pickup_code' => '26711-GLSCZ_DEPO47', 'pickup_point' => 'Brno',
    'pickup_address' => 'Nádražní 1, Brno, 602 00'];
$carrierReady = true;
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'name="action" value="carrier-save"') ||
    !str_contains($html, 'name="street" value="Nádražní 1"') ||
    !str_contains($html, 'name="postal_code" value="602 00"') ||
    !str_contains($html, 'PSD(ID místa)')) {
    throw new RuntimeException('GLS order must offer carrier dispatch preparation with the selected address.');
}
$carrierShipment = ['status' => 'draft', 'method' => 'gls_pickup',
    'draft' => ['street' => 'Nádražní 1', 'city' => 'Brno', 'postal_code' => '60200']];
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'carrier_csv=1') ||
    !str_contains($html, 'name="action" value="carrier-register"')) {
    throw new RuntimeException('Saved draft must be exportable and record the real carrier number.');
}
$carrierShipment = null;
$order['shipping'] = ['method' => 'balikovna_pickup', 'label' => 'Balíkovna',
    'recipient' => 'Eva Nová', 'phone' => '+420777123456',
    'pickup_code' => 'B10000', 'pickup_postal_code' => '11000',
    'pickup_point' => 'Praha', 'pickup_address' => 'Národní 1, 110 00 Praha'];
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'ID Balíkovny B10000') ||
    !str_contains($html, 'name="city" value="Praha"')) {
    throw new RuntimeException('Balíkovna dispatch must use the selected point ID and city.');
}
$order['shipping'] = ['method' => 'zasilkovna_pickup', 'label' => 'Zásilkovna',
    'recipient' => 'Eva Nová', 'phone' => '+420123456789',
    'pickup_code' => '123456', 'pickup_point' => 'Praha',
    'pickup_address' => 'Ulice 1, Praha, 110 00', 'pickup_verified' => true];
$orderControlsReady = true;
$orderEvents = [['created_at' => '2026-09-30 09:00:00', 'admin_id' => 3,
    'old_status' => 'shipped', 'new_status' => 'processing', 'reason' => 'Oprava <script>']];
$order['status'] = 'shipped';
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'name="action" value="correct-order-status"') ||
    !str_contains($html, 'Opravit chybné odeslání') ||
    !str_contains($html, 'Oprava &lt;script&gt;') ||
    str_contains($html, 'Oprava <script>') ||
    str_contains($html, 'name="action" value="delete-order"')) {
    throw new RuntimeException('Shipped order correction must be confirmed, audited and escaped.');
}
$order['status'] = 'completed';
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'value="not_delivered"') ||
    !str_contains($html, 'Vrátit na odesláno')) {
    throw new RuntimeException('Accidental completion must be correctable.');
}
$order['payment_status'] = 'pending';
$order['status'] = 'cancelled';
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Obnovit objednávku') ||
    !str_contains($html, 'name="action" value="delete-order"') ||
    str_contains($html, 'name="action" value="mark-order-paid"')) {
    throw new RuntimeException('Cancelled unpaid order should allow reopening or deletion, not payment confirmation.');
}
$order['status'] = 'awaiting_payment';
$order['payment_status'] = 'paid';
$orderEvents = [];
$orderControlsReady = false;
$packetaCancelReady = true;
$packetaTrackingUrl = 'https://tracking.packeta.com/cs/?id=1234567890';
$packetaShipment = ['status' => 'created', 'method' => 'zasilkovna_pickup',
    'barcode_text' => 'Z 123 4567 890', 'barcode' => 'Z1234567890',
    'weight_kg' => '0.750', 'courier_number' => null, 'last_error' => null];
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Z 123 4567 890') || !str_contains($html, 'packeta_label=1') ||
    !str_contains($html, 'name="action" value="packeta-cancel"') ||
    !str_contains($html, 'value="ready_to_ship"') ||
    !str_contains($html, 'tracking.packeta.com/cs/?id=1234567890') ||
    str_contains($html, 'name="action" value="packeta-create"')) {
    throw new RuntimeException('Created pickup packet must show the number and label.');
}
$packetaShipment['status'] = 'cancel_uncertain';
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'name="action" value="packeta-cancel-confirmed"') ||
    !str_contains($html, 'name="action" value="packeta-cancel-not-done"') ||
    !str_contains($html, 'Výsledek storna zásilky')) {
    throw new RuntimeException('Uncertain cancellation must block shipping and allow manual resolution.');
}
$packetaShipment = null;
$order['fulfillment_source'] = 'external';
$order['fulfillment_note'] = 'Dodavatel A <script>';
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Externí dodavatel') ||
    !str_contains($html, 'Dodavatel A &lt;script&gt;') ||
    str_contains($html, 'Dodavatel A <script>') ||
    str_contains($html, 'name="action" value="packeta-create"')) {
    throw new RuntimeException('External supplier must be visible and bypass local packet creation.');
}
unset($order['fulfillment_source'], $order['fulfillment_note']);
$packetaShipment = ['status' => 'created', 'method' => 'zasilkovna_pickup',
    'barcode_text' => 'Z 123 4567 890', 'barcode' => 'Z1234567890',
    'weight_kg' => '0.750', 'courier_number' => null, 'last_error' => null];
$packetaShipment['method'] = 'zasilkovna_home';
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'name="action" value="packeta-courier"') ||
    str_contains($html, 'packeta_label=1')) {
    throw new RuntimeException('HD must obtain a carrier number before its label.');
}
$packetaShipment = ['status' => 'rejected', 'last_error' => 'eshop_id: Není zadán odesilatel zásilky.',
    'submitted_json' => json_encode(['eshop' => 'Dobrodruzi <script>'], JSON_THROW_ON_ERROR)];
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Odeslané označení odesílatele') ||
    !str_contains($html, 'Dobrodruzi &lt;script&gt;') ||
    str_contains($html, 'Dobrodruzi <script>') ||
    !str_contains($html, 'client.packeta.com/senders/') ||
    !str_contains($html, 'section=settings')) {
    throw new RuntimeException('Rejected Packeta sender should show its submitted label and correction path safely.');
}
$packetaShipment = null;
$orderPage = ['items' => [array_replace($order, ['shipment_status' => 'created'])], 'nextOffset' => 25];
$ordersNextUrl = $orderBaseUrl . '&status=all&offset=25';
$deletedOrders = [['order_number' => 'TEST-26-A1B2C3D4', 'created_at' => '2026-09-30 09:00:00',
    'admin_id' => 3, 'reason' => 'Test <script>']];
$order = null;
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'DB-20260929-1') ||
    !str_contains($html, '1234567890') ||
    !str_contains($html, 'Zásilka vytvořena') ||
    !str_contains($html, 'status=all&amp;offset=25') ||
    !str_contains($html, 'Nedávno smazané objednávky') ||
    !str_contains($html, 'Test &lt;script&gt;') ||
    str_contains($html, 'Test <script>') ||
    !str_contains($html, 'Objednávky')) {
    throw new RuntimeException('Admin order list must show payment references and pagination.');
}

$screen = 'settings';
$settingsError = '';
$shippingCatalog = \SimpleStore\Checkout\ShippingPolicy::defaults();
$form = ['shipping_price' => array_fill_keys(array_keys($shippingCatalog), '120'),
    'shipping_enabled' => array_fill_keys(array_keys($shippingCatalog), '1'),
    'account_display' => '123456/0100', 'iban' => '', 'recipient' => 'Test',
    'payment_due_days' => '7', 'terms_url' => '', 'local_test_checkout' => '1'];
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Nastavení obchodu') ||
    !str_contains($html, 'value="save-checkout-settings"') ||
    !str_contains($html, 'value="123456/0100"') ||
    !str_contains($html, 'Označení odesílatele (hodnota pro API pole eshop)') ||
    !str_contains($html, 'name="shipping_price[gls_pickup]"') ||
    !str_contains($html, 'name="csrf" value="test-token"')) {
    throw new RuntimeException('Authenticated checkout settings form is missing.');
}

$screen = 'users';
$usersError = '';
$search = '';
$offset = 0;
$usersPage = ['items' => [['id' => 7, 'display_name' => 'Eva <script>',
    'email' => 'eva@example.org', 'is_active' => 1, 'order_count' => 2]], 'nextOffset' => null];
$customer = $usersPage['items'][0] + ['phone' => '', 'address_count' => 1];
$customerOrders = [];
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'Zákazníci') || !str_contains($html, 'name="action" value="customer-update"') ||
    !str_contains($html, 'name="action" value="customer-active"') ||
    !str_contains($html, 'name="action" value="customer-create"') ||
    !str_contains($html, 'Eva &lt;script&gt;') || str_contains($html, 'Eva <script>')) {
    throw new RuntimeException('Customer administration is missing or exposes unescaped data.');
}

echo "Admin rendering tests passed.\n";
