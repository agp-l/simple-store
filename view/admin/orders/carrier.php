        <?php if (in_array($shipping['method'] ?? '', ['balikovna_pickup', 'gls_pickup', 'gls_home'], true)): ?>
          <?php $carrierBalik = ($shipping['method'] ?? '') === 'balikovna_pickup'; ?>
          <details class="panel-order-accordion panel-order-dispatch" <?= $carrierShipment !== null || $orderError !== '' || isset($_GET['carrier_saved']) ? 'open' : '' ?>>
            <summary>Podání zásilky <?= $carrierBalik ? 'Balíkovnou' : 'GLS' ?><?php if ($carrierShipment !== null): ?> · <?= $carrierShipment['status'] === 'registered' ? 'číslo ' . $escape($carrierShipment['tracking_number']) : 'podklady uloženy' ?><?php endif; ?></summary>
          <div class="panel-packeta-dispatch">
            <?php if (!$carrierReady): ?>
              <p class="panel-help">Nejdřív <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
            <?php else: ?>
              <?php if (($_GET['carrier_saved'] ?? '') === 'carrier-save'): ?><p class="panel-notice" role="status">Podklady byly uloženy. Zásilka ještě nevznikla u dopravce.</p><?php endif; ?>
              <?php if (($_GET['carrier_saved'] ?? '') === 'carrier-register'): ?><p class="panel-notice" role="status">Číslo zásilky od dopravce bylo uloženo. Objednávku označ jako odeslanou až po předání balíku.</p><?php endif; ?>
              <?php if ($carrierShipment !== null): ?>
                <p class="panel-order-state"><?= $carrierShipment['status'] === 'draft' ? ($carrierBalik ? 'Údaje připraveny · podání v Balíkovně čeká' : 'CSV připraveno · čeká na import') : 'Číslo dopravce zapsáno' ?></p>
                <?php if ($carrierShipment['status'] === 'registered'): ?>
                  <dl class="panel-order-facts"><div><dt>Číslo zásilky</dt><dd><strong><?= $escape($carrierShipment['tracking_number']) ?></strong></dd></div></dl>
                <?php endif; ?>
                <?php if ($carrierBalik): ?>
                  <dl class="panel-order-facts">
                    <div><dt>Příjemce</dt><dd><?= $escape($carrierShipment['draft']['recipient'] ?? '') ?></dd></div>
                    <div><dt>Kontakt</dt><dd><?= $escape($carrierShipment['draft']['email'] ?? '') ?> · <?= $escape($carrierShipment['draft']['phone'] ?? '') ?></dd></div>
                    <div><dt>Výdejní místo</dt><dd><?= $escape($carrierShipment['draft']['pickup_point'] ?? '') ?> · <?= $escape($carrierShipment['draft']['pickup_address'] ?? '') ?><br>ID <?= $escape($carrierShipment['draft']['pickup_code'] ?? '') ?> · PSČ <?= $escape($carrierShipment['draft']['postal_code'] ?? '') ?></dd></div>
                    <div><dt>Hmotnost</dt><dd><?= $escape($carrierShipment['draft']['weight_kg'] ?? '') ?> kg</dd></div>
                  </dl>
                <?php elseif (!$gatewayDispatchBlocked): ?><p><a class="panel-button" href="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id'] . '&carrier_csv=1') ?>">Stáhnout CSV pro GLS e-Balík</a></p><?php endif; ?>
              <?php endif; ?>
              <?php if ($carrierBalik): ?>
                <p class="panel-help">Vyhledávací mapa Balíkovny pouze vrací vybrané místo; sama nevytváří zásilku ani čárový kód. Údaje níže si připrav pro <a href="https://www.balikovna.cz/cs/web/guest/poslat-balik" target="_blank" rel="noopener noreferrer">podání na webu Balíkovny ↗</a>. Po vytvoření zásilky tam získáš štítek nebo podací kód. Tento e-shop bez podání u dopravce platný štítek nevytvoří.</p>
              <?php else: ?>
                <p class="panel-help">CSV má 17 sloupců bez hlavičky pro <strong>výchozí import GLS e-Balík</strong>. U ParcelShopu je ID místa v posledním sloupci; při doručení na adresu zůstává prázdný. Zkontroluj náhled importu, vygenerovaný štítek i cenu dopravy v portálu.</p>
              <?php endif; ?>
              <?php if ($carrierShipment === null || $carrierShipment['status'] === 'draft'): ?>
                <?php if (!$paid || $gatewayDispatchBlocked || ($order['fulfillment_source'] ?? 'own') !== 'own' || in_array($order['status'], ['shipped', 'completed', 'cancelled', 'test'], true)): ?>
                  <p class="panel-help">Podklady lze připravovat jen pro zaplacenou aktivní objednávku expedovanou obchodem. Externí dodavatel podává sám.</p>
                <?php else: ?>
                  <?php
                    $addressDefaults = \SimpleStore\Checkout\CarrierShipmentDraft::addressDefaults($shipping);
                    $nameDefaults = \SimpleStore\Checkout\CarrierShipmentDraft::nameDefaults((string) ($shipping['recipient'] ?? $shipping['name'] ?? ''));
                    $savedDraft = is_array($carrierShipment['draft'] ?? null) ? $carrierShipment['draft'] : [];
                    $enteredDraft = ($method ?? 'GET') === 'POST' && $carrierAction === 'carrier-save' &&
                        (string) ($_POST['id'] ?? '') === (string) $order['id'] ? $_POST : [];
                    $draftValue = static fn (string $key, string $default): string =>
                        is_string($enteredDraft[$key] ?? null) ? $enteredDraft[$key] :
                        (is_string($savedDraft[$key] ?? null) ? $savedDraft[$key] : $default);
                  ?>
                  <?php if ($carrierShipment !== null): ?><details class="panel-order-accordion panel-order-edit-draft" <?= $enteredDraft !== [] ? 'open' : '' ?>><summary>Upravit podklady k podání</summary><?php endif; ?>
                  <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="carrier-save"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <label>Příjemce / kontaktní osoba<input name="recipient" value="<?= $escape($draftValue('recipient', (string) ($shipping['recipient'] ?? $shipping['name'] ?? ''))) ?>" maxlength="140" required></label>
                    <label>E-mail<input type="email" name="email" value="<?= $escape($draftValue('email', (string) ($order['customer_email'] ?? ''))) ?>" maxlength="254" required></label>
                    <label>Telefon<input type="tel" name="phone" value="<?= $escape($draftValue('phone', (string) ($shipping['phone'] ?? ''))) ?>" maxlength="40" required></label>
                    <label>Hmotnost zabalené zásilky v kg<input name="weight_kg" inputmode="decimal" value="<?= $escape($draftValue('weight_kg', '1')) ?>" required></label>
                    <?php if ($carrierBalik): ?>
                      <p class="panel-help">Vybrané místo: <?= $escape($shipping['pickup_point'] ?? '') ?> · <?= $escape($shipping['pickup_address'] ?? '') ?> · ID <?= $escape($shipping['pickup_code'] ?? '') ?>.</p>
                    <?php else: ?>
                      <label>Jméno příjemce<input name="first_name" value="<?= $escape($draftValue('first_name', $nameDefaults['first_name'])) ?>" maxlength="70" required></label>
                      <label>Příjmení příjemce<input name="surname" value="<?= $escape($draftValue('surname', $nameDefaults['surname'])) ?>" maxlength="70" required></label>
                      <p class="panel-help"><?= ($shipping['method'] ?? '') === 'gls_pickup' ? 'Adresu místa GLS zkontroluj podle vybraného bodu v objednávce.' : 'Zkontroluj adresu příjemce.' ?> Jméno a číslo domu jsme rozdělili automaticky; před exportem je ověř.</p>
                      <label>Ulice<input name="street" value="<?= $escape($draftValue('street', $addressDefaults['street'])) ?>" maxlength="120" required></label>
                      <label>Číslo domu<input name="house_number" value="<?= $escape($draftValue('house_number', $addressDefaults['house_number'])) ?>" maxlength="30" required></label>
                      <label>Obec<input name="city" value="<?= $escape($draftValue('city', $addressDefaults['city'])) ?>" maxlength="120" required></label>
                      <label>PSČ<input name="postal_code" value="<?= $escape($draftValue('postal_code', $addressDefaults['postal_code'])) ?>" maxlength="12" required></label>
                    <?php endif; ?>
                    <button class="panel-button" type="submit"><?= $carrierShipment === null ? 'Uložit podklady k podání' : 'Upravit podklady' ?></button>
                  </form>
                  <?php if ($carrierShipment !== null): ?></details><?php endif; ?>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($carrierShipment !== null && $carrierShipment['status'] === 'draft' && !$gatewayDispatchBlocked): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="carrier-register"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label>Skutečné číslo zásilky od dopravce<input name="tracking_number" autocomplete="off" minlength="6" maxlength="50" required></label>
                  <label class="panel-check"><input type="checkbox" name="carrier_confirmed" value="1" required> Zkontroloval/a jsem podání u dopravce a opisuji číslo skutečně vytvořené zásilky.</label>
                  <button class="panel-button" type="submit">Zapsat číslo dopravce</button>
                </form>
              <?php endif; ?>
              <p class="panel-help">Uložení podkladů ani čísla v e-shopu nenahrazuje podání u dopravce. Balík označ jeho štítkem nebo kódem. Stav vyřízení objednávky nastav zvlášť po skutečném předání.</p>
            <?php endif; ?>
          </div>
          </details>
        <?php endif; ?>
