<?php
declare(strict_types=1);

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
  <?php if ($goPayRefunded): ?><p class="panel-error" role="alert">GoPay eviduje vrácení platby. Původní úhrada zůstává v historii objednávky; před expedicí zkontroluj vrácenou částku, účetní zápisy a další postup se zákazníkem.</p><?php endif; ?>
  <?php if ($goPayDispatchBlocked && !$goPayRefunded): ?><p class="panel-error" role="alert">U této objednávky nemáme potvrzenou stále uhrazenou transakci GoPay. Před expedicí načti aktuální stav platby u brány.</p><?php endif; ?>
  <?php if ($btcpayDispatchBlocked): ?><p class="panel-error" role="alert">U této objednávky není lokálně potvrzená uhrazená faktura BTCPay. Před expedicí načti stav přímo z BTCPay Serveru.</p><?php endif; ?>
  <div class="panel-grid panel-order-detail">
    <div class="panel-workspace">
      <section class="panel-panel">
        <div class="panel-panel-head"><h2>Objednávka <?= $escape($order['order_number'] ?? '') ?></h2>
          <div class="panel-order-head-actions">
            <?php if ($orderInvoice !== null): ?><a class="panel-order-document" href="<?= $escape($invoiceDetailUrl . '&print=1') ?>" target="_blank" rel="noopener noreferrer">Faktura <?= $escape($orderInvoice['document_number']) ?> ↗</a><?php endif; ?>
            <span class="panel-order-state <?= $paymentHighlight ? 'is-paid' : 'is-pending' ?>"><?= $escape($paymentDisplayLabel) ?></span>
            <span class="panel-order-state"><?= $escape($orderFulfillmentLabel($order['status'] ?? '')) ?></span>
          </div></div>
        <p class="panel-help">Přijato <?= $escape($order['created_at'] ?? '') ?> · <?= $escape($shipping['label'] ?? $shipping['method'] ?? 'Doprava neuvedena') ?></p>
        <h3 class="panel-order-lines-title">Objednané zboží</h3>
        <div class="panel-order-lines">
          <?php foreach (($order['items'] ?? []) as $item): ?>
            <?php if (!is_array($item)): continue; endif; ?>
            <?php
            $productLanguage = (string) ($item['language'] ?? 'cs');
            $productIdentity = (string) ($item['product_key'] ?? '') . ':' . $productLanguage;
            $productUrl = $orderProductLinks[$productIdentity] ?? '';
            $imagePath = (string) ($item['image_path'] ?? '');
            $thumbnailPath = \SimpleStore\Media\MediaPath::variant($imagePath, 'thumb');
            $imageUrl = str_starts_with($thumbnailPath, 'images/') ? $basePath . $thumbnailPath :
                (preg_match('~^https?://~iD', $thumbnailPath) === 1 &&
                filter_var($thumbnailPath, FILTER_VALIDATE_URL) ? $thumbnailPath : '');
            ?>
            <div class="panel-order-line">
              <?php if ($productUrl !== ''): ?><a class="panel-order-product-image" href="<?= $escape($productUrl) ?>" aria-label="Otevřít produkt <?= $escape($item['name'] ?? '') ?>">
                <?php if ($imageUrl !== ''): ?><img src="<?= $escape($imageUrl) ?>" alt="" loading="lazy" width="64" height="64"><?php else: ?><span aria-hidden="true">▦</span><?php endif; ?>
              </a><?php elseif ($imageUrl !== ''): ?><span class="panel-order-product-image"><img src="<?= $escape($imageUrl) ?>" alt="" loading="lazy" width="64" height="64"></span><?php endif; ?>
              <div><?php if ($productUrl !== ''): ?><a class="panel-order-product-name" href="<?= $escape($productUrl) ?>"><?= $escape($item['name'] ?? '') ?></a><?php else: ?><strong><?= $escape($item['name'] ?? '') ?></strong><?php endif; ?>
                <small><?= (int) ($item['quantity'] ?? 0) ?> ks × <?= $orderMoney($item['unit_price_czk'] ?? 0) ?>
                <?php foreach (($item['options'] ?? []) as $option => $value): ?>
                  <?php if (is_scalar($value)): ?> · <?= $escape($option) ?>: <?= $escape($value) ?><?php endif; ?>
                <?php endforeach; ?></small></div>
              <strong><?= $orderMoney((int) ($item['quantity'] ?? 0) * (int) ($item['unit_price_czk'] ?? 0)) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
        <dl class="panel-order-totals">
          <div><dt>Produkty</dt><dd><?= $orderMoney($order['subtotal_czk'] ?? 0) ?></dd></div>
          <div><dt>Doprava</dt><dd><?= $orderMoney($order['shipping_czk'] ?? 0) ?></dd></div>
          <div><dt>Celkem</dt><dd><?= $orderMoney($order['total_czk'] ?? 0) ?></dd></div>
        </dl>
      </section>
      <section class="panel-panel">
        <div class="panel-panel-head"><h2>Doručení a kontakt</h2>
          <div class="panel-order-head-actions" aria-label="Kopírovat údaje pro expedici">
            <button class="panel-order-copy" type="button" data-order-copy="<?= $escape($deliveryCopy) ?>">Kopírovat adresu</button>
            <button class="panel-order-copy" type="button" data-order-copy="<?= $escape($carrierCopy) ?>">Kopírovat pro dopravce</button>
          </div>
        </div>
        <p class="panel-order-copy-feedback" role="status" aria-live="polite"></p>
        <dl class="panel-order-facts">
          <div><dt>Aktuální doprava</dt><dd><strong><?= $escape($shipping['label'] ?? $shipping['method'] ?? 'Neuvedeno') ?></strong></dd></div>
          <?php if (!empty($order['dispatch_shipping_changed'])): ?><div><dt>Původně objednáno</dt><dd><?= $escape($order['shipping_ordered']['label'] ?? $order['shipping_ordered']['method'] ?? 'Neuvedeno') ?> · účtováno <?= $orderMoney($order['shipping_czk'] ?? 0) ?></dd></div><?php endif; ?>
          <div><dt>Expeduje</dt><dd><?= ($order['fulfillment_source'] ?? 'own') === 'external' ? 'Externí dodavatel' : 'Obchod' ?><?php if (!empty($order['fulfillment_note'])): ?><br><?= $escape($order['fulfillment_note']) ?><?php endif; ?></dd></div>
          <div><dt>Příjemce</dt><dd><?= $escape($shipping['recipient'] ?? $shipping['name'] ?? 'Neuvedeno') ?></dd></div>
          <div><dt>E-mail</dt><dd><?= $escape($order['customer_email'] ?? $shipping['email'] ?? 'Neuvedeno') ?></dd></div>
          <div><dt>Telefon</dt><dd><?= $escape($shipping['phone'] ?? 'Neuvedeno') ?></dd></div>
          <?php if (!empty($shipping['pickup_point'])): ?><div><dt>Výdejní místo</dt><dd><?= $escape($shipping['pickup_point']) ?><br><?= $escape($shipping['pickup_address'] ?? '') ?><?php if (!empty($shipping['pickup_code'])): ?><br>Kód: <?= $escape($shipping['pickup_code']) ?><?php endif; ?><?php if (($shipping['method'] ?? '') === 'balikovna_pickup' && !empty($shipping['pickup_postal_code'])): ?><br>PSČ Balíkovny: <?= $escape($shipping['pickup_postal_code']) ?><?php endif; ?></dd></div>
          <?php else: ?><div><dt>Adresa</dt><dd><?= $escape($shipping['street'] ?? '') ?><br><?= $escape(trim((string) ($shipping['postal_code'] ?? '') . ' ' . (string) ($shipping['city'] ?? ''))) ?><br><?= $escape($shipping['country'] ?? 'CZ') ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($shippingChangeReady && $shippingChangeOptions !== []): ?>
          <details class="panel-order-accordion" <?= $shippingEntered !== [] ? 'open' : '' ?>>
            <summary>Změnit skutečného dopravce</summary>
            <p class="panel-help">Změna se týká expedice. Původní cena dopravy, objednávka a vystavený doklad zůstávají stejné.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="change-order-shipping"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="expected_method" value="<?= $escape($shipping['method'] ?? '') ?>">
              <label>Expedovat přes <select name="shipping_method" required>
                <?php foreach ($shippingChangeOptions as $methodCode => $methodLabel): ?><option value="<?= $escape($methodCode) ?>" <?= $methodCode === $shippingFormValue('shipping_method') ? 'selected' : '' ?>><?= $escape($methodLabel) ?></option><?php endforeach; ?>
              </select></label>
              <?php if ($shippingChangeNeedsAddress): ?>
                <p class="panel-help">Objednávka obsahuje jen výdejní místo. Pro doručení domů zadej úplnou adresu, kterou jsi ověřil/a u zákazníka.</p>
                <label>Ulice a číslo domu <input name="shipping_street" value="<?= $escape($shippingFormValue('shipping_street')) ?>" maxlength="190" required autocomplete="street-address"></label>
                <label>Město <input name="shipping_city" value="<?= $escape($shippingFormValue('shipping_city')) ?>" maxlength="120" required autocomplete="address-level2"></label>
                <label>PSČ <input name="shipping_postal_code" value="<?= $escape($shippingFormValue('shipping_postal_code')) ?>" maxlength="20" required autocomplete="postal-code"></label>
                <label class="panel-check"><input type="checkbox" name="address_confirmed" value="1" <?= $shippingFormValue('address_confirmed') === '1' ? 'checked' : '' ?> required> Ověřil/a jsem úplnou adresu příjemce pro doručení domů.</label>
              <?php endif; ?>
              <label>Důvod změny <input name="reason" value="<?= $escape($shippingFormValue('reason')) ?>" minlength="8" maxlength="190" required placeholder="Například přesměrování na jiného dopravce"></label>
              <?php if ($carrierShipment !== null && ($carrierShipment['status'] ?? '') === 'draft'): ?><label class="panel-check"><input type="checkbox" name="draft_not_submitted" value="1" <?= $shippingFormValue('draft_not_submitted') === '1' ? 'checked' : '' ?> required> Potvrzuji, že podklady dosud nebyly importovány k dopravci; uložený koncept se smaže.</label><?php endif; ?>
              <button class="panel-button" type="submit">Uložit dopravce pro expedici</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if (in_array($shipping['method'] ?? '', ['zasilkovna_pickup', 'zasilkovna_home'], true)): ?>
          <details class="panel-order-accordion panel-order-dispatch" <?= $packetaShipment !== null || $orderError !== '' || isset($_GET['packeta_result']) || isset($_GET['packeta_saved']) ? 'open' : '' ?>>
            <summary>Podání zásilky Zásilkovně<?php if ($packetaShipment !== null && ($packetaShipment['status'] ?? '') === 'created'): ?> · <?= $escape($packetaShipment['barcode_text'] ?: $packetaShipment['barcode']) ?><?php endif; ?></summary>
          <div class="panel-packeta-dispatch">
            <?php if (($order['fulfillment_source'] ?? 'own') !== 'external' && !$packetaReady): ?>
              <p class="panel-help">Nejdřív <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>, aby vznikla tabulka zásilek.</p>
            <?php elseif (($order['fulfillment_source'] ?? 'own') !== 'external' && !$packetaConfigured): ?>
              <p class="panel-help">V <a href="<?= $escape($adminUrl . '?section=settings') ?>">nastavení obchodu</a> vyplň soukromé API heslo a označení odesílatele z klientské sekce Zásilkovny.</p>
            <?php endif; ?>
            <?php $packetaNotice = match ($_GET['packeta_result'] ?? '') {
                'packeta-cancel', 'packeta-cancel-confirmed' => 'Zásilka byla stornována u Zásilkovny. Můžeš opravit údaje a vytvořit novou.',
                'packeta-cancel-not-done' => 'Zásilka zůstává aktivní u Zásilkovny.',
                default => 'Údaje zásilky byly uloženy. Stav odeslání objednávky se nastavuje zvlášť po předání balíku.',
            }; ?>
            <?php if (($_GET['packeta_saved'] ?? '') === '1' || isset($_GET['packeta_result'])): ?><p class="panel-notice" role="status"><?= $escape($packetaNotice) ?></p><?php endif; ?>
            <?php if ($packetaShipment !== null && $packetaShipment['status'] === 'created'): ?>
              <?php $submittedPacket = json_decode((string) ($packetaShipment['submitted_json'] ?? ''), true); ?>
              <p class="panel-help">Zásilka je vytvořená v systému Zásilkovny. <?= $packetaShipment['method'] === 'zasilkovna_home' && !$packetaShipment['courier_number'] ? 'U doručení domů nejprve vyžádej číslo dopravce, potom stáhni štítek.' : 'Štítek je připraven ke stažení.' ?> Po zabalení označ balík čitelným číslem nebo na něj nalep štítek.</p>
              <?php if (!empty($packetaShipment['last_error'])): ?><p class="panel-error" role="alert"><?= $escape($packetaShipment['last_error']) ?></p><?php endif; ?>
              <dl class="panel-order-facts">
                <div><dt>Číslo na balík</dt><dd><strong><?= $escape($packetaShipment['barcode_text'] ?: $packetaShipment['barcode']) ?></strong></dd></div>
                <div><dt>Kód zásilky</dt><dd><strong><?= $escape($packetaShipment['barcode']) ?></strong></dd></div>
                <?php if ($packetaShipment['courier_number']): ?><div><dt>Číslo dopravce</dt><dd><?= $escape($packetaShipment['courier_number']) ?></dd></div><?php endif; ?>
                <div><dt>Hmotnost</dt><dd><?= $escape($packetaShipment['weight_kg']) ?> kg</dd></div>
                <?php if (is_array($submittedPacket)): ?>
                  <div><dt>Podaný kontakt</dt><dd><?= $escape(trim((string) ($submittedPacket['name'] ?? '') . ' ' . (string) ($submittedPacket['surname'] ?? ''))) ?><br><?= $escape($submittedPacket['email'] ?? '') ?><br><?= $escape($submittedPacket['phone'] ?? '') ?></dd></div>
                  <?php if ($packetaShipment['method'] === 'zasilkovna_home'): ?>
                    <div><dt>Podaná adresa HD</dt><dd><?= $escape(trim((string) ($submittedPacket['street'] ?? '') . ' ' . (string) ($submittedPacket['houseNumber'] ?? ''))) ?><br><?= $escape(trim((string) ($submittedPacket['zip'] ?? '') . ' ' . (string) ($submittedPacket['city'] ?? ''))) ?></dd></div>
                  <?php else: ?><div><dt>ID výdejního místa</dt><dd><?= $escape($submittedPacket['addressId'] ?? '') ?></dd></div><?php endif; ?>
                <?php endif; ?>
              </dl>
              <?php if ($packetaConfigured && $packetaShipment['method'] === 'zasilkovna_home' && !$packetaShipment['courier_number']): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-courier"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <button class="panel-button" type="submit">Vyžádat číslo dopravce pro HD</button>
                </form>
              <?php elseif ($packetaConfigured): ?>
                <p><a class="panel-button" href="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id'] . '&packeta_label=1') ?>">Stáhnout štítek PDF</a></p>
              <?php endif; ?>
              <?php if ($packetaTrackingUrl !== null): ?><p><a class="panel-text-link" href="<?= $escape($packetaTrackingUrl) ?>" target="_blank" rel="noopener noreferrer">Sledovat zásilku u Zásilkovny →</a></p><?php endif; ?>
              <?php if ($packetaCancelReady && $packetaConfigured && !in_array($order['status'], ['shipped', 'completed', 'cancelled', 'test'], true)): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-cancel"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label class="panel-check"><input type="checkbox" name="packeta_cancel_confirmed" value="1" required> Potvrzuji, že balík ještě nebyl fyzicky předán dopravci. Storno ruší zásilku u Zásilkovny, nikoli objednávku nebo platbu.</label>
                  <button class="panel-button" type="submit">Stornovat zásilku u Zásilkovny</button>
                </form>
              <?php elseif (!$packetaCancelReady): ?><p class="panel-help">Pro možnost storna <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
              <p class="panel-help">Samotné vytvoření čísla ještě neznamená, že je balík fyzicky odeslaný.</p>
            <?php elseif ($packetaShipment !== null && in_array($packetaShipment['status'], ['cancelling', 'cancel_uncertain'], true)): ?>
              <p class="panel-error" role="alert">Výsledek storna zásilky <?= $escape($packetaShipment['barcode'] ?? '') ?> není jistý. Zkontroluj zásilku v klientské sekci Zásilkovny. Nové podání je zatím zablokované.</p>
              <?php if ($packetaShipment['status'] !== 'cancelling' || strtotime((string) $packetaShipment['updated_at'] . ' UTC') <= time() - 60): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-cancel-confirmed"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label class="panel-check"><input type="checkbox" name="packeta_cancel_checked" value="1" required> Ověřil/a jsem v klientské sekci, že je zásilka stornovaná.</label>
                  <button class="panel-button" type="submit">Potvrdit storno a povolit nové podání</button>
                </form>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-cancel-not-done"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label class="panel-check"><input type="checkbox" name="packeta_cancel_checked" value="1" required> Ověřil/a jsem v klientské sekci, že zásilka zůstala aktivní.</label>
                  <button class="panel-button" type="submit">Ponechat aktivní zásilku</button>
                </form>
              <?php else: ?><p class="panel-help">Storno ještě probíhá. Po minutě obnov stránku.</p><?php endif; ?>
            <?php elseif ($packetaShipment !== null && in_array($packetaShipment['status'], ['submitting', 'uncertain'], true)): ?>
              <p class="panel-error" role="alert">Výsledek podání není jistý. Vyhledej v klientské sekci Zásilkovny objednávku <?= $escape($order['order_number']) ?>. Další podání neprováděj, dokud nezjistíš, zda zásilka vznikla.</p>
              <?php if ($packetaShipment['status'] !== 'submitting' || strtotime((string) $packetaShipment['updated_at'] . ' UTC') <= time() - 60): ?>
              <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-reconcile"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                <label>Nalezené číslo zásilky<input name="packeta_barcode" placeholder="Z1234567890" pattern="Z[0-9]{1,20}" required></label>
                <label><input type="checkbox" name="packeta_checked" value="1" required> Ověřil/a jsem, že tato zásilka patří k objednávce.</label>
                <button class="panel-button" type="submit">Uložit nalezenou zásilku</button>
              </form>
              <?php else: ?><p class="panel-help">Podání ještě probíhá. Pro kontrolu po minutě obnov stránku.</p><?php endif; ?>
              <?php if (strtotime((string) $packetaShipment['updated_at'] . ' UTC') <= time() - 60): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-retry"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label><input type="checkbox" name="packeta_not_created" value="1" required> Ověřil/a jsem v klientské sekci, že zásilka nevznikla.</label>
                  <button class="panel-button" type="submit">Povolit nový pokus</button>
                </form>
              <?php endif; ?>
            <?php else: ?>
              <?php if ($packetaShipment !== null && $packetaShipment['status'] === 'cancelled'): ?><p class="panel-notice">Původní zásilka <?= $escape($packetaShipment['barcode'] ?? '') ?> byla stornována.<?= ($order['fulfillment_source'] ?? 'own') === 'external' ? '' : ' Oprav údaje a vytvoř novou zásilku.' ?></p><?php endif; ?>
              <?php if ($packetaShipment !== null && $packetaShipment['status'] === 'rejected'): ?>
                <p class="panel-error" role="alert"><?= $escape($packetaShipment['last_error'] ?: 'Zásilkovna zásilku odmítla.') ?></p>
                <?php if (str_contains((string) ($packetaShipment['last_error'] ?? ''), 'eshop_id:')): ?>
                  <?php $rejectedPacket = json_decode((string) ($packetaShipment['submitted_json'] ?? ''), true); ?>
                  <?php $sentSender = is_array($rejectedPacket) ? ($rejectedPacket['eshop'] ?? '') : ''; ?>
                  <p class="panel-help">Odeslané označení odesílatele: <strong><?= $escape(is_string($sentSender) ? $sentSender : '') ?></strong>. Zkopíruj přesné <strong>Označení</strong> ze čtvrtého sloupce <a href="https://client.packeta.com/senders/" target="_blank" rel="noopener noreferrer">seznamu odesílatelů Zásilkovny</a> do <a href="<?= $escape($adminUrl . '?section=settings') ?>">nastavení obchodu</a>. Potom zásilku podej znovu.</p>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!$paid): ?><p class="panel-help"><?= $onlineGateway ? 'Nejdřív vyčkej na potvrzení platby ' . $gatewayName . ' nebo načti aktuální stav brány.' : 'Nejdřív ověř platbu na bankovním výpisu a označ ji jako přijatou.' ?></p><?php endif; ?>
              <?php if (($order['fulfillment_source'] ?? 'own') === 'external'): ?><p class="panel-help">Expedici zajišťuje externí dodavatel. Stav objednávky nastav v panelu Vyřízení; zásilku tímto účtem Zásilkovny nepodávej.</p><?php endif; ?>
              <?php if (($order['fulfillment_source'] ?? 'own') !== 'external' && ($shipping['method'] ?? '') === 'zasilkovna_pickup' &&
                  (($shipping['pickup_verified'] ?? false) !== true ||
                  preg_match('/^[0-9]{1,12}$/D', (string) ($shipping['pickup_code'] ?? '')) !== 1)): ?>
                <p class="panel-help">U této starší objednávky nebylo výdejní místo ověřeno. Zadej správné ID; před podáním ho server ověří přes Zásilkovnu.</p>
              <?php endif; ?>
              <?php if ($packetaReady && $packetaConfigured && $paid && !$gatewayDispatchBlocked && ($order['fulfillment_source'] ?? 'own') !== 'external' && !in_array($order['status'], ['shipped', 'cancelled', 'completed', 'test'], true)): ?>
                <?php
                $recipientParts = preg_split('/\s+/u', trim((string) ($shipping['recipient'] ?? $shipping['name'] ?? ''))) ?: [];
                $defaultSurname = count($recipientParts) > 1 ? array_pop($recipientParts) : '';
                $defaultFirstName = implode(' ', $recipientParts);
                $streetOriginal = trim((string) ($shipping['street'] ?? ''));
                $streetMatch = [];
                $streetSplit = preg_match('/^(.+?)\s+(\d+[a-zA-Z]?(?:\/\d+[a-zA-Z]?)?)$/uD', $streetOriginal, $streetMatch) === 1;
                $entered = ($method ?? 'GET') === 'POST' && $packetaAction === 'packeta-create' &&
                    (string) ($_POST['id'] ?? '') === (string) $order['id'] ?
                    array_filter($_POST, 'is_string') : [];
                ?>
                <details class="panel-order-accordion panel-order-edit-draft" <?= $entered !== [] ? 'open' : '' ?>><summary>Zkontrolovat údaje a vytvořit zásilku</summary>
                <p class="panel-help">Zkontroluj údaje příjemce a hmotnost již zabaleného balíku. API použije číslo objednávky <?= $escape($order['order_number']) ?> a platbu bez dobírky. Případné opravy kontaktu a adresy níže se uloží k zásilce; původní objednávka zůstane v historii.</p>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-create"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label>Jméno<input name="first_name" value="<?= $escape($entered['first_name'] ?? $defaultFirstName) ?>" maxlength="70" required></label>
                  <label>Příjmení<input name="surname" value="<?= $escape($entered['surname'] ?? $defaultSurname) ?>" maxlength="70" required></label>
                  <label>E-mail<input name="email" type="email" value="<?= $escape($entered['email'] ?? $order['customer_email']) ?>" maxlength="254" required></label>
                  <label>Telefon<input name="phone" type="tel" value="<?= $escape($entered['phone'] ?? $shipping['phone'] ?? '') ?>" maxlength="40" required></label>
                  <label>Hmotnost balíku v kg<input name="weight_kg" type="text" inputmode="decimal" value="<?= $escape($entered['weight_kg'] ?? '1') ?>" placeholder="např. 0,75" required></label>
                  <?php if ($shipping['method'] === 'zasilkovna_home'): ?>
                    <label>Ulice<input name="street" value="<?= $escape($entered['street'] ?? ($streetSplit ? $streetMatch[1] : $streetOriginal)) ?>" maxlength="120" required></label>
                    <label>Číslo domu<input name="house_number" value="<?= $escape($entered['house_number'] ?? ($streetSplit ? $streetMatch[2] : '')) ?>" maxlength="30" required></label>
                    <label>Město<input name="city" value="<?= $escape($entered['city'] ?? $shipping['city'] ?? '') ?>" maxlength="120" required></label>
                    <label>PSČ<input name="postal_code" value="<?= $escape($entered['postal_code'] ?? $shipping['postal_code'] ?? '') ?>" maxlength="20" required></label>
                    <p class="panel-help">Ověř rozdělení ulice a čísla domu a úplnou dodací adresu před podáním.</p>
                  <?php else: ?>
                    <label>ID výdejního místa Zásilkovny<input name="pickup_point_id" value="<?= $escape($entered['pickup_point_id'] ?? $shipping['pickup_code'] ?? '') ?>" maxlength="80" required></label>
                    <p class="panel-help">Původní místo: <?= $escape($shipping['pickup_point'] ?? '') ?> (ID <?= $escape($shipping['pickup_code'] ?? '') ?>). Pokud se místo mění, zadej ID nového místa z klientské sekce Zásilkovny. Před podáním ho server ověří; původní objednávka se nemění.</p>
                  <?php endif; ?>
                  <button class="panel-button" type="submit">Vytvořit zásilku u Zásilkovny</button>
                </form>
                </details>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($cancelledPackets !== []): ?>
              <p class="panel-help">Dříve stornované zásilky: <?php foreach ($cancelledPackets as $old): ?><?= $escape($old['barcode']) ?> (<?= $escape($old['cancelled_at']) ?>) <?php endforeach; ?></p>
            <?php endif; ?>
          </div>
          </details>
        <?php endif; ?>
        <?php if (in_array($shipping['method'] ?? '', ['balikovna_pickup', 'gls_pickup', 'gls_home'], true)): ?>
          <?php $carrierBalik = ($shipping['method'] ?? '') === 'balikovna_pickup'; ?>
          <details class="panel-order-accordion panel-order-dispatch" <?= $carrierShipment !== null || $orderError !== '' || isset($_GET['carrier_saved']) ? 'open' : '' ?>>
            <summary>Podání zásilky <?= $carrierBalik ? 'Balíkovnou' : 'GLS' ?><?php if ($carrierShipment !== null): ?> · <?= $carrierShipment['status'] === 'registered' ? 'číslo ' . $escape($carrierShipment['tracking_number']) : 'podklady uloženy' ?><?php endif; ?></summary>
          <div class="panel-packeta-dispatch">
            <?php if (!$carrierReady): ?>
              <p class="panel-help">Nejdřív <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
            <?php else: ?>
              <?php if (($_GET['carrier_saved'] ?? '') === 'carrier-save'): ?><p class="panel-notice" role="status">Podklady byly uloženy. Zásilka ještě nevznikla u dopravce.</p><?php endif; ?>
              <?php if (($_GET['carrier_saved'] ?? '') === 'carrier-register'): ?><p class="panel-notice" role="status">Číslo zásilky od dopravce bylo uloženo. Objednávku označ jako odeslanou až po předání balíku.</p><?php endif; ?>
              <?php if ($carrierShipment !== null): ?>
                <p class="panel-order-state"><?= $carrierShipment['status'] === 'draft' ? ($carrierBalik ? 'Údaje připraveny · podání v Balíkovně čeká' : 'CSV připraveno · čeká na import') : 'Číslo dopravce zapsáno' ?></p>
                <?php if ($carrierShipment['status'] === 'registered'): ?>
                  <dl class="panel-order-facts"><div><dt>Číslo zásilky</dt><dd><strong><?= $escape($carrierShipment['tracking_number']) ?></strong></dd></div></dl>
                <?php endif; ?>
                <?php if ($carrierBalik): ?>
                  <dl class="panel-order-facts">
                    <div><dt>Příjemce</dt><dd><?= $escape($carrierShipment['draft']['recipient'] ?? '') ?></dd></div>
                    <div><dt>Kontakt</dt><dd><?= $escape($carrierShipment['draft']['email'] ?? '') ?> · <?= $escape($carrierShipment['draft']['phone'] ?? '') ?></dd></div>
                    <div><dt>Výdejní místo</dt><dd><?= $escape($carrierShipment['draft']['pickup_point'] ?? '') ?> · <?= $escape($carrierShipment['draft']['pickup_address'] ?? '') ?><br>ID <?= $escape($carrierShipment['draft']['pickup_code'] ?? '') ?> · PSČ <?= $escape($carrierShipment['draft']['postal_code'] ?? '') ?></dd></div>
                    <div><dt>Hmotnost</dt><dd><?= $escape($carrierShipment['draft']['weight_kg'] ?? '') ?> kg</dd></div>
                  </dl>
                <?php elseif (!$gatewayDispatchBlocked): ?><p><a class="panel-button" href="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id'] . '&carrier_csv=1') ?>">Stáhnout CSV pro GLS e-Balík</a></p><?php endif; ?>
              <?php endif; ?>
              <?php if ($carrierBalik): ?>
                <p class="panel-help">Vyhledávací mapa Balíkovny pouze vrací vybrané místo; sama nevytváří zásilku ani čárový kód. Údaje níže si připrav pro <a href="https://www.balikovna.cz/cs/web/guest/poslat-balik" target="_blank" rel="noopener noreferrer">podání na webu Balíkovny ↗</a>. Po vytvoření zásilky tam získáš štítek nebo podací kód. Tento e-shop bez podání u dopravce platný štítek nevytvoří.</p>
              <?php else: ?>
                <p class="panel-help">CSV má 17 sloupců bez hlavičky pro <strong>výchozí import GLS e-Balík</strong>. U ParcelShopu je ID místa v posledním sloupci; při doručení na adresu zůstává prázdný. Zkontroluj náhled importu, vygenerovaný štítek i cenu dopravy v portálu.</p>
              <?php endif; ?>
              <?php if ($carrierShipment === null || $carrierShipment['status'] === 'draft'): ?>
                <?php if (!$paid || $gatewayDispatchBlocked || ($order['fulfillment_source'] ?? 'own') !== 'own' || in_array($order['status'], ['shipped', 'completed', 'cancelled', 'test'], true)): ?>
                  <p class="panel-help">Podklady lze připravovat jen pro zaplacenou aktivní objednávku expedovanou obchodem. Externí dodavatel podává sám.</p>
                <?php else: ?>
                  <?php
                    $addressDefaults = \SimpleStore\Checkout\CarrierShipmentDraft::addressDefaults($shipping);
                    $nameDefaults = \SimpleStore\Checkout\CarrierShipmentDraft::nameDefaults((string) ($shipping['recipient'] ?? $shipping['name'] ?? ''));
                    $savedDraft = is_array($carrierShipment['draft'] ?? null) ? $carrierShipment['draft'] : [];
                    $enteredDraft = ($method ?? 'GET') === 'POST' && $carrierAction === 'carrier-save' &&
                        (string) ($_POST['id'] ?? '') === (string) $order['id'] ? $_POST : [];
                    $draftValue = static fn (string $key, string $default): string =>
                        is_string($enteredDraft[$key] ?? null) ? $enteredDraft[$key] :
                        (is_string($savedDraft[$key] ?? null) ? $savedDraft[$key] : $default);
                  ?>
                  <?php if ($carrierShipment !== null): ?><details class="panel-order-accordion panel-order-edit-draft" <?= $enteredDraft !== [] ? 'open' : '' ?>><summary>Upravit podklady k podání</summary><?php endif; ?>
                  <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="carrier-save"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <label>Příjemce / kontaktní osoba<input name="recipient" value="<?= $escape($draftValue('recipient', (string) ($shipping['recipient'] ?? $shipping['name'] ?? ''))) ?>" maxlength="140" required></label>
                    <label>E-mail<input type="email" name="email" value="<?= $escape($draftValue('email', (string) ($order['customer_email'] ?? ''))) ?>" maxlength="254" required></label>
                    <label>Telefon<input type="tel" name="phone" value="<?= $escape($draftValue('phone', (string) ($shipping['phone'] ?? ''))) ?>" maxlength="40" required></label>
                    <label>Hmotnost zabalené zásilky v kg<input name="weight_kg" inputmode="decimal" value="<?= $escape($draftValue('weight_kg', '1')) ?>" required></label>
                    <?php if ($carrierBalik): ?>
                      <p class="panel-help">Vybrané místo: <?= $escape($shipping['pickup_point'] ?? '') ?> · <?= $escape($shipping['pickup_address'] ?? '') ?> · ID <?= $escape($shipping['pickup_code'] ?? '') ?>.</p>
                    <?php else: ?>
                      <label>Jméno příjemce<input name="first_name" value="<?= $escape($draftValue('first_name', $nameDefaults['first_name'])) ?>" maxlength="70" required></label>
                      <label>Příjmení příjemce<input name="surname" value="<?= $escape($draftValue('surname', $nameDefaults['surname'])) ?>" maxlength="70" required></label>
                      <p class="panel-help"><?= ($shipping['method'] ?? '') === 'gls_pickup' ? 'Adresu místa GLS zkontroluj podle vybraného bodu v objednávce.' : 'Zkontroluj adresu příjemce.' ?> Jméno a číslo domu jsme rozdělili automaticky; před exportem je ověř.</p>
                      <label>Ulice<input name="street" value="<?= $escape($draftValue('street', $addressDefaults['street'])) ?>" maxlength="120" required></label>
                      <label>Číslo domu<input name="house_number" value="<?= $escape($draftValue('house_number', $addressDefaults['house_number'])) ?>" maxlength="30" required></label>
                      <label>Obec<input name="city" value="<?= $escape($draftValue('city', $addressDefaults['city'])) ?>" maxlength="120" required></label>
                      <label>PSČ<input name="postal_code" value="<?= $escape($draftValue('postal_code', $addressDefaults['postal_code'])) ?>" maxlength="12" required></label>
                    <?php endif; ?>
                    <button class="panel-button" type="submit"><?= $carrierShipment === null ? 'Uložit podklady k podání' : 'Upravit podklady' ?></button>
                  </form>
                  <?php if ($carrierShipment !== null): ?></details><?php endif; ?>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($carrierShipment !== null && $carrierShipment['status'] === 'draft' && !$gatewayDispatchBlocked): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="carrier-register"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label>Skutečné číslo zásilky od dopravce<input name="tracking_number" autocomplete="off" minlength="6" maxlength="50" required></label>
                  <label class="panel-check"><input type="checkbox" name="carrier_confirmed" value="1" required> Zkontroloval/a jsem podání u dopravce a opisuji číslo skutečně vytvořené zásilky.</label>
                  <button class="panel-button" type="submit">Zapsat číslo dopravce</button>
                </form>
              <?php endif; ?>
              <p class="panel-help">Uložení podkladů ani čísla v e-shopu nenahrazuje podání u dopravce. Balík označ jeho štítkem nebo kódem. Stav vyřízení objednávky nastav zvlášť po skutečném předání.</p>
            <?php endif; ?>
          </div>
          </details>
        <?php endif; ?>
      </section>
    </div>
    <aside class="panel-panel panel-order-payment">
      <h2>Platba</h2>
      <p class="panel-order-state <?= $paymentHighlight ? 'is-paid' : 'is-pending' ?>"><?= $escape($paymentDisplayLabel) ?></p>
      <dl class="panel-order-facts">
        <div><dt>Metoda</dt><dd><?= $bankTransfer ? 'Bankovní převod' : ($onlineGateway ? $gatewayName : $escape($order['payment_method'] ?? 'Neuvedeno')) ?></dd></div>
        <div><dt>Částka</dt><dd><strong><?= $orderMoney($order['total_czk'] ?? 0) ?></strong></dd></div>
        <?php if ($bankTransfer): ?><div><dt>Variabilní symbol</dt><dd><strong><?= $escape($order['variable_symbol'] ?? 'Neuveden') ?></strong></dd></div><?php endif; ?>
        <?php if ($onlineGateway && !empty($order['provider_reference'])): ?><div><dt><?= $btcpayPayment ? 'Faktura' : 'Transakce' ?> <?= $gatewayName ?></dt><dd><strong><?= $escape($order['provider_reference']) ?></strong></dd></div><?php endif; ?>
        <?php if ($goPayPayment && $goPayState !== null): ?>
          <div><dt>Stav u GoPay</dt><dd><?= $escape($goPayStatusLabel($goPayState['status'] ?? '')) ?><?= (int) ($goPayState['test_mode'] ?? 0) === 1 ? ' · testovací' : '' ?></dd></div>
          <?php if (empty($order['provider_reference']) && !empty($goPayState['payment_id'])): ?><div><dt>ID platby GoPay</dt><dd><?= $escape($goPayState['payment_id']) ?></dd></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($btcpayPayment && $btcpayState !== null): ?>
          <div><dt>Stav u BTCPay</dt><dd><?= $escape($btcpayStatusLabel($btcpayState['status'] ?? '')) ?></dd></div>
          <?php if (empty($order['provider_reference']) && !empty($btcpayState['invoice_id'])): ?><div><dt>ID faktury BTCPay</dt><dd><?= $escape($btcpayState['invoice_id']) ?></dd></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($paid && !empty($order['payment_paid_at'])): ?><div><dt><?= $onlineGateway ? 'Potvrzeno bránou' : 'Ověřeno' ?></dt><dd><?= $escape($order['payment_paid_at']) ?><?php if (!empty($order['payment_verified_by'])): ?> · správce #<?= (int) $order['payment_verified_by'] ?><?php endif; ?></dd></div><?php endif; ?>
        <?php if ($bankTransfer): ?><div><dt>Účet</dt><dd><?= $escape($payment['account_display'] ?? 'Neuveden') ?></dd></div><?php endif; ?>
        <?php if ($bankTransfer && !empty($payment['iban'])): ?><div><dt>IBAN</dt><dd><?= $escape($payment['iban']) ?></dd></div><?php endif; ?>
        <?php if ($bankTransfer && !empty($order['payment_due_at'])): ?><div><dt>Splatnost</dt><dd><?= $escape($order['payment_due_at']) ?></dd></div><?php endif; ?>
      </dl>
      <div class="panel-order-invoice">
        <strong>Faktura</strong>
        <?php if ($orderInvoice !== null): ?>
          <span>Číslo <?= $escape($orderInvoice['document_number']) ?></span>
          <div class="panel-order-head-actions"><a class="panel-button" href="<?= $escape($invoiceDetailUrl . '&print=1') ?>" target="_blank" rel="noopener noreferrer">Zobrazit fakturu ↗</a><a class="panel-text-link" href="<?= $escape($invoiceDetailUrl) ?>">Detail a odeslání</a></div>
        <?php else: ?><span>Dosud nevystavena</span><?php endif; ?>
      </div>
      <?php if ($goPayPayment && $goPayState !== null && in_array($goPayState['status'] ?? '', ['creating', 'uncertain'], true)): ?>
        <p class="panel-error" role="alert">Založení platby má nejasný výsledek. Neopakuj požadavek naslepo; nejprve vyhledej transakci v administraci GoPay podle čísla objednávky.</p>
      <?php endif; ?>
      <?php if ($btcpayPayment && $btcpayState !== null && in_array($btcpayState['status'] ?? '', ['creating', 'uncertain'], true)): ?>
        <p class="panel-error" role="alert">Založení faktury má nejasný výsledek. Před dalším pokusem ji vyhledej v BTCPay Serveru podle čísla objednávky.</p>
      <?php endif; ?>
      <?php if ($bankTransfer && !$paid && ($order['payment_status'] ?? '') === 'pending' && !in_array($order['status'], ['cancelled', 'test'], true)): ?>
        <form class="panel-order-confirm" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
          <input type="hidden" name="action" value="mark-order-paid">
          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
          <label><input type="checkbox" name="bank_checked" value="1" required> Ověřil/a jsem na bankovním výpisu částku a variabilní symbol této objednávky.</label>
          <button class="panel-button" type="submit">Označit platbu jako přijatou</button>
        </form>
      <?php endif; ?>
      <?php if ($onlineGateway && !empty($order['provider_reference'])): ?>
        <?php if ($gatewayConfigured): ?>
          <form class="panel-order-confirm" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <input type="hidden" name="action" value="<?= $btcpayPayment ? 'btcpay-refresh' : ($goPayPayment ? 'gopay-refresh' : 'comgate-refresh') ?>">
            <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
            <button class="panel-button" type="submit">Ověřit stav u <?= $gatewayName ?></button>
          </form>
        <?php else: ?><p class="panel-help">Pro opětovné ověření stavu u <?= $gatewayName ?> vyplň přihlašovací údaje v <a href="<?= $escape($adminUrl . '?section=settings') ?>">nastavení obchodu</a>.</p><?php endif; ?>
      <?php endif; ?>
      <?php if ($bankTransfer && $paid && $orderControlsReady): ?>
        <details class="panel-order-accordion panel-order-controls" aria-label="Oprava platby">
          <summary>Opravit chybně potvrzenou platbu</summary>
          <p class="panel-help">Vrátí stav na „Čeká na platbu“. Bankovní pohyb se tím nemění. Původní potvrzení, částka a důvod zůstanou v účetních zásazích.</p>
          <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-payment"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="confirmation" value="not_received">
            <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required placeholder="Například platba označena jako přijatá omylem"></textarea></label>
            <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Ověřil/a jsem výpis a opravuji ručně potvrzený stav platby.</label>
            <button class="panel-button" type="submit">Vrátit platbu na čekající</button>
          </form>
        </details>
      <?php endif; ?>
      <?php if ($bankTransfer): ?><p class="panel-help">Stav platby se z banky nenačítá automaticky.</p><?php endif; ?>
      <?php if ($onlineGateway): ?><p class="panel-help">Stav platby potvrzuje <?= $gatewayName ?>. Samotný návrat zákazníka na web platbu nepotvrzuje.</p><?php endif; ?>
      <?php if (($bankTransfer || $onlineGateway) && $paid && $orderTaxReady && ($order['status'] ?? '') !== 'test'): ?>
        <details class="panel-order-accordion panel-order-controls" aria-label="Daňová evidence objednávky">
          <summary>Daňová evidence a vystavení faktury</summary>
          <?php if ($bankTransfer): ?>
          <?php if ($orderReceipt !== null): ?>
            <p class="panel-notice">Příjem <?= $orderMoney($orderReceipt['amount_czk']) ?> ze dne <?= $escape($orderReceipt['entry_date']) ?> je zapsaný v <a href="<?= $escape($adminUrl . '?section=accounting&tab=money') ?>">peněžním deníku</a>.</p>
          <?php else: ?>
            <p class="panel-help">Zaplaceno je stav objednávky. Do deníku zapiš skutečné datum připsání částky podle bankovního výpisu.</p>
            <form class="panel-form" method="post" action="<?= $escape($adminUrl . '?section=accounting') ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="tax-link-payment"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
              <label>Datum připsání<input type="date" name="entry_date" required></label>
              <label>Bankovní reference<input name="reference" maxlength="100" value="<?= $escape($order['variable_symbol'] ?? '') ?>"></label>
              <button class="panel-button" type="submit">Zapsat příjem <?= $orderMoney($order['total_czk']) ?></button>
            </form>
          <?php endif; ?>
          <?php elseif ($btcpayPayment): ?>
            <p class="panel-help">BTCPay potvrdil úhradu zákazníka. Příjem bitcoinu a jeho hodnotu v Kč zapiš do <a href="<?= $escape($adminUrl . '?section=accounting&tab=money') ?>">peněžního deníku</a> podle skutečných podkladů.</p>
          <?php else: ?>
            <p class="panel-help"><?= $gatewayName ?> potvrdil úhradu zákazníka. Výplatu a poplatky zaznamenej v <a href="<?= $escape($adminUrl . '?section=accounting&tab=money') ?>">peněžním deníku</a> podle skutečného vyúčtování brány a bankovního výpisu; mohou zahrnovat více objednávek.</p>
          <?php endif; ?>
          <?php if ($orderInvoice !== null): ?>
            <p>Faktura <strong><?= $escape($orderInvoice['document_number']) ?></strong> · <a href="<?= $escape($invoiceDetailUrl) ?>">detail, tisk a e-mail</a></p>
          <?php elseif ($gatewayDispatchBlocked): ?>
            <p class="panel-help">Před vystavením faktury nejprve ověř u <?= $gatewayName ?> její aktuální stav a případné vrácení platby.</p>
          <?php elseif ($orderInvoiceReady && \SimpleStore\Accounting\TaxEvidenceRepository::invoiceReady($sellerSettings)): ?>
            <h3>Vystavit fakturu</h3>
            <form class="panel-form" method="post" action="<?= $escape($adminUrl . '?section=accounting') ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="invoice-issue"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
              <label>Odběratel<input name="buyer_name" value="<?= $escape($shipping['recipient'] ?? $shipping['name'] ?? '') ?>" maxlength="120" required></label>
              <label>Ulice a číslo<input name="buyer_street" value="<?= $escape($shipping['street'] ?? '') ?>" maxlength="160"></label>
              <label>Město<input name="buyer_city" value="<?= $escape($shipping['city'] ?? '') ?>" maxlength="100"></label>
              <label>PSČ<input name="buyer_postal_code" value="<?= $escape($shipping['postal_code'] ?? '') ?>" maxlength="6"></label>
              <label>IČO odběratele (volitelné)<input name="buyer_ico" maxlength="8"></label>
              <button class="panel-button" type="submit">Vystavit a připravit e-mail s fakturou</button>
              <p class="panel-help">Zkontroluj fakturační údaje. Tiskový doklad lze uložit jako PDF v prohlížeči.</p>
            </form>
          <?php else: ?><p class="panel-help">Před vystavením faktury doplň <a href="<?= $escape($adminUrl . '?section=accounting&tab=settings') ?>">údaje OSVČ</a> a aktualizuj SQL tabulky.</p><?php endif; ?>
        </details>
      <?php endif; ?>
      <?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Stav vyřízení objednávky byl uložen.</p><?php endif; ?>
      <h2>Vyřízení</h2>
      <p class="panel-order-state"><?= $escape($orderFulfillmentLabel($order['status'] ?? '')) ?></p>
      <?php if (!in_array($order['status'], ['completed', 'cancelled', 'test'], true) && !$gatewayDispatchBlocked): ?>
      <?php $packetaMethod = in_array($shipping['method'] ?? '', ['zasilkovna_pickup', 'zasilkovna_home'], true);
      $fulfillmentSource = $order['fulfillment_source'] ?? 'own'; ?>
      <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="set-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
        <?php if ($paid && $order['status'] !== 'shipped' && $fulfillmentSourceReady): ?>
          <label>Expedici zajišťuje <select name="fulfillment_source">
            <option value="own" <?= $fulfillmentSource === 'own' ? 'selected' : '' ?>>Obchod</option>
            <option value="external" <?= $fulfillmentSource === 'external' ? 'selected' : '' ?>>Externí dodavatel</option>
          </select></label>
          <label>Dodavatel nebo poznámka k externí expedici (volitelné)<input name="fulfillment_note" maxlength="190" value="<?= $escape($order['fulfillment_note'] ?? '') ?>"></label>
        <?php else: ?><input type="hidden" name="fulfillment_source" value="<?= $escape($fulfillmentSource) ?>"><input type="hidden" name="fulfillment_note" value="<?= $escape($order['fulfillment_note'] ?? '') ?>"><?php endif; ?>
        <label>Vyřízení objednávky <select name="order_status">
          <?php if ($paid): ?>
            <?php if ($order['status'] !== 'shipped'): ?>
              <option value="processing" <?= $order['status'] === 'processing' ? 'selected' : '' ?>>Připravuje se</option>
              <option value="ready_to_ship" <?= $order['status'] === 'ready_to_ship' ? 'selected' : '' ?>>Připravena k odeslání</option>
            <?php endif; ?>
            <option value="shipped" <?= $order['status'] === 'shipped' ? 'selected' : '' ?>>Předána dopravci</option>
            <?php if ($order['status'] === 'shipped'): ?><option value="completed">Dokončena po doručení</option><?php endif; ?>
          <?php else: ?><option value="cancelled">Stornována (bez přijaté platby)</option><?php endif; ?>
        </select></label>
        <button class="panel-button" type="submit">Uložit stav</button>
        <p class="panel-help">Připravena k odeslání znamená zabalenou zásilku, případně potvrzení připravenosti od dodavatele. Stav Předána dopravci nastav až po skutečném předání balíku (u dodavatele po jeho potvrzení), Dokončena po doručení. <?= $packetaMethod ? 'Při expedici obchodem přes Zásilkovnu musí být místní zásilka vytvořená. Dodavatel může expedovat bez místního podání.' : '' ?> Dokončené a stornované objednávky se zákazníkovi přesunou do historie.</p>
        <?php if ($paid && !$fulfillmentSourceReady): ?><p class="panel-help">Pro volbu externího dodavatele <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
      </form>
      <?php elseif ($gatewayDispatchBlocked): ?>
        <p class="panel-help">Běžnou expedici po vrácení nebo neověřeném stavu platby nelze potvrdit. Pokud opravuješ omylem uložený stav, použij níže ovládání oprav s uvedením důvodu.</p>
      <?php endif; ?>
      <?php if (!$orderControlsReady): ?>
        <p class="panel-help">Pro opravy a mazání objednávek <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
      <?php else: ?>
        <?php if (in_array($order['status'], ['shipped', 'completed'], true)): ?>
          <details class="panel-order-accordion panel-order-controls" aria-label="Oprava chybného odeslání">
            <summary>Opravit omylem nastavený stav</summary>
            <p class="panel-help">Použij jen když balík ve skutečnosti nebyl předán dopravci. Oprava nemění platbu ani zásilku u dopravce; zůstane zapsána v historii.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="confirmation" value="not_handed">
              <label>Skutečný stav <select name="order_status"><option value="processing">Připravuje se</option><option value="ready_to_ship">Připravena k odeslání</option></select></label>
              <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required placeholder="Například omylem označeno jako odeslané"></textarea></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Potvrzuji, že balík nebyl předán dopravci.</label>
              <button class="panel-button" type="submit">Opravit chybné odeslání</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($order['status'] === 'completed'): ?>
          <details class="panel-order-accordion panel-order-controls" aria-label="Oprava dokončení">
            <summary>Vrátit z dokončeno na předáno dopravci</summary>
            <p class="panel-help">Když objednávka stále cestuje a doručení bylo potvrzeno omylem.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="order_status" value="shipped"><input type="hidden" name="confirmation" value="not_delivered">
              <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required></textarea></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Potvrzuji, že zásilka ještě nebyla doručena.</label>
              <button class="panel-button" type="submit">Vrátit na předáno dopravci</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($order['status'] === 'cancelled' && !$paid): ?>
          <details class="panel-order-accordion panel-order-controls" aria-label="Obnovení objednávky">
            <summary>Obnovit stornovanou objednávku</summary>
            <p class="panel-help">Zrušení bylo omyl; platba zůstává neověřená.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="order_status" value="new"><input type="hidden" name="confirmation" value="reopen">
              <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required></textarea></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Potvrzuji, že chci zrušenou objednávku znovu otevřít.</label>
              <button class="panel-button" type="submit">Obnovit objednávku</button>
            </form>
          </details>
        <?php endif; ?>
        <?php $canOfferDeletion = in_array(($order['payment_method'] ?? ''),
            ['bank_transfer', 'comgate', 'gopay', 'btcpay', 'legacy'], true) ||
            (($order['status'] ?? '') === 'test' && ($order['payment_method'] ?? '') === 'test' &&
                ($order['payment_status'] ?? '') === 'test'); ?>
        <?php if ($canOfferDeletion): ?>
          <details class="panel-order-accordion panel-order-controls" aria-label="Smazání objednávky">
            <summary>Trvale smazat objednávku</summary>
            <p class="panel-help">Objednávka zmizí z běžného seznamu a účtu zákazníka bez ohledu na stav platby či vyřízení. Vystavená faktura, finanční záznamy a zaplacené položky zůstanou v účetnictví, čísla skutečných zásilek v databázi pro dohledání u dopravce. Odstranění objednávky nestornuje platbu ani fyzickou zásilku u dopravce. Nejasné založení platby u brány nejprve ověř. Do důvodu nepiš osobní údaje.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="delete-order"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="confirmation" value="delete">
              <label>Důvod smazání <textarea name="reason" minlength="8" maxlength="190" required placeholder="Například test pokladny"></textarea></label>
              <label>Opiš číslo <?= $escape($order['order_number']) ?><input name="order_number" autocomplete="off" required></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Rozumím trvalému smazání; případná platba na bankovním účtu tím nezmizí.</label>
              <button class="panel-button" type="submit">Trvale smazat objednávku</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($orderEvents !== []): ?>
          <details class="panel-order-accordion panel-order-controls" aria-label="Historie zásahů">
            <summary>Historie zásahů správce (<?= count($orderEvents) ?>)</summary>
            <ul>
              <?php foreach ($orderEvents as $event): ?>
                <li><strong><?= $escape($event['created_at'] ?? '') ?></strong> · správce #<?= (int) ($event['admin_id'] ?? 0) ?> · <?php if (($event['action'] ?? '') === 'payment_correction'): ?>Platba: <?= $escape($orderPaymentLabel($event['old_status'] ?? '')) ?> → <?= $escape($orderPaymentLabel($event['new_status'] ?? '')) ?><?php elseif (($event['action'] ?? '') === 'shipping_changed'): ?>Doprava: <?= $escape($shippingMethodLabels[$event['old_status'] ?? ''] ?? $event['old_status'] ?? '') ?> → <?= $escape($shippingMethodLabels[$event['new_status'] ?? ''] ?? $event['new_status'] ?? '') ?><?php else: ?>Vyřízení: <?= $escape($orderFulfillmentLabel($event['old_status'] ?? '')) ?> → <?= $escape($orderFulfillmentLabel($event['new_status'] ?? '')) ?><?php endif; ?><br><?= $escape($event['reason'] ?? '') ?></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </aside>
  </div>
  <script defer src="<?= $escape($basePath . 'assets/admin-orders.js?v=' . filemtime(__DIR__ . '/../../assets/admin-orders.js')) ?>"></script>
<?php elseif ($ordersReady): ?>
  <?php
  $paymentFilter ??= 'all';
  $orderSearch ??= '';
  $offset ??= 0;
  $orderPageUrl ??= $orderBaseUrl . '&status=' . rawurlencode($statusFilter) .
      '&payment=' . rawurlencode($paymentFilter) . '&q=' . rawurlencode($orderSearch);
  $orderMethodLabel = static fn (mixed $method): string => match ($method) {
      'bank_transfer' => 'Převod na účet', 'comgate' => 'Comgate',
      'gopay' => 'GoPay', 'btcpay' => 'BTCPay Server', 'test' => 'Test', default => (string) $method,
  };
  ?>
  <nav class="panel-quick panel-order-filters" aria-label="Filtrovat objednávky">
    <?php foreach (['all' => 'Všechny', 'pending' => 'Čeká na platbu', 'paid' => 'Zaplaceno', 'processing' => 'Připravuje se', 'ready_to_ship' => 'Připraveno', 'shipped' => 'Předáno dopravci', 'completed' => 'Dokončeno', 'cancelled' => 'Zrušeno', 'test' => 'Testovací'] as $filter => $label): ?>
      <a href="<?= $escape($orderBaseUrl . '&status=' . $filter . '&payment=' . rawurlencode($paymentFilter) . '&q=' . rawurlencode($orderSearch)) ?>" <?= $statusFilter === $filter ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="panel-order-search" method="get" action="<?= $escape($adminUrl) ?>" role="search">
    <input type="hidden" name="section" value="orders"><input type="hidden" name="status" value="<?= $escape($statusFilter) ?>">
    <label>Číslo objednávky, e-mail nebo VS
      <input type="search" name="q" value="<?= $escape($orderSearch) ?>" maxlength="100" placeholder="Hledat objednávku">
    </label>
    <label>Způsob platby
      <select name="payment">
        <?php foreach (['all' => 'Všechny platby', 'bank_transfer' => 'Převod na účet', 'comgate' => 'Comgate', 'gopay' => 'GoPay', 'btcpay' => 'BTCPay Server', 'test' => 'Test'] as $method => $label): ?>
          <option value="<?= $escape($method) ?>" <?= $paymentFilter === $method ? 'selected' : '' ?>><?= $escape($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="panel-button" type="submit">Filtrovat</button>
    <?php if ($orderSearch !== '' || $paymentFilter !== 'all'): ?><a class="panel-text-link" href="<?= $escape($orderBaseUrl . '&status=' . $statusFilter) ?>">Vymazat filtry</a><?php endif; ?>
  </form>
  <?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Stav vyřízení byl uložen.</p><?php endif; ?>
  <?php if (($_GET['payment_saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Přijetí platby bylo potvrzeno.</p><?php endif; ?>
  <section class="panel-panel" aria-labelledby="panel-orders-list">
    <h2 id="panel-orders-list">Objednávky <span><?= count($orderPage['items']) ?> na stránce</span></h2>
    <?php if ($orderPage['items'] === []): ?><p class="panel-empty">V tomto přehledu zatím nejsou objednávky.</p><?php endif; ?>
    <?php if ($orderPage['items'] !== []): ?><div class="panel-order-table-wrap"><table class="panel-order-table">
      <thead><tr><th>Objednávka</th><th>Doručení</th><th>Platba</th><th>Vyřízení</th><th>Faktura</th><th>Celkem</th><th>Ovládání</th></tr></thead>
      <tbody>
      <?php foreach ($orderPage['items'] as $listed): ?>
        <?php
        $listedGoPay = ($listed['payment_method'] ?? '') === 'gopay';
        $listedGoPayState = $listedGoPay ? ($listed['gopay_payment_state'] ?? null) : null;
        $listedBtcpay = ($listed['payment_method'] ?? '') === 'btcpay';
        $listedBtcpayState = $listedBtcpay ? ($listed['btcpay_payment_state'] ?? null) : null;
        $listedRefunded = in_array($listedGoPayState, ['refunded', 'partially_refunded'], true);
        $listedGatewayBlocked = ($listed['payment_status'] ?? '') === 'paid' &&
            (($listedGoPay && $listedGoPayState !== 'paid') ||
            ($listedBtcpay && $listedBtcpayState !== 'settled'));
        $listedPaid = ($listed['payment_status'] ?? '') === 'paid' && !$listedGatewayBlocked;
        $listedPaymentLabel = $listedGoPayState === 'refunded' ? 'Platba vrácena' :
            ($listedGoPayState === 'partially_refunded' ? 'Platba částečně vrácena' :
            ($listedGatewayBlocked ? 'Platba k ověření' :
            ($listedBtcpay && $listedBtcpayState === 'processing' ? 'Platba se potvrzuje' :
            $orderPaymentLabel($listed['payment_status'] ?? ''))));
        $listedStatus = $listed['status'] ?? '';
        $listedShipping = json_decode((string) ($listed['shipping_json'] ?? ''), true);
        $listedShipping = is_array($listedShipping) ? $listedShipping : [];
        $listedSource = ($listed['fulfillment_source'] ?? 'own') === 'external' ? 'external' : 'own';
        $listedShippingMethod = $listedShipping['method'] ?? '';
        $listedPacketaBlocked = $listedSource === 'own' &&
            in_array($listedShippingMethod, ['zasilkovna_pickup', 'zasilkovna_home'], true) &&
            ($listed['shipment_status'] ?? '') !== 'created';
        $listedNext = match ($listedStatus) {
            'new' => $listedPaid ? ['processing', 'ready_to_ship', 'shipped'] : [],
            'processing' => $listedPaid ? ['ready_to_ship', 'shipped'] : [],
            'ready_to_ship' => $listedPaid ? ['processing', 'shipped'] : [],
            'shipped' => $listedPaid ? ['completed'] : [],
            default => [],
        };
        if ($listedPacketaBlocked) {
            $listedNext = array_values(array_diff($listedNext, ['ready_to_ship', 'shipped']));
        }
        $listedQuickUrl = $orderPageUrl . '&offset=' . $offset;
        $listedId = (int) $listed['id'];
        ?>
        <tr>
          <td data-label="Objednávka">
            <a class="panel-order-primary" href="<?= $escape($orderBaseUrl . '&id=' . $listedId) ?>"><?= $escape($listed['order_number'] ?? '') ?></a>
            <small><?= $escape($listed['created_at'] ?? '') ?><br><?= $escape($listedShipping['recipient'] ?? $listed['customer_email'] ?? '') ?></small>
            <small><?= $escape($listed['customer_email'] ?? '') ?> · <?= ($listed['payment_method'] ?? '') === 'test' ? 'TEST' : 'VS ' . $escape($listed['variable_symbol'] ?? '–') ?></small>
          </td>
          <td data-label="Doručení"><strong><?= $escape($listedShipping['label'] ?? 'Neuvedeno') ?></strong>
            <?php if ($listedSource === 'external'): ?><small>Externí dodavatel</small><?php endif; ?>
            <?php if (($listed['shipment_status'] ?? '') === 'created'): ?><small>Zásilka vytvořena</small><?php endif; ?>
            <?php if (($listed['carrier_shipment_status'] ?? '') === 'registered'): ?><small>Číslo zásilky zapsáno</small><?php endif; ?>
          </td>
          <td data-label="Platba"><span class="panel-order-state <?= $listedRefunded ? 'is-refunded' : ($listedPaid ? 'is-paid' : 'is-pending') ?>"><?= $escape($listedPaymentLabel) ?></span><small><?= $escape($orderMethodLabel($listed['payment_method'] ?? '')) ?></small>
            <?php if ($listedGatewayBlocked): ?><small>Ověř transakci v detailu objednávky.</small><?php endif; ?>
          </td>
          <td data-label="Vyřízení"><span class="panel-order-state <?= in_array($listedStatus, ['shipped', 'completed'], true) ? 'is-paid' : 'is-pending' ?>"><?= $escape($orderFulfillmentLabel($listedStatus)) ?></span>
            <?php if ($listedPacketaBlocked && $listedPaid && $listedNext !== []): ?><small>Pro přípravu zásilky otevři detail.</small><?php endif; ?>
          </td>
          <td data-label="Faktura"><?php if ((int) ($listed['invoice_id'] ?? 0) > 0): ?>
            <a class="panel-text-link" href="<?= $escape($adminUrl . '?section=accounting&tab=invoices&invoice_id=' . (int) $listed['invoice_id'] . '&print=1') ?>" target="_blank" rel="noopener noreferrer"><?= $escape($listed['invoice_number'] ?? 'Otevřít fakturu') ?></a>
            <?php else: ?><span class="panel-order-muted">Nevystavena</span><?php endif; ?>
          </td>
          <td data-label="Celkem" class="panel-order-amount"><?= $orderMoney($listed['total_czk'] ?? 0) ?></td>
          <td data-label="Ovládání" class="panel-order-actions">
            <a class="panel-text-link" href="<?= $escape($orderBaseUrl . '&id=' . $listedId) ?>">Otevřít detail</a>
            <?php if (!$listedPaid && ($listed['payment_method'] ?? '') === 'bank_transfer' &&
                ($listed['payment_status'] ?? '') === 'pending' &&
                !in_array($listedStatus, ['cancelled', 'test'], true)): ?>
              <details class="panel-order-quick"><summary>Potvrdit platbu</summary>
                <form method="post" action="<?= $escape($listedQuickUrl) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="mark-order-paid">
                  <input type="hidden" name="id" value="<?= $listedId ?>"><input type="hidden" name="return_list" value="1">
                  <label><input type="checkbox" name="bank_checked" value="1" required> Platbu jsem ověřil ve výpisu banky.</label>
                  <button type="submit">Označit jako zaplacené</button>
                </form>
              </details>
            <?php endif; ?>
            <?php if ($listedNext !== []): ?>
              <form class="panel-order-quick-status" method="post" action="<?= $escape($listedQuickUrl) ?>">
                <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="set-order-status">
                <input type="hidden" name="id" value="<?= $listedId ?>"><input type="hidden" name="return_list" value="1">
                <input type="hidden" name="fulfillment_source" value="<?= $escape($listedSource) ?>">
                <input type="hidden" name="fulfillment_note" value="<?= $escape($listed['fulfillment_note'] ?? '') ?>">
                <label for="order-quick-<?= $listedId ?>">Nový stav objednávky <?= $escape($listed['order_number'] ?? '') ?></label>
                <select id="order-quick-<?= $listedId ?>" name="order_status">
                  <?php foreach ($listedNext as $next): ?><option value="<?= $escape($next) ?>"><?= $escape($orderFulfillmentLabel($next)) ?></option><?php endforeach; ?>
                </select>
                <button type="submit">Uložit stav</button>
              </form>
            <?php elseif ($listedStatus === 'new' && !$listedPaid && ($listed['payment_status'] ?? '') === 'pending'): ?>
              <details class="panel-order-quick"><summary>Stornovat</summary>
                <form method="post" action="<?= $escape($listedQuickUrl) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="set-order-status">
                  <input type="hidden" name="id" value="<?= $listedId ?>"><input type="hidden" name="return_list" value="1">
                  <input type="hidden" name="order_status" value="cancelled">
                  <input type="hidden" name="fulfillment_source" value="<?= $escape($listedSource) ?>">
                  <input type="hidden" name="fulfillment_note" value="<?= $escape($listed['fulfillment_note'] ?? '') ?>">
                  <label><input type="checkbox" required> Potvrzuji storno objednávky.</label>
                  <button type="submit">Stornovat</button>
                </form>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div><?php endif; ?>
    <?php if ($ordersPreviousUrl !== '' || $ordersNextUrl !== ''): ?>
      <nav class="panel-quick panel-order-pages" aria-label="Stránky objednávek">
        <?php if ($ordersPreviousUrl !== ''): ?><a href="<?= $escape($ordersPreviousUrl) ?>">← Předchozí</a><?php endif; ?>
        <?php if ($ordersNextUrl !== ''): ?><a href="<?= $escape($ordersNextUrl) ?>">Další →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>
<?php endif; ?>
