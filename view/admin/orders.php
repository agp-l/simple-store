<?php
declare(strict_types=1);
$orderTrackingReady ??= false;
$orderManualTracking ??= ['number' => '', 'url' => ''];

$orderMoney = static fn (mixed $amount): string => number_format((int) $amount, 0, ',', ' ') . ' Kč';
$orderPaymentLabel = static fn (mixed $status): string => match ($status) {
    'paid' => 'Zaplaceno',
    'pending' => 'Čeká na platbu',
    'cancelled' => 'Platba zrušena',
    'test' => 'Testovací objednávka',
    default => 'Stav platby: ' . (string) $status,
};
$orderFulfillmentLabel = static fn (mixed $status): string => match ($status) {
    'processing' => 'Připravuje se', 'ready_to_ship' => 'Připravena k odeslání',
    'shipped' => 'Předána dopravci', 'completed' => 'Dokončena', 'cancelled' => 'Stornována',
    'test' => 'Testovací', default => 'Přijata',
};
$goPayStatusLabel = static fn (mixed $status): string => match ($status) {
    'creating' => 'Založení platby probíhá',
    'uncertain' => 'Založení platby je nejisté',
    'created' => 'Čeká na platbu',
    'payment_method_chosen' => 'Zvolen způsob platby',
    'authorized' => 'Autorizováno, čeká na úhradu',
    'paid' => 'Zaplaceno',
    'canceled', 'cancelled' => 'Zrušeno',
    'timeouted' => 'Vypršel čas',
    'refunded' => 'Vráceno',
    'partially_refunded' => 'Částečně vráceno',
    'rejected' => 'Brána odmítla založení',
    default => (string) $status,
};
$btcpayStatusLabel = static fn (mixed $status): string => match ($status) {
    'creating' => 'Založení faktury probíhá',
    'uncertain' => 'Založení faktury je nejisté',
    'new' => 'Čeká na zaplacení',
    'processing' => 'Platba se potvrzuje',
    'settled' => 'Zaplaceno',
    'expired' => 'Vypršela platnost',
    'invalid' => 'Neplatná platba',
    'rejected' => 'Založení faktury bylo odmítnuto',
    default => (string) $status,
};
$packetaReady ??= false;
$comgateConfigured ??= false;
$goPayConfigured ??= false;
$btcpayConfigured ??= false;
$packetaConfigured ??= false;
$packetaCancelReady ??= false;
$packetaShipment ??= null;
$packetaTrackingUrl ??= null;
$cancelledPackets ??= [];
$packetaAction ??= '';
$carrierReady ??= false;
$carrierShipment ??= null;
$carrierAction ??= '';
$fulfillmentSourceReady ??= false;
$orderControlsReady ??= false;
$orderEvents ??= [];
$orderTaxReady ??= false;
$orderInvoiceReady ??= false;
$orderReceipt ??= null;
$goPayState ??= null;
$btcpayState ??= null;
$orderInvoice ??= null;
$sellerSettings ??= [];
$shippingChangeReady ??= false;
$shippingChangeOptions ??= [];
$shippingChangeNeedsAddress ??= false;
$orderProductLinks ??= [];
$shippingMethodLabels = array_map(static fn (array $method): string => $method['label'],
    \SimpleStore\Checkout\ShippingPolicy::defaults());
?>
<div class="panel-intro">
  <div><p class="panel-eyebrow">Prodej</p><h1>Objednávky</h1>
    <p>Přehled přijatých objednávek. Převod potvrď po kontrole bankovního výpisu; platby Comgate, GoPay a BTCPay ověřuj u příslušné brány.</p></div>
  <?php if ($order !== null): ?><div class="panel-quick"><a href="<?= $escape($orderBaseUrl) ?>">← Všechny objednávky</a></div><?php endif; ?>
</div>
<?php if (!$ordersReady): ?>
  <p class="panel-error" role="alert">Pro objednávky nejdřív <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
<?php endif; ?>
<?php if ($orderError !== ''): ?><p class="panel-error" role="alert"><?= $escape($orderError) ?></p><?php endif; ?>
<?php if ($order === null && ($_GET['deleted'] ?? '') === '1'): ?><p class="panel-notice" role="status">Objednávka byla smazána. Skutečný obchod má zachovanou účetní stopu.</p><?php endif; ?>
<?php if ($order !== null): ?>
  <?php
  $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
  $payment = is_array($order['payment_details'] ?? null) ? $order['payment_details'] : [];
  $bankTransfer = ($order['payment_method'] ?? '') === 'bank_transfer';
  $comgatePayment = ($order['payment_method'] ?? '') === 'comgate';
  $goPayPayment = ($order['payment_method'] ?? '') === 'gopay';
  $btcpayPayment = ($order['payment_method'] ?? '') === 'btcpay';
  $onlineGateway = $comgatePayment || $goPayPayment || $btcpayPayment;
  $gatewayName = $btcpayPayment ? 'BTCPay Server' : ($goPayPayment ? 'GoPay' : 'Comgate');
  $gatewayConfigured = $btcpayPayment ? $btcpayConfigured : ($goPayPayment ? $goPayConfigured : $comgateConfigured);
  $paid = ($order['payment_status'] ?? '') === 'paid';
  $orderOverdue = $bankTransfer && !$paid && ($order['payment_status'] ?? '') === 'pending' &&
      ($order['status'] ?? '') === 'new' && is_string($order['payment_due_at'] ?? null) &&
      $order['payment_due_at'] < gmdate('Y-m-d H:i:s');
  $goPayRefunded = $goPayPayment && in_array($goPayState['status'] ?? '', ['refunded', 'partially_refunded'], true);
  $btcpayDispatchBlocked = $btcpayPayment && $paid &&
      ($btcpayState === null || ($btcpayState['status'] ?? '') !== 'settled' ||
      (string) ($btcpayState['invoice_id'] ?? '') !== (string) ($order['provider_reference'] ?? ''));
  $paymentHighlight = $paid && !$goPayRefunded && !$btcpayDispatchBlocked;
  $paymentDisplayLabel = $goPayRefunded
      ? (($goPayState['status'] ?? '') === 'refunded' ? 'Platba vrácena' : 'Platba částečně vrácena')
      : ($btcpayDispatchBlocked ? 'Platba k ověření' : $orderPaymentLabel($order['payment_status'] ?? ''));
  $goPayDispatchBlocked = $goPayPayment && $paid &&
      ($goPayState === null || ($goPayState['status'] ?? '') !== 'paid' ||
      (string) ($goPayState['payment_id'] ?? '') !== (string) ($order['provider_reference'] ?? ''));
  $gatewayDispatchBlocked = $goPayDispatchBlocked || $btcpayDispatchBlocked;
  $recipientName = trim((string) ($shipping['recipient'] ?? $shipping['name'] ?? ''));
  $contactEmail = trim((string) ($order['customer_email'] ?? $shipping['email'] ?? ''));
  $contactPhone = trim((string) ($shipping['phone'] ?? ''));
  $isPickup = !empty($shipping['pickup_point']);
  $deliveryLines = $isPickup ? [
      $recipientName,
      (string) ($shipping['pickup_point'] ?? ''),
      (string) ($shipping['pickup_address'] ?? ''),
      empty($shipping['pickup_code']) ? '' : 'ID místa: ' . $shipping['pickup_code'],
  ] : [
      $recipientName,
      (string) ($shipping['street'] ?? ''),
      trim((string) ($shipping['postal_code'] ?? '') . ' ' . (string) ($shipping['city'] ?? '')),
      (string) ($shipping['country'] ?? 'CZ'),
  ];
  $deliveryCopy = implode("\n", array_filter($deliveryLines, static fn (string $line): bool => trim($line) !== ''));
  $carrierCopy = implode("\n", array_filter([
      'Objednávka ' . (string) ($order['order_number'] ?? ''),
      'Doprava: ' . (string) ($shipping['label'] ?? $shipping['method'] ?? ''),
      $deliveryCopy,
      $contactEmail === '' ? '' : 'E-mail: ' . $contactEmail,
      $contactPhone === '' ? '' : 'Telefon: ' . $contactPhone,
  ],
      static fn (string $line): bool => trim($line) !== ''));
  $invoiceDetailUrl = $orderInvoice === null ? '' : $adminUrl . '?section=accounting&tab=invoices&invoice_id=' . (int) $orderInvoice['id'];
  $shippingEntered = ($method ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'change-order-shipping' ? $_POST : [];
  $shippingFormValue = static fn (string $field): string => is_string($shippingEntered[$field] ?? null) ? $shippingEntered[$field] : '';
  ?>
  <?php if (($_GET['paid'] ?? null) === '1' && $paid): ?><p class="panel-notice" role="status">Platba byla ručně označena jako přijatá.</p><?php endif; ?>
  <?php if (($_GET['shipping_saved'] ?? null) === '1'): ?><p class="panel-notice" role="status">Dopravce pro expedici byl změněn. Cena v původní objednávce zůstává stejná.</p><?php endif; ?>
  <?php if (($_GET['payment_checked'] ?? null) === '1' && $onlineGateway): ?><p class="panel-notice" role="status">Stav platby byl ověřen přímo u <?= $gatewayName ?>.</p><?php endif; ?>
  <?php if (($_GET['corrected'] ?? null) === '1'): ?><p class="panel-notice" role="status">Stav byl opraven. Důvod a původní stav jsou v historii zásahů níže.</p><?php endif; ?>
  <?php if (($_GET['payment_corrected'] ?? null) === '1'): ?><p class="panel-notice" role="status">Potvrzení platby bylo opraveno. Původní údaj je v historii zásahů a v účetních podkladech.</p><?php endif; ?>
  <?php if (($_GET['tax_saved'] ?? null) === '1'): ?><p class="panel-notice" role="status">Účetní údaj byl uložen.</p><?php endif; ?>
  <?php if (($order['status'] ?? '') === 'cancelled' && $paid): ?><p class="panel-error" role="alert">Zaplaceno po stornu – prověř vrácení platby. Před dalším postupem ověř přijatou částku a záznamy u platební brány.</p><?php endif; ?>
  <?php if ($orderOverdue): ?><p class="panel-error" role="alert">Po splatnosti od <?= $escape($order['payment_due_at']) ?> UTC. Před stornem ověř, že platba nedorazila na bankovní účet.</p><?php endif; ?>
  <?php if ($goPayRefunded): ?><p class="panel-error" role="alert">GoPay eviduje vrácení platby. Původní úhrada zůstává v historii objednávky; před expedicí zkontroluj vrácenou částku, účetní zápisy a další postup se zákazníkem.</p><?php endif; ?>
  <?php if ($goPayDispatchBlocked && !$goPayRefunded): ?><p class="panel-error" role="alert">U této objednávky nemáme potvrzenou stále uhrazenou transakci GoPay. Před expedicí načti aktuální stav platby u brány.</p><?php endif; ?>
  <?php if ($btcpayDispatchBlocked): ?><p class="panel-error" role="alert">U této objednávky není lokálně potvrzená uhrazená faktura BTCPay. Před expedicí načti stav přímo z BTCPay Serveru.</p><?php endif; ?>
  <div class="panel-grid panel-order-detail">
    <div class="panel-workspace">
      <?php require __DIR__ . "/orders/summary.php"; ?>
      <?php require __DIR__ . "/orders/delivery.php"; ?>
    </div>
    <?php require __DIR__ . "/orders/payment.php"; ?>
  </div>
  <script defer src="<?= $escape($basePath . 'assets/admin-orders.js?v=' . filemtime(__DIR__ . '/../../assets/admin-orders.js')) ?>"></script>
<?php elseif ($ordersReady): ?>
  <?php require __DIR__ . "/orders/list.php"; ?>
<?php endif; ?>
