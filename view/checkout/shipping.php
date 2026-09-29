<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
?>
<main class="wrap checkout-page" id="produkty">
  <div class="checkout-heading"><span class="checkout-eyebrow">Vaše objednávka</span><h1>Doprava a kontaktní údaje</h1><p>Kam máme objednávku poslat a jak vás zastihneme?</p></div>
  <?php require __DIR__ . '/steps.php'; ?>
  <?php if ($error !== ''): ?><p class="checkout-alert" role="alert"><?= $checkoutEscape($error) ?></p><?php endif; ?>
  <?php if (!($shippingConfigured ?? false) || $availableShippingOptions === []): ?>
    <p class="checkout-alert" role="alert">Doprava zatím není nastavená. Objednávku teď nelze dokončit.</p><p><a class="checkout-back" href="<?= $checkoutEscape($cartUrl) ?>">← Zpět do košíku</a></p>
  <?php else: ?>
    <div class="checkout-columns">
      <form class="checkout-panel checkout-form" method="post" action="<?= $checkoutEscape($checkoutUrl) ?>">
        <input type="hidden" name="csrf" value="<?= $checkoutEscape($cartToken) ?>"><input type="hidden" name="action" value="delivery"><input type="hidden" name="country" value="CZ">
        <fieldset><legend>1. Způsob doručení</legend><p class="checkout-field-help">Zvolte dostupnou dopravu. Cena je uvedená u každé možnosti.</p>
          <?php foreach ($availableShippingOptions as $option): ?>
            <label class="checkout-choice"><input type="radio" name="method" value="<?= $checkoutEscape($option['code']) ?>" <?= ($delivery['method'] ?? '') === $option['code'] ? 'checked' : '' ?> required><span><strong><?= $checkoutEscape($option['label']) ?></strong><?php if (($option['description'] ?? '') !== ''): ?><small><?= $checkoutEscape($option['description']) ?></small><?php endif; ?></span><strong><?= (int) $option['price_czk'] === 0 ? 'Zdarma' : $checkoutMoney((int) $option['price_czk']) ?></strong></label>
          <?php endforeach; ?>
        </fieldset>
        <fieldset><legend>2. Kontaktní údaje</legend>
          <div class="checkout-fields"><label>Jméno a příjmení <input type="text" name="name" value="<?= $checkoutEscape($delivery['name'] ?? '') ?>" autocomplete="name" maxlength="120" required></label>
            <label>E-mail <input type="email" name="email" value="<?= $checkoutEscape($delivery['email'] ?? '') ?>" autocomplete="email" maxlength="254" required></label>
            <label>Telefon <input type="tel" name="phone" value="<?= $checkoutEscape($delivery['phone'] ?? '') ?>" autocomplete="tel" maxlength="40" required></label></div>
        </fieldset>
        <fieldset><legend>3. Doručovací adresa</legend>
          <div class="checkout-fields"><label class="checkout-span">Ulice a číslo domu <input type="text" name="street" value="<?= $checkoutEscape($delivery['street'] ?? '') ?>" autocomplete="street-address" maxlength="190" required></label>
            <label>Město <input type="text" name="city" value="<?= $checkoutEscape($delivery['city'] ?? '') ?>" autocomplete="address-level2" maxlength="120" required></label>
            <label>PSČ <input type="text" name="postal_code" value="<?= $checkoutEscape($delivery['postal_code'] ?? '') ?>" autocomplete="postal-code" maxlength="20" inputmode="numeric" required></label>
            <p class="checkout-country">Země doručení: <strong>Česká republika</strong></p></div>
        </fieldset>
        <div class="checkout-form-actions"><a class="checkout-back" href="<?= $checkoutEscape($cartUrl) ?>">← Zpět do košíku</a><button class="checkout-primary" type="submit">Pokračovat k platbě <span aria-hidden="true">→</span></button></div>
      </form>
      <?php require __DIR__ . '/summary.php'; ?>
    </div>
  <?php endif; ?>
</main>
