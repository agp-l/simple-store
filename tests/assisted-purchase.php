<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Checkout\AssistedPurchase as A;
use SimpleStore\Accounting\OrderEmailComposer;
use SimpleStore\Accounting\MailSettingsRepository;

$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$invalid = static function (callable $run): void {
    try { $run(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid assisted sale accepted.');
};
$sale = ['mode' => 'assisted', 'supplier_price_czk' => 1678, 'delivery_note' => 'Do 5 pracovních dnů',
    'seller' => ['name' => 'Partner <test>', 'ico' => '12345678', 'address' => 'Praha 1',
        'email' => 'partner@example.test', 'terms_url' => 'https://partner.example.test/terms']];
$withoutDelivery = $sale; unset($withoutDelivery['delivery_note']);
$check(A::snapshot($withoutDelivery, 1800)['delivery_note'] === A::DELIVERY_POLICY &&
    A::snapshot(array_replace($sale, ['delivery_note' => '']), 1800)['delivery_note'] === A::DELIVERY_POLICY,
    'A product without a delivery estimate must use the common mandate.');
$assisted = A::snapshot($sale, 1800) + ['name' => 'Boty', 'unit_price_czk' => 1800, 'quantity' => 2];
$own = ['name' => 'Ponožky', 'unit_price_czk' => 200, 'quantity' => 1];
$items = [$assisted, $own];
$check($assisted['delivery_note'] === 'Do 5 pracovních dnů' && str_contains(A::description($assisted), 'Do 5 pracovních dnů'), 'Historical delivery promise changed.');
$check($assisted['assistance_fee_czk'] === 122 && A::principal($items) === 3356, 'Fee was added on top or quantity lost.');
$check(A::has($items) && A::own($items) && !A::has([$own]) && !A::own([$assisted]), 'Mixed cart modes wrong.');
$invoice = A::invoiceItems($items);
$check($invoice[0]['unit_price_czk'] === 122 && $invoice[1] === $own &&
    array_sum(array_map(static fn ($i) => $i['quantity'] * $i['unit_price_czk'], $invoice)) === 444,
    'Entrusted funds became invoice revenue.');
$invalid(static fn () => A::snapshot(array_replace($sale, ['supplier_price_czk' => 1801]), 1800));
$invalid(static fn () => A::snapshot(array_replace($sale, ['supplier_price_czk' => '1678']), 1800));
$invalid(static fn () => A::snapshot(array_replace($sale, ['seller' => []]), 1800));
$check(A::snapshot($sale, 1678)['assistance_fee_czk'] === 0 && A::snapshot(['mode' => 'own'], 1800) === [], 'Zero fee or legacy mode failed.');
$summary = ['items' => $items];
$token = \SimpleStore\Checkout\CheckoutReview::token($summary, ['email' => 'a@example.test'], 79, 'bank_transfer', 'legal-v1', 'secret');
foreach ([['items' => [$own]], ['items' => [array_replace($assisted, ['supplier_price_czk' => 1600]), $own]]] as $changed) {
    $check($token !== \SimpleStore\Checkout\CheckoutReview::token($changed, ['email' => 'a@example.test'], 79, 'bank_transfer', 'legal-v1', 'secret'), 'Changed contract retained review approval.');
}
$check($token !== \SimpleStore\Checkout\CheckoutReview::token($summary, ['email' => 'a@example.test'], 79, 'bank_transfer', 'legal-v2', 'secret'), 'Changed terms retained review approval.');
$order = ['items' => $items, 'total_czk' => 3879, 'subtotal_czk' => 3800, 'shipping_czk' => 79,
    'shipping' => ['assisted_purchase' => ['early_start' => true, 'early_request_text' => A::EARLY_REQUEST]]];
$mail = OrderEmailComposer::compose('order', $order, MailSettingsRepository::EVENTS['order']);
$check(str_contains($mail['text'], '1 678 Kč + zajištění nákupu 122 Kč') &&
    str_contains($mail['html'], 'Partner &lt;test&gt;') && str_contains($mail['text'], A::EARLY_REQUEST),
    'Confirmation lost breakdown, seller, escaping or explicit request.');
$item = $assisted; $checkoutEscape = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$checkoutMoney = static fn (int $v) => number_format($v, 0, ',', ' ') . ' Kč';
ob_start(); require dirname(__DIR__) . '/view/checkout/assisted-item.php'; $html = ob_get_clean();
$check(str_contains($html, '1 678 Kč') && str_contains($html, '122 Kč') && str_contains($html, 'Partner &lt;test&gt;'), 'Customer breakdown is missing or unsafe.');
$check(str_contains($html, '<details class="assisted-details">') && !str_contains($html, ' open') &&
    str_contains($html, 'Za kus: zboží') && str_contains($html, '(již v ceně)') && !str_contains($html, ' BTC'),
    'Compact disclosure lost the visible fee or adds repeated BTC conversions.');
$checkoutItems = [A::snapshot($withoutDelivery, 1800) + ['name' => 'Boty'], A::snapshot($withoutDelivery, 1800) + ['name' => 'Bunda']];
ob_start(); require dirname(__DIR__) . '/view/checkout/assisted-delivery.php'; $deliveryHtml = ob_get_clean();
$check(substr_count($deliveryHtml, A::DELIVERY_POLICY) === 1, 'Common delivery must appear once per order.');
$checkoutItems = [$assisted];
ob_start(); require dirname(__DIR__) . '/view/checkout/assisted-delivery.php'; $deliveryHtml = ob_get_clean();
$check(str_contains($deliveryHtml, 'Do 5 pracovních dnů') && !str_contains($deliveryHtml, A::DELIVERY_POLICY), 'An old receipt acquired new terms.');
echo "Assisted purchase: fixed total, mixed cart, immutable approval, invoice split and customer copy OK.\n";
