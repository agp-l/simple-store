<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\AfterSales\CaseNotice;

$case = [
    'id' => 4, 'case_number' => 'V-2026-123456', 'case_token' => str_repeat('a', 64),
    'kind' => 'withdrawal', 'status' => 'resolved', 'order_number' => 'DB-1',
    'item_name' => 'Boty <script>alert(1)</script>',
    'item_options_json' => json_encode(['Velikost' => '42'], JSON_THROW_ON_ERROR),
    'quantity' => 1, 'unit_price_czk' => 1000, 'customer_name' => 'Eva',
    'customer_company' => 'Eva s.r.o.', 'customer_email' => 'eva@example.test',
    'customer_phone' => '+420777123456', 'description' => 'Bez důvodu',
    'requested_solution' => 'refund', 'submitted_at' => '2026-10-04 10:00:00',
    'delivered_on' => '2026-10-03', 'received_at' => null,
    'resolved_at' => '2026-10-04 12:00:00', 'resolution_type' => 'refund',
    'resolution_text' => 'Peníze pošleme zpět.', 'repair_duration' => null,
    'refunded_at' => null, 'seller_json' => json_encode(['name' => 'Prodejce', 'ico' => '12345678',
        'street' => 'Lesní 2', 'postal_code' => '60200', 'city' => 'Brno',
        'email' => 'info@example.test'], JSON_THROW_ON_ERROR),
    'events' => [['visible_to_customer' => 1, 'created_at' => '2026-10-04 10:00:00',
        'message' => 'Odstoupení přijato', 'status' => 'submitted'],
        ['visible_to_customer' => 0, 'created_at' => '2026-10-04 11:00:00',
            'message' => 'Soukromá poznámka', 'status' => 'reviewing']],
];
$notice = CaseNotice::receipt($case, 'https://shop.example.test/support.php?case=' . $case['case_token']);
if (!str_contains($notice['text'], '4. 10. 2026 12:00 (Praha)') ||
    !str_contains($notice['text'], 'odstoupení od smlouvy') ||
    !str_contains($notice['text'], 'Velikost: 42') ||
    !str_contains($notice['text'], 'Prodejce') ||
    str_contains($notice['html'], '<script>')) {
    throw new RuntimeException('The durable withdrawal receipt omitted facts or failed escaping.');
}
if (CaseNotice::publicUrl($case, 'http://localhost/simple-store') !== '') {
    throw new RuntimeException('A private case link may only be emailed with a configured HTTPS base.');
}
$event = CaseNotice::event($case, 'Peníze pošleme zpět.');
if (!str_contains($event['text'], 'Peníze pošleme zpět.') ||
    !str_contains($event['text'], 'Datum vyřízení')) {
    throw new RuntimeException('The written outcome omitted its date or reason.');
}
$error = '';
$mailState = 'queued';
$preview = $order = null;
$cases = [];
$ready = false;
$supportUrl = '/support.php';
$basePath = '/';
$language = 'cs';
ob_start();
require dirname(__DIR__) . '/view/after-sales/public.php';
$html = ob_get_clean();
if (!str_contains($html, 'Vytisknout potvrzení') ||
    !str_contains($html, 'Eva s.r.o.') ||
    !str_contains($html, 'Peníze pošleme zpět.') ||
    str_contains($html, '<script>alert(1)</script>') ||
    str_contains($html, 'Soukromá poznámka')) {
    throw new RuntimeException('Private case view leaked a note, omitted the result or failed escaping.');
}
echo "After-sales rendering tests passed.\n";
