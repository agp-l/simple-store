<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
$orderNumber = (string) ($order['order_number'] ?? $order['number'] ?? '');
$paymentAmount = (int) ($bankPayment['amount_czk'] ?? 0);
$qrPayload = (string) ($bankPayment['spayd'] ?? '');
$qrReady = str_starts_with($qrPayload, 'SPD*1.0*') && strlen($qrPayload) <= 512 && !preg_match('/[\x00-\x1F\x7F]/', $qrPayload);
$paymentPaid = ($order['payment_status'] ?? '') === 'paid';
?>
<main class="wrap checkout-page checkout-complete" id="produkty">
  <div class="checkout-heading"><span class="checkout-eyebrow">Objednávka přijata</span><h1>Děkujeme za objednávku</h1><p>Objednávka<?= $orderNumber !== '' ? ' č. ' . $checkoutEscape($orderNumber) : '' ?> <?= $paymentPaid ? 'je zaplacená. Děkujeme.' : 'čeká na úhradu. Zaplaťte bankovním převodem podle údajů níže.' ?></p></div>
  <div class="checkout-columns<?= $paymentPaid ? ' checkout-columns-paid' : '' ?>">
    <?php if (!$paymentPaid): ?>
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
      <?php if ($orderUrl !== ''): ?><p class="checkout-fineprint">Uložte si odkaz na tuto stránku, abyste se mohli k platebním údajům vrátit. Potvrzení e-mailem zatím neposíláme.</p><label class="checkout-return-link">Odkaz na objednávku <input type="text" readonly value="<?= $checkoutEscape($orderUrl) ?>"></label><?php endif; ?>
      <a class="checkout-back" href="<?= $checkoutEscape($siteRoot . $language) ?>#produkty">← Zpět do obchodu</a>
    </section>
    <aside class="checkout-panel checkout-qr" aria-labelledby="checkout-qr-title"><h2 id="checkout-qr-title">Naskenovat QR platbu</h2>
      <?php if ($qrReady): ?><div class="checkout-qr-canvas" data-qr-payload="<?= $checkoutEscape($qrPayload) ?>"></div><noscript><p>QR kód vyžaduje JavaScript. Platební údaje vlevo lze použít i bez něj.</p></noscript><p class="checkout-fineprint">Zkontrolujte údaje v bankovní aplikaci před potvrzením platby.</p>
      <?php else: ?><p>QR platba není dostupná. Použijte údaje pro ruční převod.</p><?php endif; ?>
    </aside>
    <?php else: ?><section class="checkout-panel"><h2>Platba přijata</h2><p>Za tuto objednávku už neplaťte znovu.</p><a class="checkout-back" href="<?= $checkoutEscape($siteRoot . $language) ?>#produkty">← Zpět do obchodu</a></section><?php endif; ?>
  </div>
</main>
