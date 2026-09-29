<?php
declare(strict_types=1);
require __DIR__ . '/common.php';
?>
<main class="wrap checkout-page" id="produkty">
  <div class="checkout-heading"><span class="checkout-eyebrow">Vaše objednávka</span><h1>Doprava a kontaktní údaje</h1><p>Kam máme objednávku poslat a jak vás zastihneme?</p></div>
  <?php require __DIR__ . '/steps.php'; ?>
  <aside class="checkout-account">
    <?php if (!empty($customerProfile['email'])): ?>Přihlášeno jako <strong><?= $checkoutEscape($customerProfile['email']) ?></strong>. <a href="<?= $checkoutEscape($basePath . 'account.php') ?>">Můj účet</a>
    <?php else: ?>Máte účet? <a href="<?= $checkoutEscape($basePath . 'account.php?checkout=1') ?>">Přihlásit se</a> · <a href="<?= $checkoutEscape($basePath . 'account.php?mode=register&checkout=1') ?>">Zaregistrovat se</a>. Košík po přihlášení zůstane zachovaný.
    <?php endif; ?>
  </aside>
  <?php if ($error !== ''): ?><p class="checkout-alert" role="alert"><?= $checkoutEscape($error) ?></p><?php endif; ?>
  <?php if (!($shippingConfigured ?? false) || $availableShippingOptions === []): ?>
    <p class="checkout-alert" role="alert">Doprava zatím není nastavená. Objednávku teď nelze dokončit.</p><p><a class="checkout-back" href="<?= $checkoutEscape($cartUrl) ?>">← Zpět do košíku</a></p>
  <?php else: ?>
    <?php if (!empty($customerAddresses)): ?><div class="checkout-saved-addresses"><strong>Použít uloženou adresu</strong><div>
      <?php foreach ($customerAddresses as $savedAddress): ?><?php if (($savedAddress['country'] ?? '') !== 'CZ') continue; ?>
        <a href="<?= $checkoutEscape($checkoutUrl . '?step=shipping&address=' . (int) $savedAddress['id']) ?>"><?= $checkoutEscape($savedAddress['label']) ?> · <?= $checkoutEscape($savedAddress['street']) ?>, <?= $checkoutEscape($savedAddress['city']) ?></a>
      <?php endforeach; ?>
    </div></div><?php endif; ?>
    <div class="checkout-columns">
      <form class="checkout-panel checkout-form" method="post" action="<?= $checkoutEscape($checkoutUrl) ?>">
        <input type="hidden" name="csrf" value="<?= $checkoutEscape($cartToken) ?>"><input type="hidden" name="action" value="delivery"><input type="hidden" name="country" value="CZ">
        <fieldset><legend>1. Výdejní místa a boxy</legend><p class="checkout-field-help">Zvolte dopravce. Pro vyhledání bodu se otevře jeho mapa; název a adresu pak opište níže.</p>
          <?php foreach ($availableShippingOptions as $option): ?>
            <?php if ($option['group'] !== 'pickup') continue; ?>
            <div class="checkout-shipping-option"><label class="checkout-choice"><input type="radio" name="method" value="<?= $checkoutEscape($option['code']) ?>" <?= ($delivery['method'] ?? '') === $option['code'] ? 'checked' : '' ?> required><span><strong><?= $checkoutEscape($option['label']) ?></strong></span><strong><?= (int) $option['price_czk'] === 0 ? 'Zdarma' : $checkoutMoney((int) $option['price_czk']) ?></strong></label><?php if ($option['locator_url'] !== ''): ?><a href="<?= $checkoutEscape($option['locator_url']) ?>" target="_blank" rel="noopener noreferrer">Vybrat adresu výdejního místa ↗</a><?php endif; ?></div>
          <?php endforeach; ?>
        </fieldset>
        <fieldset><legend>2. Doručení na adresu</legend>
          <?php foreach ($availableShippingOptions as $option): ?>
            <?php if ($option['group'] !== 'home') continue; ?>
            <label class="checkout-choice"><input type="radio" name="method" value="<?= $checkoutEscape($option['code']) ?>" <?= ($delivery['method'] ?? '') === $option['code'] ? 'checked' : '' ?> required><span><strong><?= $checkoutEscape($option['label']) ?></strong></span><strong><?= (int) $option['price_czk'] === 0 ? 'Zdarma' : $checkoutMoney((int) $option['price_czk']) ?></strong></label>
          <?php endforeach; ?>
        </fieldset>
        <fieldset><legend>3. Kontaktní údaje</legend>
          <div class="checkout-fields"><label>Jméno a příjmení <input type="text" name="name" value="<?= $checkoutEscape($delivery['name'] ?? '') ?>" autocomplete="name" maxlength="120" required></label>
            <label>E-mail <input type="email" name="email" value="<?= $checkoutEscape($delivery['email'] ?? '') ?>" autocomplete="email" maxlength="254" required></label>
            <label>Telefon <input type="tel" name="phone" value="<?= $checkoutEscape($delivery['phone'] ?? '') ?>" autocomplete="tel" maxlength="40" required></label></div>
        </fieldset>
        <fieldset class="checkout-home-fields"><legend>4. Adresa doručení</legend><p class="checkout-field-help">Vyplňte při doručení na adresu.</p>
          <div class="checkout-fields"><label class="checkout-span">Ulice a číslo domu <input type="text" name="street" value="<?= $checkoutEscape($delivery['street'] ?? '') ?>" autocomplete="street-address" maxlength="190"></label>
            <label>Město <input type="text" name="city" value="<?= $checkoutEscape($delivery['city'] ?? '') ?>" autocomplete="address-level2" maxlength="120"></label>
            <label>PSČ <input type="text" name="postal_code" value="<?= $checkoutEscape($delivery['postal_code'] ?? '') ?>" autocomplete="postal-code" maxlength="20" inputmode="numeric"></label>
            <p class="checkout-country">Země doručení: <strong>Česká republika</strong></p></div>
        </fieldset>
        <fieldset class="checkout-pickup-fields"><legend>4. Vybrané výdejní místo</legend><p class="checkout-field-help">Na mapě dopravce vyhledejte místo a opište jeho název a přesnou adresu. Výběr se zatím z mapy automaticky nepřenáší.</p>
          <div class="checkout-fields"><label class="checkout-span">Název výdejního místa nebo boxu <input type="text" name="pickup_point" value="<?= $checkoutEscape($delivery['pickup_point'] ?? '') ?>" maxlength="190" placeholder="Například PPL Parcelbox Hlavní nádraží"></label>
            <label class="checkout-span">Adresa výdejního místa (ulice, město, PSČ) <input type="text" name="pickup_address" value="<?= $checkoutEscape($delivery['pickup_address'] ?? '') ?>" maxlength="190"></label>
            <label>Kód místa (pokud je uveden) <input type="text" name="pickup_code" value="<?= $checkoutEscape($delivery['pickup_code'] ?? '') ?>" maxlength="80"></label></div>
        </fieldset>
        <div class="checkout-form-actions"><a class="checkout-back" href="<?= $checkoutEscape($cartUrl) ?>">← Zpět do košíku</a><button class="checkout-primary" type="submit">Pokračovat k platbě <span aria-hidden="true">→</span></button></div>
      </form>
      <?php require __DIR__ . '/summary.php'; ?>
    </div>
  <?php endif; ?>
</main>
