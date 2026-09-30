<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
?>
<main class="wrap checkout-page" id="produkty">
  <div class="checkout-heading"><span class="checkout-eyebrow">Vaše objednávka</span><h1>Platba</h1><p>Vyberte způsob úhrady objednávky.</p></div>
  <?php require __DIR__ . '/steps.php'; ?>
  <?php if ($error !== ''): ?><p class="checkout-alert" role="alert"><?= $checkoutEscape($error) ?></p><?php endif; ?>
  <div class="checkout-columns">
    <section class="checkout-panel checkout-payment" aria-labelledby="checkout-payment-title">
      <h2 id="checkout-payment-title">Způsob platby</h2>
      <?php if (($testCheckout ?? false)): ?>
        <div class="checkout-choice checkout-payment-choice"><span class="checkout-payment-mark" aria-hidden="true">✓</span><span><strong>Testovací objednávka</strong><small>Záznam vznikne jen v místní databázi. Nevzniknou platební údaje ani QR kód.</small></span><span class="checkout-selected">Vybráno</span></div>
        <div class="checkout-form-actions"><a class="checkout-back" href="<?= $checkoutEscape($checkoutUrl . '?step=shipping') ?>">← Zpět k dopravě</a><?php if (($paymentStepReady ?? false)): ?><a class="checkout-primary" href="<?= $checkoutEscape($checkoutUrl . '?step=review') ?>">Zkontrolovat objednávku <span aria-hidden="true">→</span></a><?php endif; ?></div>
      <?php else: ?>
        <form method="post" action="<?= $checkoutEscape($checkoutUrl) ?>">
          <input type="hidden" name="csrf" value="<?= $checkoutEscape($cartToken) ?>"><input type="hidden" name="action" value="payment">
          <?php if (($bankConfigured ?? false)): ?><label class="checkout-choice"><input type="radio" name="payment_method" value="bank_transfer" <?= ($paymentMethod ?? '') === 'bank_transfer' ? 'checked' : '' ?> required><span><strong>Bankovní převod</strong><small>Po objednání dostanete číslo účtu, variabilní symbol a QR kód. Platbu potvrdíme po připsání na účet.</small></span></label><?php endif; ?>
          <?php if (($comgateConfigured ?? false)): ?><label class="checkout-choice"><input type="radio" name="payment_method" value="comgate" <?= ($paymentMethod ?? '') === 'comgate' ? 'checked' : '' ?> required><span><strong>Online platba Comgate</strong><small>Po objednání vás přesměrujeme do zabezpečené platební brány. O zaplacení rozhodne potvrzení od Comgate.</small></span></label><?php endif; ?>
          <?php if (($gopayConfigured ?? false)): ?><label class="checkout-choice"><input type="radio" name="payment_method" value="gopay" <?= ($paymentMethod ?? '') === 'gopay' ? 'checked' : '' ?> required><span><strong>Online platba GoPay</strong><small>Po objednání vás přesměrujeme na platební bránu GoPay, kde zvolíte dostupný způsob platby. Objednávku označíme jako zaplacenou až po ověření u GoPay.</small></span></label><?php endif; ?>
          <?php if (($gopayConfigured ?? false)): ?>
            <div class="checkout-gopay-brands">
              <a href="https://www.gopay.cz/" target="_blank" rel="noopener noreferrer" aria-label="GoPay – informace o platební bráně"><img src="<?= $siteRoot ?>assets/gopay-logo.png" alt="GoPay" width="100" height="35"></a>
              <div class="checkout-gopay-cards" aria-label="Loga platebních karet a zabezpečení">
                <img src="<?= $siteRoot ?>assets/gopay-visa.png" alt="Visa" width="67" height="22">
                <img src="<?= $siteRoot ?>assets/gopay-visa-electron.png" alt="Visa Electron" width="67" height="43">
                <img src="<?= $siteRoot ?>assets/gopay-mastercard.png" alt="Mastercard" width="80" height="50">
                <img src="<?= $siteRoot ?>assets/gopay-mastercard-electronic.png" alt="Mastercard Electronic" width="67" height="43">
                <img src="<?= $siteRoot ?>assets/gopay-maestro.png" alt="Maestro" width="67" height="43">
                <img src="<?= $siteRoot ?>assets/gopay-verified-by-visa.png" alt="Verified by Visa" width="67" height="30">
                <img src="<?= $siteRoot ?>assets/gopay-mastercard-securecode.png" alt="Mastercard SecureCode" width="86" height="40">
              </div>
            </div>
          <?php endif; ?>
          <?php if (($setupNotice ?? '') !== ''): ?><p class="checkout-alert" role="alert">Databáze objednávek ještě není připravená. Objednávku teď nelze odeslat.</p><?php elseif (!($bankConfigured ?? false) && !($comgateConfigured ?? false) && !($gopayConfigured ?? false)): ?><p class="checkout-alert" role="alert">Není nastavený žádný způsob platby. V administraci nastavte bankovní převod, Comgate nebo GoPay.</p><?php elseif (!($paymentStepReady ?? false) || $summaryShippingPrice === null): ?><p class="checkout-alert" role="alert">Zkontrolujte košík a zvolenou dopravu.</p><?php endif; ?>
          <div class="checkout-form-actions"><a class="checkout-back" href="<?= $checkoutEscape($checkoutUrl . '?step=shipping') ?>">← Zpět k dopravě</a><?php if (($paymentStepReady ?? false) && $summaryShippingPrice !== null): ?><button type="submit" class="checkout-primary">Zkontrolovat objednávku <span aria-hidden="true">→</span></button><?php endif; ?></div>
        </form>
      <?php endif; ?>
    </section>
    <?php require __DIR__ . '/summary.php'; ?>
  </div>
</main>
