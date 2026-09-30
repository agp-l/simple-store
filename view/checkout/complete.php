<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
$orderNumber = (string) ($order['order_number'] ?? $order['number'] ?? '');
$paymentAmount = (int) ($bankPayment['amount_czk'] ?? $order['total_czk'] ?? 0);
$qrPayload = (string) ($bankPayment['spayd'] ?? '');
$qrReady = str_starts_with($qrPayload, 'SPD*1.0*') && strlen($qrPayload) <= 512 && !preg_match('/[\x00-\x1F\x7F]/', $qrPayload);
$paymentPaid = ($order['payment_status'] ?? '') === 'paid';
$testOrder = ($order['payment_method'] ?? '') === 'test';
$comgateOrder = ($order['payment_method'] ?? '') === 'comgate';
$gopayOrder = ($order['payment_method'] ?? '') === 'gopay';
$comgateStatus = is_array($comgateState ?? null) ? (string) ($comgateState['status'] ?? '') : '';
$gopayStatus = is_array($gopayState ?? null) ? (string) ($gopayState['status'] ?? '') : '';
$gopayRefund = $gopayOrder && in_array($gopayStatus, ['refunded', 'partially_refunded'], true)
    ? $gopayStatus : '';
$onlineOrder = $comgateOrder || $gopayOrder;
$onlineProvider = $gopayOrder ? 'GoPay' : 'Comgate';
$onlineStatus = $gopayOrder ? $gopayStatus : $comgateStatus;
$onlineUncertain = in_array($onlineStatus, ['creating', 'uncertain'], true);
$onlineAvailable = $gopayOrder ? ($gopayAvailable ?? false) : ($comgateAvailable ?? false);
$onlineAction = $gopayOrder ? 'gopay_pay' : 'comgate_pay';
if ($testOrder) {
    $paymentMessage = 'je testovací. Nic neplaťte; nebyly vytvořeny platební údaje ani QR kód.';
} elseif ($gopayRefund === 'refunded') {
    $paymentMessage = 'má u GoPay evidované úplné vrácení platby.';
} elseif ($gopayRefund === 'partially_refunded') {
    $paymentMessage = 'má u GoPay evidované částečné vrácení platby.';
} elseif ($paymentPaid) {
    $paymentMessage = 'je zaplacená. Děkujeme.';
} elseif ($onlineOrder) {
    $paymentMessage = in_array($onlineStatus, ['cancelled', 'canceled', 'timeouted', 'rejected'], true)
        ? 'čeká na další pokus o online platbu přes ' . $onlineProvider . '.'
        : 'čeká na potvrzení online platby přes ' . $onlineProvider . '.';
} else {
    $paymentMessage = 'čeká na úhradu. Zaplaťte bankovním převodem podle údajů níže.';
}
?>
<main class="wrap checkout-page checkout-complete" id="produkty">
  <div class="checkout-heading"><span class="checkout-eyebrow"><?= $testOrder ? 'Místní test' : ($gopayRefund !== '' ? 'Stav platby' : 'Objednávka přijata') ?></span><h1><?= $testOrder ? 'Testovací objednávka vytvořena' : ($gopayRefund !== '' ? 'Stav objednávky' : 'Děkujeme za objednávku') ?></h1><p>Objednávka<?= $orderNumber !== '' ? ' č. ' . $checkoutEscape($orderNumber) : '' ?> <?= $paymentMessage ?></p></div>
  <?php if (($paymentNotice ?? '') !== ''): ?><p class="checkout-alert" role="alert"><?= $checkoutEscape($paymentNotice) ?></p><?php endif; ?>
  <div class="checkout-columns<?= $paymentPaid ? ' checkout-columns-paid' : '' ?>">
    <?php if ($testOrder): ?><section class="checkout-panel"><h2>Jen pro testování</h2><p>Objednávku najdete v administraci mezi testovacími objednávkami. K placení ani expedici neslouží.</p><?php if ($orderUrl !== ''): ?><label class="checkout-return-link">Odkaz na objednávku <input type="text" readonly value="<?= $checkoutEscape($orderUrl) ?>"></label><?php endif; ?><a class="checkout-back" href="<?= $checkoutEscape($siteRoot . $language) ?>#produkty">← Zpět do obchodu</a></section>
    <?php elseif ($gopayRefund !== ''): ?>
    <section class="checkout-panel" aria-labelledby="checkout-refund-title">
      <h2 id="checkout-refund-title"><?= $gopayRefund === 'refunded' ? 'Platba vrácena' : 'Částečné vrácení platby' ?></h2>
      <p><?= $gopayRefund === 'refunded' ? 'GoPay eviduje vrácení původní platby.' : 'GoPay eviduje vrácení části původní platby.' ?> Souhrn níže ukazuje původní cenu objednávky. Další platbu neprovádějte bez domluvy s obchodem.</p>
      <?php if (($invoiceUrl ?? '') !== ''): ?><p><a class="checkout-back" href="<?= $checkoutEscape($invoiceUrl) ?>">Zobrazit původní fakturu</a></p><?php endif; ?>
      <a class="checkout-back" href="<?= $checkoutEscape($siteRoot . $language) ?>#produkty">← Zpět do obchodu</a>
    </section>
    <?php elseif ($gopayOrder && $gopayGatewayUrl !== '' && !$paymentPaid): ?>
    <section class="checkout-panel checkout-bank" aria-labelledby="checkout-gopay-title">
      <h2 id="checkout-gopay-title">Pokračovat k platbě GoPay</h2>
      <p>Objednávka je uložená a čeká na úhradu. Pokračujte na zabezpečenou platební bránu.</p>
      <p><strong>Částka k úhradě: <?= $checkoutMoney($paymentAmount) ?></strong></p>
      <form id="checkout-gopay-handoff" method="post" action="<?= $checkoutEscape($gopayGatewayUrl) ?>">
        <button type="submit" class="checkout-primary">Přejít na platební bránu GoPay</button>
      </form>
      <p class="checkout-fineprint">Pokud se brána neotevře automaticky, použijte tlačítko. Stav platby ověříme přímo u GoPay.</p>
      <a class="checkout-back" href="<?= $checkoutEscape($orderUrl) ?>">Zpět na objednávku</a>
    </section>
    <script>document.getElementById('checkout-gopay-handoff').submit();</script>
    <?php elseif ($onlineOrder && !$paymentPaid): ?>
    <section class="checkout-panel checkout-bank" aria-labelledby="checkout-online-title">
      <h2 id="checkout-online-title">Online platba <?= $checkoutEscape($onlineProvider) ?></h2>
      <p><?= in_array($onlineStatus, ['cancelled', 'canceled', 'timeouted'], true) ? 'Platba byla zrušena nebo nebyla dokončena. Objednávku můžete zaplatit znovu.' : ($onlineStatus === 'rejected' ? 'Platební brána nepřijala poslední pokus o platbu. Můžete to zkusit znovu.' : ($onlineUncertain ? 'Stav posledního pokusu o platbu se ověřuje. Kontaktujte obchod, pokud se stav brzy neaktualizuje.' : 'Objednávka je uložená. Pokud jste platbu nedokončili, můžete se k ní vrátit.')) ?></p>
      <p><strong>Částka k úhradě: <?= $checkoutMoney($paymentAmount) ?></strong></p>
      <?php if ($onlineAvailable && !$onlineUncertain): ?><form method="post" action="<?= $checkoutEscape($orderUrl) ?>"><input type="hidden" name="csrf" value="<?= $checkoutEscape($cartToken) ?>"><input type="hidden" name="action" value="<?= $checkoutEscape($onlineAction) ?>"><button type="submit" class="checkout-primary">Přejít k online platbě</button></form><?php elseif (!$onlineAvailable): ?><p class="checkout-fineprint">Online platba je dočasně nedostupná. Kontaktujte prosím obchod a neprovádějte další platbu bez ověření objednávky.</p><?php endif; ?>
      <?php if ($orderUrl !== ''): ?><p class="checkout-fineprint">Uložte si odkaz na objednávku pro pozdější kontrolu stavu. Potvrzení o přijetí objednávky může přijít také e-mailem.</p><label class="checkout-return-link">Odkaz na objednávku <input type="text" readonly value="<?= $checkoutEscape($orderUrl) ?>"></label><?php endif; ?>
      <a class="checkout-back" href="<?= $checkoutEscape($siteRoot . $language) ?>#produkty">← Zpět do obchodu</a>
    </section>
    <?php elseif (!$paymentPaid): ?>
    <section class="checkout-panel checkout-bank" aria-labelledby="checkout-bank-title">
      <h2 id="checkout-bank-title">Údaje pro platbu</h2>
      <dl>
        <div><dt>Částka k úhradě</dt><dd class="checkout-bank-total"><?= $checkoutMoney($paymentAmount) ?></dd></div>
        <div><dt>Číslo účtu</dt><dd><span class="checkout-selectable"><?= $checkoutEscape($bankPayment['account_display'] ?? '') ?></span></dd></div>
        <?php if (($bankPayment['iban'] ?? '') !== ''): ?><div><dt>IBAN</dt><dd><span class="checkout-selectable"><?= $checkoutEscape($bankPayment['iban']) ?></span></dd></div><?php endif; ?>
        <div><dt>Variabilní symbol</dt><dd><span class="checkout-selectable"><?= $checkoutEscape($bankPayment['variable_symbol'] ?? '') ?></span></dd></div>
        <?php if (!empty($bankPayment['due_at'])): ?><div><dt>Splatnost (UTC)</dt><dd><?= $checkoutEscape($bankPayment['due_at']) ?></dd></div><?php endif; ?>
      </dl>
      <p class="checkout-fineprint">Uveďte variabilní symbol, abychom mohli platbu přiřadit k objednávce. Objednávku začneme vyřizovat po přijetí platby.</p>
      <?php if ($orderUrl !== ''): ?><p class="checkout-fineprint">Uložte si odkaz na tuto stránku, abyste se mohli k platebním údajům vrátit. Potvrzení objednávky odesíláme e-mailem, pokud je poštovní služba obchodu nastavena.</p><label class="checkout-return-link">Odkaz na objednávku <input type="text" readonly value="<?= $checkoutEscape($orderUrl) ?>"></label><?php endif; ?>
      <a class="checkout-back" href="<?= $checkoutEscape($siteRoot . $language) ?>#produkty">← Zpět do obchodu</a>
    </section>
    <aside class="checkout-panel checkout-qr" aria-labelledby="checkout-qr-title"><h2 id="checkout-qr-title">Naskenovat QR platbu</h2>
      <?php if ($qrReady): ?><div class="checkout-qr-canvas" data-qr-payload="<?= $checkoutEscape($qrPayload) ?>"></div><noscript><p>QR kód vyžaduje JavaScript. Platební údaje vlevo lze použít i bez něj.</p></noscript><p class="checkout-fineprint">Zkontrolujte údaje v bankovní aplikaci před potvrzením platby.</p>
      <?php else: ?><p>QR platba není dostupná. Použijte údaje pro ruční převod.</p><?php endif; ?>
    </aside>
    <?php else: ?><section class="checkout-panel"><h2>Platba přijata</h2><p>Za tuto objednávku už neplaťte znovu.</p><?php if (($invoiceUrl ?? '') !== ''): ?><p><a class="checkout-back" href="<?= $checkoutEscape($invoiceUrl) ?>">Zobrazit fakturu a uložit ji jako PDF</a></p><?php endif; ?><a class="checkout-back" href="<?= $checkoutEscape($siteRoot . $language) ?>#produkty">← Zpět do obchodu</a></section><?php endif; ?>
  </div>
  <?php if (isset($order['subtotal_czk'], $order['shipping_czk'], $order['total_czk'])): ?>
  <section class="checkout-panel checkout-receipt"><h2>Souhrn nákupu</h2><dl>
    <div><dt>Produkty</dt><dd><?= $checkoutMoney((int) $order['subtotal_czk']) ?></dd></div>
    <div><dt><?= $checkoutEscape($order['shipping']['label'] ?? 'Doprava') ?></dt><dd><?= $checkoutMoney((int) $order['shipping_czk']) ?></dd></div>
    <div><dt><?= $gopayRefund !== '' ? 'Původní cena objednávky' : 'Celkem' ?></dt><dd><strong><?= $checkoutMoney((int) $order['total_czk']) ?></strong></dd></div>
  </dl><?php if (!empty($order['shipping']['pickup_point'])): ?><p>Výdejní místo: <?= $checkoutEscape($order['shipping']['pickup_point']) ?>, <?= $checkoutEscape($order['shipping']['pickup_address'] ?? '') ?></p><?php endif; ?></section>
  <?php endif; ?>
</main>
