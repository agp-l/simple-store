<?php
declare(strict_types=1);
?>
<aside class="checkout-summary" aria-labelledby="checkout-summary-title">
  <h2 id="checkout-summary-title">Souhrn objednávky</h2>
  <p class="checkout-summary-count"><?= (int) ($checkout['count'] ?? 0) ?> ks v košíku</p>
  <dl>
    <div><dt>Produkty</dt><dd><?= $checkoutSubtotal === null ? 'Nelze určit' : $checkoutMoney($checkoutSubtotal) ?></dd></div>
    <div><dt>Doprava<?= $chosenShipping === null ? '' : ': ' . $checkoutEscape($chosenShipping['label']) ?></dt><dd><?= $summaryShippingPrice === null ? 'Vyberte dopravu' : ($summaryShippingPrice === 0 ? 'Zdarma' : $checkoutMoney((int) $summaryShippingPrice)) ?></dd></div>
    <?php if ($summaryShippingPrice !== null && $checkoutSubtotal !== null): ?><div class="checkout-summary-total"><dt>Celkem k úhradě</dt><dd><?= $checkoutMoney($checkoutSubtotal + (int) $summaryShippingPrice) ?></dd></div><?php endif; ?>
  </dl>
  <?php if ($checkoutSubtotal === null): ?><p class="checkout-fineprint">Nejprve odstraňte nedostupné položky. Cena se potom přepočítá.</p><?php elseif ($summaryShippingPrice === null): ?><p class="checkout-fineprint">Celkovou cenu uvidíte po výběru dostupné dopravy.</p><?php endif; ?>
</aside>
