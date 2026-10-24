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
        <?php foreach ($checkoutItems as $item): ?>
          <li>
            <div class="checkout-review-item"><strong><?= $checkoutEscape($item['name']) ?></strong>
              <small><?= (int) $item['quantity'] ?> ks<?php foreach (($item['options'] ?? []) as $label => $value): ?> · <?= $checkoutEscape($label) ?>: <?= $checkoutEscape($value) ?><?php endforeach; ?></small>
              <?php if (($item['issue'] ?? '') !== ''): ?><small class="checkout-line-issue" role="alert"><?= $checkoutEscape($item['issue']) ?></small><?php endif; ?>
            </div>
            <strong><?= $item['line_total_czk'] === null ? 'Nedostupné' : $checkoutMoney((int) $item['line_total_czk']) ?></strong>
            <?php if (($item['sale_mode'] ?? 'own') === 'assisted'): ?><div class="checkout-review-assistance"><?php require __DIR__ . '/assisted-item.php'; ?></div><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul><?php require __DIR__ . '/assisted-delivery.php'; ?></section>
      <section class="checkout-panel"><div class="checkout-panel-title"><h2>Doprava a kontakt</h2><a href="<?= $checkoutEscape($checkoutUrl . '?step=shipping') ?>">Upravit údaje</a></div>
        <p><strong><?= $checkoutEscape($chosenShipping['label'] ?? '') ?></strong></p>
        <address><?= $checkoutEscape($delivery['name'] ?? '') ?><?php if (!empty($delivery['company'])): ?><br><?= $checkoutEscape($delivery['company']) ?><?php endif; ?><br><?php if (($chosenShipping['group'] ?? '') === 'pickup'): ?>Výdejní místo: <?= $checkoutEscape($delivery['pickup_point'] ?? '') ?><br><?= $checkoutEscape($delivery['pickup_address'] ?? '') ?><?php if (!empty($delivery['pickup_code'])): ?><br>Kód místa: <?= $checkoutEscape($delivery['pickup_code']) ?><?php endif; ?><?php else: ?><?= $checkoutEscape($delivery['street'] ?? '') ?><br><?= $checkoutEscape($delivery['postal_code'] ?? '') ?> <?= $checkoutEscape($delivery['city'] ?? '') ?><?php endif; ?><br>Česká republika</address>
        <p><?= $checkoutEscape($delivery['email'] ?? '') ?><br><?= $checkoutEscape($delivery['phone'] ?? '') ?></p>
      </section>
      <section class="checkout-panel"><div class="checkout-panel-title"><h2>Platba</h2><a href="<?= $checkoutEscape($checkoutUrl . '?step=payment') ?>">Změnit platbu</a></div><p><?= match ($paymentMethod ?? '') { 'comgate' => 'Online platba Comgate. Po potvrzení objednávky budete přesměrováni na platební bránu.', 'gopay' => 'Online platba GoPay. Po potvrzení objednávky budete přesměrováni na platební bránu.', 'btcpay' => 'Platba bitcoinem přes BTCPay Server. Po potvrzení objednávky otevřete platební stránku.', 'bank_transfer' => 'Bankovní převod. Platební údaje a QR kód se zobrazí po vytvoření objednávky.', default => 'Původně vybraný způsob platby již není dostupný. Vraťte se k výběru platby.' } ?></p></section>
    </div>
    <div class="checkout-sidebar">
      <?php require __DIR__ . '/summary.php'; ?>
      <?php if ($checkoutCanContinue && ($checkoutReady ?? false) && $summaryShippingPrice !== null): ?>
      <form class="checkout-place" method="post" action="<?= $checkoutEscape($checkoutUrl) ?>">
        <input type="hidden" name="csrf" value="<?= $checkoutEscape($cartToken) ?>"><input type="hidden" name="action" value="place">
        <input type="hidden" name="review_token" value="<?= $checkoutEscape($reviewToken ?? '') ?>">
        <?php if (($hasOwnSale ?? true) && ($termsUrl ?? '') !== ''): ?><label><input type="checkbox" name="terms" value="1" required> Souhlasím s <a href="<?= $checkoutEscape($termsUrl) ?>" target="_blank" rel="noopener">obchodními podmínkami pro zboží prodávané přímo obchodem</a>.</label><?php endif; ?>
        <?php if ($hasAssisted ?? false): ?>
          <label><input type="checkbox" name="assisted_terms" value="1" required> Souhlasím s <a href="<?= $checkoutEscape($assistedTermsUrl) ?>" target="_blank" rel="noopener">podmínkami asistovaného nákupu</a> a pověřuji vás nákupem označených položek mým jménem u uvedených prodávajících za zobrazených podmínek.</label>
          <label><input type="checkbox" name="early_start" value="1"> <?= $checkoutEscape(\SimpleStore\Checkout\AssistedPurchase::EARLY_REQUEST) ?></label>
          <p class="checkout-fineprint">Bez této žádosti nákup u partnera zahájíme až po uplynutí lhůty pro odstoupení. Smlouva o asistenci vzniká odesláním této objednávky.</p>
        <?php endif; ?>
        <button type="submit" class="checkout-primary">Objednat s povinností platby</button>
      </form>
      <?php else: ?><p class="checkout-alert" role="alert">Objednávku nyní nelze vytvořit. Vraťte se do košíku a zkontrolujte údaje.</p><?php endif; ?>
      <p class="checkout-fineprint"><?= match ($paymentMethod ?? '') { 'comgate' => 'Objednávka vznikne po potvrzení. Poté vás přesměrujeme k online platbě Comgate.', 'gopay' => 'Objednávka vznikne po potvrzení. Poté vás přesměrujeme k online platbě GoPay.', 'btcpay' => 'Objednávka vznikne po potvrzení. Poté otevřete BTCPay a zaplatíte bitcoinem.', default => 'Objednávka vznikne po potvrzení. K zaplacení pak použijete údaje na další stránce.' } ?></p>
    </div>
  </div>
</main>
