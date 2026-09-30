<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
?>
<main class="wrap checkout-page" id="produkty">
  <div class="checkout-heading"><span class="checkout-eyebrow">Vaše objednávka</span><h1>Kontrola objednávky</h1><p>Ještě jednou zkontrolujte produkty, doručení a konečnou cenu.</p></div>
  <?php require __DIR__ . '/steps.php'; ?>
  <?php if ($error !== ''): ?><p class="checkout-alert" role="alert"><?= $checkoutEscape($error) ?></p><?php endif; ?>
  <?php foreach ($checkoutOtherIssues as $issue): ?><p class="checkout-alert" role="alert"><?= $checkoutEscape($issue) ?></p><?php endforeach; ?>
  <div class="checkout-columns">
    <div class="checkout-review">
      <section class="checkout-panel"><div class="checkout-panel-title"><h2>Produkty</h2><a href="<?= $checkoutEscape($cartUrl) ?>">Upravit košík</a></div><ul class="checkout-review-lines">
        <?php foreach ($checkoutItems as $item): ?><li><span><strong><?= $checkoutEscape($item['name']) ?></strong><small><?= (int) $item['quantity'] ?> ks<?php foreach (($item['options'] ?? []) as $label => $value): ?> · <?= $checkoutEscape($label) ?>: <?= $checkoutEscape($value) ?><?php endforeach; ?></small><?php if (($item['issue'] ?? '') !== ''): ?><small class="checkout-line-issue" role="alert"><?= $checkoutEscape($item['issue']) ?></small><?php endif; ?></span><strong><?= $item['line_total_czk'] === null ? 'Nedostupné' : $checkoutMoney((int) $item['line_total_czk']) ?></strong></li><?php endforeach; ?>
      </ul></section>
      <section class="checkout-panel"><div class="checkout-panel-title"><h2>Doprava a kontakt</h2><a href="<?= $checkoutEscape($checkoutUrl . '?step=shipping') ?>">Upravit údaje</a></div>
        <p><strong><?= $checkoutEscape($chosenShipping['label'] ?? '') ?></strong></p>
        <address><?= $checkoutEscape($delivery['name'] ?? '') ?><br><?php if (($chosenShipping['group'] ?? '') === 'pickup'): ?>Výdejní místo: <?= $checkoutEscape($delivery['pickup_point'] ?? '') ?><br><?= $checkoutEscape($delivery['pickup_address'] ?? '') ?><?php if (!empty($delivery['pickup_code'])): ?><br>Kód místa: <?= $checkoutEscape($delivery['pickup_code']) ?><?php endif; ?><?php else: ?><?= $checkoutEscape($delivery['street'] ?? '') ?><br><?= $checkoutEscape($delivery['postal_code'] ?? '') ?> <?= $checkoutEscape($delivery['city'] ?? '') ?><?php endif; ?><br>Česká republika</address>
        <p><?= $checkoutEscape($delivery['email'] ?? '') ?><br><?= $checkoutEscape($delivery['phone'] ?? '') ?></p>
      </section>
      <section class="checkout-panel"><div class="checkout-panel-title"><h2>Platba</h2><a href="<?= $checkoutEscape($checkoutUrl . '?step=payment') ?>">Změnit platbu</a></div><p><?= ($testCheckout ?? false) ? 'Místní testovací objednávka. Platba se neprovádí.' : (($paymentMethod ?? '') === 'comgate' ? 'Online platba Comgate. Po potvrzení objednávky budete přesměrováni na platební bránu.' : 'Bankovní převod. Platební údaje a QR kód se zobrazí po vytvoření objednávky.') ?></p></section>
    </div>
    <div class="checkout-sidebar">
      <?php require __DIR__ . '/summary.php'; ?>
      <?php if ($checkoutCanContinue && ($checkoutReady ?? false) && $summaryShippingPrice !== null): ?>
      <form class="checkout-place" method="post" action="<?= $checkoutEscape($checkoutUrl) ?>">
        <input type="hidden" name="csrf" value="<?= $checkoutEscape($cartToken) ?>"><input type="hidden" name="action" value="place">
        <?php if (!($testCheckout ?? false) && ($termsUrl ?? '') !== ''): ?><label><input type="checkbox" name="terms" value="1" required> Souhlasím s <a href="<?= $checkoutEscape($termsUrl) ?>" target="_blank" rel="noopener">obchodními podmínkami</a>.</label><?php endif; ?>
        <button type="submit" class="checkout-primary"><?= ($testCheckout ?? false) ? 'Vytvořit testovací objednávku' : 'Objednat s povinností platby' ?></button>
      </form>
      <?php else: ?><p class="checkout-alert" role="alert">Objednávku nyní nelze vytvořit. Vraťte se do košíku a zkontrolujte údaje.</p><?php endif; ?>
      <p class="checkout-fineprint"><?= ($testCheckout ?? false) ? 'Testovací objednávku uvidíš v administraci. Neplať ji a nevyřizuj ji jako skutečný nákup.' : (($paymentMethod ?? '') === 'comgate' ? 'Objednávka vznikne po potvrzení. Poté vás přesměrujeme k online platbě Comgate.' : 'Objednávka vznikne po potvrzení. K zaplacení pak použijete údaje na další stránce.') ?></p>
    </div>
  </div>
</main>
