<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
?>
<main class="wrap checkout-page" id="produkty">
  <div class="checkout-heading"><span class="checkout-eyebrow">Vaše objednávka</span><h1>Platba</h1><p><?= ($testCheckout ?? false) ? 'Místní testovací objednávka nevyžaduje platbu.' : 'Zatím můžete zaplatit bankovním převodem. Platební údaje a QR kód dostanete po vytvoření objednávky.' ?></p></div>
  <?php require __DIR__ . '/steps.php'; ?>
  <?php if ($error !== ''): ?><p class="checkout-alert" role="alert"><?= $checkoutEscape($error) ?></p><?php endif; ?>
  <div class="checkout-columns">
    <section class="checkout-panel checkout-payment" aria-labelledby="checkout-payment-title">
      <h2 id="checkout-payment-title">Způsob platby</h2>
      <div class="checkout-choice checkout-payment-choice"><span class="checkout-payment-mark" aria-hidden="true">✓</span><span><strong><?= ($testCheckout ?? false) ? 'Testovací objednávka' : 'Bankovní převod' ?></strong><small><?= ($testCheckout ?? false) ? 'Záznam vznikne jen v místní databázi. Nevzniknou platební údaje ani QR kód.' : 'Po odeslání objednávky uvidíte číslo účtu, variabilní symbol a QR kód pro platbu.' ?></small></span><span class="checkout-selected">Vybráno</span></div>
      <?php if (($setupNotice ?? '') !== ''): ?><p class="checkout-alert" role="alert">Databáze objednávek ještě není připravená. Objednávku teď nelze odeslat.</p><?php elseif (!($testCheckout ?? false) && (!($bankConfigured ?? false) || ($termsUrl ?? '') === '')): ?><p class="checkout-alert" role="alert">Platba nebo obchodní podmínky zatím nejsou nastavené. Objednávku teď nelze odeslat.</p><?php elseif (!($checkoutReady ?? false) || $summaryShippingPrice === null): ?><p class="checkout-alert" role="alert">Zkontrolujte košík a zvolenou dopravu.</p><?php endif; ?>
      <div class="checkout-form-actions"><a class="checkout-back" href="<?= $checkoutEscape($checkoutUrl . '?step=shipping') ?>">← Zpět k dopravě</a><?php if (($checkoutReady ?? false) && $summaryShippingPrice !== null): ?><a class="checkout-primary" href="<?= $checkoutEscape($checkoutUrl . '?step=review') ?>">Zkontrolovat objednávku <span aria-hidden="true">→</span></a><?php endif; ?></div>
    </section>
    <?php require __DIR__ . '/summary.php'; ?>
  </div>
</main>
