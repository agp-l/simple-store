<?php
declare(strict_types=1);

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
    !str_contains($html, 'Pořadí horních odkazů')) {
    throw new RuntimeException('Menu administration must include placement and page ordering.');
}

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
    !str_contains($html, 'name="csrf" value="test-token"') ||
    !str_contains($html, 'bank_checked') || !str_contains($html, '&lt;img src=x onerror=alert(1)&gt;') ||
    str_contains($html, '<img src=x onerror=alert(1)>')) {
    throw new RuntimeException('Order detail must require explicit bank verification and escape customer data.');
}
$order['payment_status'] = 'paid';
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (str_contains($html, 'name="action" value="mark-order-paid"') || !str_contains($html, 'Zaplaceno')) {
    throw new RuntimeException('Paid bank transfers must not show the confirmation form.');
}
$orderPage = ['items' => [$order], 'nextOffset' => 25];
$ordersNextUrl = $orderBaseUrl . '&status=all&offset=25';
$order = null;
ob_start();
require dirname(__DIR__) . '/view/admin/layout.php';
$html = ob_get_clean();
if (!str_contains($html, 'DB-20260929-1') ||
    !str_contains($html, '1234567890') ||
    !str_contains($html, 'status=all&amp;offset=25') ||
    !str_contains($html, 'Objednávky')) {
    throw new RuntimeException('Admin order list must show payment references and pagination.');
}

echo "Admin rendering tests passed.\n";
