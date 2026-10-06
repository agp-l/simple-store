      <section class="panel-panel">
        <div class="panel-panel-head"><h2>Doručení a kontakt</h2>
          <div class="panel-order-head-actions" aria-label="Kopírovat údaje pro expedici">
            <button class="panel-order-copy" type="button" data-order-copy="<?= $escape($deliveryCopy) ?>">Kopírovat adresu</button>
            <button class="panel-order-copy" type="button" data-order-copy="<?= $escape($carrierCopy) ?>">Kopírovat pro dopravce</button>
          </div>
        </div>
  <p class="panel-order-copy-feedback" role="status" aria-live="polite"></p>
        <?php if (($_GET['tracking_saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Sledovací údaje byly uloženy. Pokud už byla objednávka předána dopravci a automatické zprávy jsou zapnuté, zpráva zákazníkovi se připravila podle nastavení e-mailů.</p><?php endif; ?>
        <dl class="panel-order-facts">
          <div><dt>Aktuální doprava</dt><dd><strong><?= $escape($shipping['label'] ?? $shipping['method'] ?? 'Neuvedeno') ?></strong></dd></div>
          <?php if (!empty($order['dispatch_shipping_changed'])): ?><div><dt>Původně objednáno</dt><dd><?= $escape($order['shipping_ordered']['label'] ?? $order['shipping_ordered']['method'] ?? 'Neuvedeno') ?> · účtováno <?= $orderMoney($order['shipping_czk'] ?? 0) ?></dd></div><?php endif; ?>
          <div><dt>Expeduje</dt><dd><?= ($order['fulfillment_source'] ?? 'own') === 'external' ? 'Externí dodavatel' : 'Obchod' ?><?php if (!empty($order['fulfillment_note'])): ?><br><?= $escape($order['fulfillment_note']) ?><?php endif; ?></dd></div>
          <div><dt>Příjemce</dt><dd><?= $escape($shipping['recipient'] ?? $shipping['name'] ?? 'Neuvedeno') ?></dd></div>
          <div><dt>E-mail</dt><dd><?= $escape($order['customer_email'] ?? $shipping['email'] ?? 'Neuvedeno') ?></dd></div>
          <div><dt>Telefon</dt><dd><?= $escape($shipping['phone'] ?? 'Neuvedeno') ?></dd></div>
          <?php if (!empty($shipping['pickup_point'])): ?><div><dt>Výdejní místo</dt><dd><?= $escape($shipping['pickup_point']) ?><br><?= $escape($shipping['pickup_address'] ?? '') ?><?php if (!empty($shipping['pickup_code'])): ?><br>Kód: <?= $escape($shipping['pickup_code']) ?><?php endif; ?><?php if (($shipping['method'] ?? '') === 'balikovna_pickup' && !empty($shipping['pickup_postal_code'])): ?><br>PSČ Balíkovny: <?= $escape($shipping['pickup_postal_code']) ?><?php endif; ?></dd></div>
          <?php else: ?><div><dt>Adresa</dt><dd><?= $escape($shipping['street'] ?? '') ?><br><?= $escape(trim((string) ($shipping['postal_code'] ?? '') . ' ' . (string) ($shipping['city'] ?? ''))) ?><br><?= $escape($shipping['country'] ?? 'CZ') ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($orderTrackingReady): ?>
          <details class="panel-order-accordion"><summary>Sledování zásilky a externí dodavatel</summary>
            <p class="panel-help">U Zásilkovny se použije odkaz z API, u GLS a Balíkovny registrované číslo. Číslo nebo HTTPS odkaz můžeš doplnit i ručně. Po předání dopravci se nové údaje pošlou zákazníkovi; stejné uložení zprávu neopakuje.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save-order-tracking"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
              <label>Číslo zásilky<input name="tracking_number" maxlength="100" value="<?= $escape($orderManualTracking['number']) ?>" placeholder="Číslo přidělené dopravcem"></label>
              <label>Veřejný odkaz pro sledování<input type="url" name="tracking_url" maxlength="1000" value="<?= $escape($orderManualTracking['url']) ?>" placeholder="https://..."></label>
              <button class="panel-button" type="submit">Uložit sledování</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($shippingChangeReady && $shippingChangeOptions !== []): ?>
          <details class="panel-order-accordion" <?= $shippingEntered !== [] ? 'open' : '' ?>>
            <summary>Změnit skutečného dopravce</summary>
            <p class="panel-help">Změna se týká expedice. Původní cena dopravy, objednávka a vystavený doklad zůstávají stejné.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="change-order-shipping"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="expected_method" value="<?= $escape($shipping['method'] ?? '') ?>">
              <label>Expedovat přes <select name="shipping_method" required>
                <?php foreach ($shippingChangeOptions as $methodCode => $methodLabel): ?><option value="<?= $escape($methodCode) ?>" <?= $methodCode === $shippingFormValue('shipping_method') ? 'selected' : '' ?>><?= $escape($methodLabel) ?></option><?php endforeach; ?>
              </select></label>
              <?php if ($shippingChangeNeedsAddress): ?>
                <p class="panel-help">Objednávka obsahuje jen výdejní místo. Pro doručení domů zadej úplnou adresu, kterou jsi ověřil/a u zákazníka.</p>
                <label>Ulice a číslo domu <input name="shipping_street" value="<?= $escape($shippingFormValue('shipping_street')) ?>" maxlength="190" required autocomplete="street-address"></label>
                <label>Město <input name="shipping_city" value="<?= $escape($shippingFormValue('shipping_city')) ?>" maxlength="120" required autocomplete="address-level2"></label>
                <label>PSČ <input name="shipping_postal_code" value="<?= $escape($shippingFormValue('shipping_postal_code')) ?>" maxlength="20" required autocomplete="postal-code"></label>
                <label class="panel-check"><input type="checkbox" name="address_confirmed" value="1" <?= $shippingFormValue('address_confirmed') === '1' ? 'checked' : '' ?> required> Ověřil/a jsem úplnou adresu příjemce pro doručení domů.</label>
              <?php endif; ?>
              <label>Důvod změny <input name="reason" value="<?= $escape($shippingFormValue('reason')) ?>" minlength="8" maxlength="190" required placeholder="Například přesměrování na jiného dopravce"></label>
              <?php if ($carrierShipment !== null && ($carrierShipment['status'] ?? '') === 'draft'): ?><label class="panel-check"><input type="checkbox" name="draft_not_submitted" value="1" <?= $shippingFormValue('draft_not_submitted') === '1' ? 'checked' : '' ?> required> Potvrzuji, že podklady dosud nebyly importovány k dopravci; uložený koncept se smaže.</label><?php endif; ?>
              <button class="panel-button" type="submit">Uložit dopravce pro expedici</button>
            </form>
          </details>
        <?php endif; ?>
        <?php require __DIR__ . "/packeta.php"; ?>
        <?php require __DIR__ . "/carrier.php"; ?>
      </section>
