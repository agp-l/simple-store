<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Accounting\MailSettingsRepository;
use SimpleStore\Accounting\OrderEmailComposer;
use SimpleStore\Accounting\OrderMailMime;

$order = ['order_number' => 'DB-26-1234567890', 'order_token' => str_repeat('a', 64),
    'customer_email' => 'eva@example.test',
    'total_czk' => 1079, 'shipping_czk' => 79, 'payment_method' => 'bank_transfer',
    'payment_details' => ['account_display' => '123/4567'], 'variable_symbol' => '1234567890',
    'payment_due_at' => '2026-10-15', 'shipping' => ['label' => 'GLS', 'recipient' => '<Eva>',
        'company' => 'Výprava <s.r.o.>', 'pickup_point' => 'Box <Centrum>', 'pickup_address' => 'Hlavní 1, Brno',
        'pickup_code' => 'BOX-123', 'country' => 'CZ', 'phone' => '+420 123 456 789', 'email' => 'eva@example.test'],
    'items' => [['name' => '<Batoh>', 'quantity' => 1, 'unit_price_czk' => 1000,
        'options' => ['Velikost' => '<L>']]]];
$confirmation = OrderEmailComposer::compose('order', $order, MailSettingsRepository::EVENTS['order'], [],
    'https://shop.example/simple-store/cs/objednavka/abc', 'https://shop.example/simple-store',
    ['obchodni-podminky', 'reklamacni-rad', 'vymena-a-vraceni-zbozi']);
if (!str_contains($confirmation['text'], 'Číslo účtu: 123/4567') ||
    !str_contains($confirmation['text'], 'Variabilní symbol: 1234567890') ||
    !str_contains($confirmation['text'], 'Výdejní místo / box: Box <Centrum>') ||
    !str_contains($confirmation['text'], 'Kód místa: BOX-123') ||
    !str_contains($confirmation['text'], 'Výprava <s.r.o.>') ||
    !str_contains($confirmation['text'], 'Velikost: <L>') ||
    !str_contains($confirmation['text'], 'https://shop.example/simple-store/cs/obchodni-podminky') ||
    !str_contains($confirmation['text'], 'https://shop.example/simple-store/support.php?order=' . str_repeat('a', 64)) ||
    !str_contains($confirmation['html'], 'Reklamace a vrácení') ||
    str_contains($confirmation['text'], 'OBCHODNÍ PODMÍNKY PLATNÉ PŘI OBJEDNÁNÍ') ||
    str_contains($confirmation['html'], 'Obchodní podmínky platné při objednání</h2>') ||
    !str_contains($confirmation['html'], 'Výprava &lt;s.r.o.&gt;') ||
    !str_contains($confirmation['html'], 'Box &lt;Centrum&gt;') ||
    !str_contains($confirmation['html'], '&lt;Batoh&gt;') ||
    str_contains($confirmation['html'], '<Batoh>') ||
    !str_contains($confirmation['html'], '1 079 Kč')) {
    throw new RuntimeException('Order confirmation omitted payment details or escaped HTML incorrectly.');
}
$shipping = OrderEmailComposer::compose('shipped', $order, MailSettingsRepository::EVENTS['shipped'],
    ['number' => 'GLS123456', 'url' => 'https://carrier.example/GLS123456'],
    'https://shop.example/cs/objednavka/abc');
if (!str_contains($shipping['text'], 'Číslo zásilky: GLS123456') ||
    !str_contains($shipping['text'], 'Výdejní místo / box: Box <Centrum>') ||
    !str_contains($shipping['text'], 'Objednané zboží:') ||
    !str_contains($shipping['text'], 'Výprava <s.r.o.>') ||
    !str_contains($shipping['html'], 'Sledovat zásilku') ||
    !str_contains($shipping['html'], 'https://carrier.example/GLS123456') ||
    str_contains($shipping['text'], 'Číslo účtu:')) {
    throw new RuntimeException('Shipment notification has wrong stage-specific content.');
}
$withoutTracking = OrderEmailComposer::compose('shipped', $order, MailSettingsRepository::EVENTS['shipped']);
if (!str_contains($withoutTracking['text'], 'zatím nepřidělil číslo')) {
    throw new RuntimeException('The shipment notification must explain when tracking is not yet available.');
}
$unsafeTracking = OrderEmailComposer::compose('shipped', $order, MailSettingsRepository::EVENTS['shipped'],
    ['number' => 'ABC', 'url' => 'javascript:alert(1)'], 'https://shop.example/simple-store/cs/objednavka/abc',
    'https://shop.example/simple-store', ['obchodni-podminky']);
if (str_contains($unsafeTracking['html'], 'javascript:') ||
    str_contains($unsafeTracking['text'], 'obchodni-podminky')) {
    throw new RuntimeException('Stage email included an unsafe tracking URL or unnecessary legal links.');
}
$insecureBase = OrderEmailComposer::compose('order', $order, MailSettingsRepository::EVENTS['order'],
    [], '', 'http://localhost/simple-store');
if (str_contains($insecureBase['html'], 'support.php?order=')) {
    throw new RuntimeException('Private order token was linked through plain HTTP.');
}
$paid = OrderEmailComposer::compose('paid', $order, MailSettingsRepository::EVENTS['paid'], [], '',
    'https://shop.example/simple-store', ['obchodni-podminky']);
if (!str_contains($paid['text'], 'https://shop.example/simple-store/cs/obchodni-podminky') ||
    !str_contains($paid['text'], 'Platba byla potvrzena.')) {
    throw new RuntimeException('Paid mail must link the terms without expanding them in the body.');
}
$mime = OrderMailMime::compose('Přehled objednávky', '<p>Přehled objednávky</p>',
    'Znění pro tohoto zákazníka.');
if (!str_contains($mime['headers'], 'Content-Type: multipart/mixed;') ||
    !str_contains($mime['body'], 'Content-Type: multipart/alternative;') ||
    !str_contains($mime['body'], 'Content-Disposition: attachment; filename="obchodni-podminky.txt"') ||
    !str_contains($mime['body'], base64_encode('Znění pro tohoto zákazníka.')) ||
    str_contains($mime['body'], 'Znění pro tohoto zákazníka.')) {
    throw new RuntimeException('Terms must arrive as a separate UTF-8 attachment.');
}
$noAttachment = OrderMailMime::compose('Běžná zpráva', '<p>Běžná zpráva</p>');
if (!str_contains($noAttachment['headers'], 'Content-Type: multipart/alternative;') ||
    str_contains($noAttachment['body'], 'Content-Disposition: attachment')) {
    throw new RuntimeException('Unrelated stage mail unexpectedly gained an attachment.');
}
$corrected = $order;
$corrected['shipping_ordered'] = ['label' => 'GLS na adresu', 'recipient' => 'Eva',
    'company' => 'Původní firma', 'street' => 'Polní 1', 'postal_code' => '11000',
    'city' => 'Praha', 'country' => 'CZ'];
$corrected['shipping'] = ['label' => 'GLS na adresu', 'recipient' => 'Eva',
    'company' => 'Nová firma', 'street' => 'Nová 2', 'postal_code' => '60200',
    'city' => 'Brno', 'country' => 'CZ'];
$initial = OrderEmailComposer::compose('order', $corrected, MailSettingsRepository::EVENTS['order']);
$updated = OrderEmailComposer::compose('processing', $corrected, MailSettingsRepository::EVENTS['processing']);
if (!str_contains($initial['text'], 'Původní firma') || !str_contains($initial['text'], 'Polní 1') ||
    str_contains($initial['text'], 'Nová firma') || !str_contains($updated['text'], 'Nová firma') ||
    !str_contains($updated['text'], '60200 Brno')) {
    throw new RuntimeException('Address notices must distinguish the checkout and dispatch snapshots.');
}
echo "Customer mail rendering OK\n";
