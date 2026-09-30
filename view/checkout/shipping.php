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
      <form class="checkout-panel checkout-form checkout-has-gls-map <?= ($pplWidgetKey ?? '') !== '' ? 'checkout-has-ppl-widget' : '' ?>" method="post" action="<?= $checkoutEscape($checkoutUrl) ?>">
        <input type="hidden" name="csrf" value="<?= $checkoutEscape($cartToken) ?>"><input type="hidden" name="action" value="delivery"><input type="hidden" name="country" value="CZ">
        <fieldset><legend>1. Výdejní místa a boxy</legend><p class="checkout-field-help">U Zásilkovny, GLS<?= ($pplWidgetKey ?? '') !== '' ? ' a PPL' : '' ?> vyberte místo v mapě; údaje se doplní automaticky. U ostatních dopravců zatím adresu opište z jejich mapy.</p>
          <?php foreach ($availableShippingOptions as $option): ?>
            <?php if ($option['group'] !== 'pickup') continue; ?>
            <div class="checkout-shipping-option"><label class="checkout-choice"><input type="radio" name="method" value="<?= $checkoutEscape($option['code']) ?>" <?= ($delivery['method'] ?? '') === $option['code'] ? 'checked' : '' ?> required><span><strong><?= $checkoutEscape($option['label']) ?></strong></span><strong><?= (int) $option['price_czk'] === 0 ? 'Zdarma' : $checkoutMoney((int) $option['price_czk']) ?></strong></label><?php if ($option['code'] === 'zasilkovna_pickup'): ?><button class="checkout-packeta-open" type="button" data-packeta-open>Vybrat výdejní místo Zásilkovny</button><?php elseif ($option['code'] === 'ppl_pickup' && ($pplWidgetKey ?? '') !== ''): ?><button class="checkout-packeta-open" type="button" data-ppl-open>Vybrat místo PPL v mapě</button><?php elseif ($option['code'] === 'gls_pickup'): ?><button class="checkout-packeta-open" type="button" data-gls-open>Vybrat místo GLS v mapě</button><?php elseif ($option['locator_url'] !== ''): ?><a href="<?= $checkoutEscape($option['locator_url']) ?>" target="_blank" rel="noopener noreferrer">Vybrat adresu výdejního místa ↗</a><?php endif; ?></div>
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
        <fieldset class="checkout-pickup-fields"><legend>4. Vybrané výdejní místo</legend>
          <div class="checkout-packeta-fields" data-packeta-key="<?= $checkoutEscape($packetaApiKey ?? '') ?>" data-packeta-options="<?= $checkoutEscape(json_encode($packetaOptions ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)) ?>">
            <input type="hidden" name="packeta_point_id" value="<?= ($delivery['method'] ?? '') === 'zasilkovna_pickup' ? $checkoutEscape($delivery['pickup_code'] ?? '') : '' ?>" data-packeta-id>
            <p class="checkout-packeta-selection" data-packeta-selection aria-live="polite"><?php if (($delivery['method'] ?? '') === 'zasilkovna_pickup' && !empty($delivery['pickup_code'])): ?><?= $checkoutEscape($delivery['pickup_point'] ?? '') ?> · <?= $checkoutEscape($delivery['pickup_address'] ?? '') ?> (ID <?= $checkoutEscape($delivery['pickup_code']) ?>)<?php else: ?>Místo zatím není vybrané. Použijte tlačítko u Zásilkovny.<?php endif; ?></p>
            <p class="checkout-field-help" data-packeta-status role="status"></p><noscript>Pro výběr výdejního místa Zásilkovny zapněte JavaScript.</noscript>
          </div>
          <?php if (($pplWidgetKey ?? '') !== ''): ?>
          <div class="checkout-ppl-fields">
            <ppl-access-point-widget api-key="<?= $checkoutEscape($pplWidgetKey) ?>" config='<?= $checkoutEscape(json_encode(['viewMode' => 'modal', 'defaultCountry' => 'CZ', 'allowedCountries' => ['CZ'], 'defaultLang' => 'cs', 'codRequired' => false], JSON_THROW_ON_ERROR)) ?>' data-ppl-widget></ppl-access-point-widget>
            <input type="hidden" name="ppl_point_code" value="<?= $checkoutEscape($pplSelection['code'] ?? '') ?>" data-ppl-code>
            <input type="hidden" name="ppl_point_name" value="<?= $checkoutEscape($pplSelection['name'] ?? '') ?>" data-ppl-name>
            <input type="hidden" name="ppl_point_address" value="<?= $checkoutEscape($pplSelection['address'] ?? '') ?>" data-ppl-address>
            <input type="hidden" name="ppl_point_country" value="<?= $checkoutEscape($pplSelection['country'] ?? '') ?>" data-ppl-country>
            <p class="checkout-packeta-selection" data-ppl-selection aria-live="polite"><?php if (($pplSelection['code'] ?? '') !== ''): ?><?= $checkoutEscape($pplSelection['name'] ?? '') ?> · <?= $checkoutEscape($pplSelection['address'] ?? '') ?> (<?= $checkoutEscape($pplSelection['code']) ?>)<?php else: ?>Místo zatím není vybrané. Použijte tlačítko u PPL.<?php endif; ?></p>
            <p class="checkout-field-help" data-ppl-status role="status"></p><noscript>Pro výběr místa PPL zapněte JavaScript.</noscript>
          </div>
          <?php endif; ?>
          <div class="checkout-gls-fields">
            <input type="hidden" name="gls_point_id" value="<?= $checkoutEscape($glsSelection['id'] ?? '') ?>" data-gls-id>
            <input type="hidden" name="gls_point_name" value="<?= $checkoutEscape($glsSelection['name'] ?? '') ?>" data-gls-name>
            <input type="hidden" name="gls_point_address" value="<?= $checkoutEscape($glsSelection['address'] ?? '') ?>" data-gls-address>
            <input type="hidden" name="gls_point_country" value="<?= $checkoutEscape($glsSelection['country'] ?? '') ?>" data-gls-country>
            <p class="checkout-packeta-selection" data-gls-selection aria-live="polite"><?php if (($glsSelection['id'] ?? '') !== ''): ?><?= $checkoutEscape($glsSelection['name'] ?? '') ?> · <?= $checkoutEscape($glsSelection['address'] ?? '') ?> (ID <?= $checkoutEscape($glsSelection['id']) ?>)<?php else: ?>Místo zatím není vybrané. Použijte tlačítko u GLS.<?php endif; ?></p>
            <p class="checkout-field-help" data-gls-status role="status"></p><noscript>Pro výběr místa GLS zapněte JavaScript.</noscript>
          </div>
          <div class="checkout-manual-pickup"><p class="checkout-field-help">Na mapě dopravce vyhledejte místo a opište jeho název a přesnou adresu.</p>
          <?php $manualSelection = !in_array(($delivery['method'] ?? ''), ['zasilkovna_pickup', 'gls_pickup'], true) && !(($delivery['method'] ?? '') === 'ppl_pickup' && ($pplWidgetKey ?? '') !== ''); ?>
          <div class="checkout-fields"><label class="checkout-span">Název výdejního místa nebo boxu <input type="text" name="pickup_point" value="<?= $manualSelection ? $checkoutEscape($delivery['pickup_point'] ?? '') : '' ?>" maxlength="190" placeholder="Například ParcelShop Hlavní nádraží"></label>
            <label class="checkout-span">Adresa výdejního místa (ulice, město, PSČ) <input type="text" name="pickup_address" value="<?= $manualSelection ? $checkoutEscape($delivery['pickup_address'] ?? '') : '' ?>" maxlength="190"></label>
            <label>Kód místa (pokud je uveden) <input type="text" name="pickup_code" value="<?= $manualSelection ? $checkoutEscape($delivery['pickup_code'] ?? '') : '' ?>" maxlength="80"></label></div></div>
        </fieldset>
        <div class="checkout-form-actions"><a class="checkout-back" href="<?= $checkoutEscape($cartUrl) ?>">← Zpět do košíku</a><button class="checkout-primary" type="submit">Pokračovat k platbě <span aria-hidden="true">→</span></button></div>
      </form>
      <dialog class="checkout-gls-dialog" data-gls-dialog aria-label="Výběr výdejního místa GLS">
        <div class="checkout-gls-dialog-head"><strong>Vyberte místo GLS</strong><button type="button" data-gls-close aria-label="Zavřít mapu">Zavřít ×</button></div>
        <p class="checkout-gls-dialog-status" data-gls-dialog-status role="status"></p>
        <iframe title="Mapa výdejních míst GLS" data-gls-map data-src="<?= $checkoutEscape(\SimpleStore\Checkout\GlsPickupPoint::MAP_URL) ?>" referrerpolicy="strict-origin-when-cross-origin"></iframe>
      </dialog>
      <?php require __DIR__ . '/summary.php'; ?>
    </div>
  <?php endif; ?>
</main>
