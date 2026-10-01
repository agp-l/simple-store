<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Accounting\MailSettingsRepository;
use SimpleStore\Accounting\OrderEmailComposer;

$order = ['order_number' => 'DB-26-1234567890', 'customer_email' => 'eva@example.test',
    'total_czk' => 1079, 'shipping_czk' => 79, 'payment_method' => 'bank_transfer',
    'payment_details' => ['account_display' => '123/4567'], 'variable_symbol' => '1234567890',
    'payment_due_at' => '2026-10-15', 'shipping' => ['label' => 'GLS', 'recipient' => '<Eva>'],
    'items' => [['name' => '<Batoh>', 'quantity' => 1, 'unit_price_czk' => 1000]]];
$confirmation = OrderEmailComposer::compose('order', $order, MailSettingsRepository::EVENTS['order']);
if (!str_contains($confirmation['text'], 'Číslo účtu: 123/4567') ||
    !str_contains($confirmation['text'], 'Variabilní symbol: 1234567890') ||
    !str_contains($confirmation['html'], '&lt;Batoh&gt;') ||
    str_contains($confirmation['html'], '<Batoh>') ||
    !str_contains($confirmation['html'], '1 079 Kč')) {
    throw new RuntimeException('Order confirmation omitted payment details or escaped HTML incorrectly.');
}
$shipping = OrderEmailComposer::compose('shipped', $order, MailSettingsRepository::EVENTS['shipped'],
    ['number' => 'GLS123456', 'url' => 'https://carrier.example/GLS123456'],
    'https://shop.example/cs/objednavka/abc');
if (!str_contains($shipping['text'], 'Číslo zásilky: GLS123456') ||
    !str_contains($shipping['html'], 'Sledovat zásilku') ||
    !str_contains($shipping['html'], 'https://carrier.example/GLS123456') ||
    str_contains($shipping['text'], 'Číslo účtu:')) {
    throw new RuntimeException('Shipment notification has wrong stage-specific content.');
}
$withoutTracking = OrderEmailComposer::compose('shipped', $order, MailSettingsRepository::EVENTS['shipped']);
if (!str_contains($withoutTracking['text'], 'zatím nepřidělil číslo')) {
    throw new RuntimeException('The shipment notification must explain when tracking is not yet available.');
}
echo "Customer mail rendering OK\n";
