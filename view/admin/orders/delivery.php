      <section class="panel-panel">
        <div class="panel-panel-head"><h2>Doručení a kontakt</h2>
          <div class="panel-order-head-actions" aria-label="Kopírovat údaje pro expedici">
            <button class="panel-action panel-order-copy" type="button" data-order-copy="<?= $escape($deliveryCopy) ?>"><?= \SimpleStore\Rendering\Icon::render('copy') ?> Kopírovat adresu</button>
            <button class="panel-action panel-order-copy" type="button" data-order-copy="<?= $escape($carrierCopy) ?>"><?= \SimpleStore\Rendering\Icon::render('copy') ?> Kopírovat pro dopravce</button>
          </div>
        </div>
  <p class="panel-order-copy-feedback" role="status" aria-live="polite"></p>
        <?php if (($_GET['tracking_saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Sledovací údaje byly uloženy. Pokud už byla objednávka předána dopravci a automatické zprávy jsou zapnuté, zpráva zákazníkovi se připravila podle nastavení e-mailů.</p><?php endif; ?>
        <?php
        $contactGroups = [
            ['title' => 'Zákazník', 'tone' => 'blue', 'fields' => [
                ['Příjemce', $recipientName, 'user'],
                ['E-mail', $contactEmail, 'mail'],
                ['Telefon', $contactPhone, 'phone'],
            ]],
            ['title' => 'Doručení', 'tone' => 'violet', 'fields' => [
                ['Aktuální doprava', (string) ($shipping['label'] ?? $shipping['method'] ?? ''), 'truck'],
                ['Expeduje', ($order['fulfillment_source'] ?? 'own') === 'external' ? 'Externí dodavatel' : 'Obchod', 'package'],
            ]],
        ];
        if (!empty($order['dispatch_shipping_changed'])) {
            $contactGroups[1]['fields'][] = ['Původně objednáno', (string) ($order['shipping_ordered']['label'] ?? $order['shipping_ordered']['method'] ?? '') . ' · účtováno ' . $orderMoney($order['shipping_czk'] ?? 0), 'truck'];
        }
        if (!empty($order['fulfillment_note'])) {
            $contactGroups[1]['fields'][] = ['Poznámka k expedici', (string) $order['fulfillment_note'], 'document'];
        }
        if ($isPickup) {
            $addressFields = [
                ['Výdejní místo', (string) ($shipping['pickup_point'] ?? ''), 'pin'],
                ['Adresa výdejního místa', (string) ($shipping['pickup_address'] ?? ''), 'home'],
            ];
            if (!empty($shipping['pickup_code'])) $addressFields[] = ['Kód výdejního místa', (string) $shipping['pickup_code'], 'hash'];
            if (($shipping['method'] ?? '') === 'balikovna_pickup' && !empty($shipping['pickup_postal_code'])) {
                $addressFields[] = ['PSČ Balíkovny', (string) $shipping['pickup_postal_code'], 'hash'];
            }
        } else {
            $addressFields = [
                ['Ulice a číslo domu', (string) ($shipping['street'] ?? ''), 'home'],
                ['Město', (string) ($shipping['city'] ?? ''), 'pin'],
                ['PSČ', (string) ($shipping['postal_code'] ?? ''), 'hash'],
                ['Země', (string) ($shipping['country'] ?? 'CZ'), 'globe'],
            ];
        }
        $contactGroups[] = ['title' => $isPickup ? 'Výdejní místo' : 'Adresa příjemce', 'tone' => 'teal', 'fields' => $addressFields];
        ?>
        <div class="panel-order-contact-groups">
          <?php foreach ($contactGroups as $group): ?>
            <section class="panel-order-contact-group" data-tone="<?= $escape($group['tone']) ?>" aria-label="<?= $escape($group['title']) ?>">
              <h3><?= $escape($group['title']) ?></h3>
              <dl class="panel-order-contact-fields">
                <?php foreach ($group['fields'] as [$label, $value, $icon]): ?>
                  <div class="panel-order-contact-field">
                    <dt><span class="panel-order-contact-icon"><?= \SimpleStore\Rendering\Icon::render($icon) ?></span><?= $escape($label) ?></dt>
                    <dd><span class="panel-order-contact-value"><?= $escape($value !== '' ? $value : 'Neuvedeno') ?></span>
                      <?php if ($value !== ''): ?><button type="button" class="panel-order-field-copy" data-order-copy="<?= $escape($value) ?>" data-order-copy-label="<?= $escape($label) ?>" aria-label="<?= $escape('Kopírovat: ' . $label) ?>" title="<?= $escape('Kopírovat: ' . $label) ?>"><span class="order-copy-idle"><?= \SimpleStore\Rendering\Icon::render('copy') ?></span><span class="order-copy-done" hidden><?= \SimpleStore\Rendering\Icon::render('check') ?></span></button><?php endif; ?>
                    </dd>
                  </div>
                <?php endforeach; ?>
              </dl>
            </section>
          <?php endforeach; ?>
        </div>
        <?php if ($orderTrackingReady): ?>
          <details class="panel-order-accordion"><summary>Sledování zásilky a externí dodavatel</summary>
            <p class="panel-help">U Zásilkovny se použije odkaz z API, u GLS a Balíkovny registrované číslo. Číslo nebo HTTPS odkaz můžeš doplnit i ručně. Po předání dopravci se nové údaje pošlou zákazníkovi; stejné uložení zprávu neopakuje.</p>
            <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="save-order-tracking"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
              <label>Číslo zásilky<input name="tracking_number" maxlength="100" value="<?= $escape($orderManualTracking['number']) ?>" placeholder="Číslo přidělené dopravcem"></label>
              <label>Veřejný odkaz pro sledování<input type="url" name="tracking_url" maxlength="1000" value="<?= $escape($orderManualTracking['url']) ?>" placeholder="https://..."></label>
              <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit sledování</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($shippingChangeReady && $shippingChangeOptions !== []): ?>
          <details class="panel-order-accordion" <?= $shippingEntered !== [] ? 'open' : '' ?>>
            <summary><?= \SimpleStore\Rendering\Icon::render('edit') ?> Změnit skutečného dopravce</summary>
            <p class="panel-help">Změna se týká expedice. Původní cena dopravy, objednávka a vystavený doklad zůstávají stejné.</p>
            <form class="panel-form" method="post" action="<?= \SimpleStore\Navigation\PanelUrl::escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
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
              <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit dopravce pro expedici</button>
            </form>
          </details>
        <?php endif; ?>
        <?php require __DIR__ . "/packeta.php"; ?>
        <?php require __DIR__ . "/carrier.php"; ?>
      </section>
