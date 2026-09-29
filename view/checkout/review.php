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
        <address><?= $checkoutEscape($delivery['name'] ?? '') ?><br><?= $checkoutEscape($delivery['street'] ?? '') ?><br><?= $checkoutEscape($delivery['postal_code'] ?? '') ?> <?= $checkoutEscape($delivery['city'] ?? '') ?><br>Česká republika</address>
        <p><?= $checkoutEscape($delivery['email'] ?? '') ?><br><?= $checkoutEscape($delivery['phone'] ?? '') ?></p>
      </section>
      <section class="checkout-panel"><div class="checkout-panel-title"><h2>Platba</h2><a href="<?= $checkoutEscape($checkoutUrl . '?step=payment') ?>">Zpět k platbě</a></div><p>Bankovní převod. Platební údaje a QR kód se zobrazí po vytvoření objednávky.</p></section>
    </div>
    <div class="checkout-sidebar">
      <?php require __DIR__ . '/summary.php'; ?>
      <?php if ($checkoutCanContinue && ($checkoutReady ?? false) && ($termsUrl ?? '') !== '' && $summaryShippingPrice !== null): ?>
      <form class="checkout-place" method="post" action="<?= $checkoutEscape($checkoutUrl) ?>">
        <input type="hidden" name="csrf" value="<?= $checkoutEscape($cartToken) ?>"><input type="hidden" name="action" value="place">
        <label><input type="checkbox" name="terms" value="1" required> Souhlasím s <a href="<?= $checkoutEscape($termsUrl) ?>" target="_blank" rel="noopener">obchodními podmínkami</a>.</label>
        <button type="submit" class="checkout-primary">Objednat s povinností platby</button>
      </form>
      <?php else: ?><p class="checkout-alert" role="alert">Objednávku nyní nelze vytvořit. Vraťte se do košíku a zkontrolujte údaje.</p><?php endif; ?>
      <p class="checkout-fineprint">Objednávka vznikne až po potvrzení. K zaplacení pak použijete údaje na další stránce.</p>
    </div>
  </div>
</main>
