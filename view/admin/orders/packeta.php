        <?php if (in_array($shipping['method'] ?? '', ['zasilkovna_pickup', 'zasilkovna_home'], true)): ?>
          <details class="panel-order-accordion panel-order-dispatch" <?= $packetaShipment !== null || $orderError !== '' || isset($_GET['packeta_result']) || isset($_GET['packeta_saved']) ? 'open' : '' ?>>
            <summary>Podání zásilky Zásilkovně<?php if ($packetaShipment !== null && ($packetaShipment['status'] ?? '') === 'created'): ?> · <?= $escape($packetaShipment['barcode_text'] ?: $packetaShipment['barcode']) ?><?php endif; ?></summary>
          <div class="panel-packeta-dispatch">
            <?php if (($order['fulfillment_source'] ?? 'own') !== 'external' && !$packetaReady): ?>
              <p class="panel-help">Nejdřív <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>, aby vznikla tabulka zásilek.</p>
            <?php elseif (($order['fulfillment_source'] ?? 'own') !== 'external' && !$packetaConfigured): ?>
              <p class="panel-help">V <a href="<?= $escape($adminUrl . '?section=settings') ?>">nastavení obchodu</a> vyplň soukromé API heslo a označení odesílatele z klientské sekce Zásilkovny.</p>
            <?php endif; ?>
            <?php $packetaNotice = match ($_GET['packeta_result'] ?? '') {
                'packeta-cancel', 'packeta-cancel-confirmed' => 'Zásilka byla stornována u Zásilkovny. Můžeš opravit údaje a vytvořit novou.',
                'packeta-cancel-not-done' => 'Zásilka zůstává aktivní u Zásilkovny.',
                default => 'Údaje zásilky byly uloženy. Stav odeslání objednávky se nastavuje zvlášť po předání balíku.',
            }; ?>
            <?php if (($_GET['packeta_saved'] ?? '') === '1' || isset($_GET['packeta_result'])): ?><p class="panel-notice" role="status"><?= $escape($packetaNotice) ?></p><?php endif; ?>
            <?php if ($packetaShipment !== null && $packetaShipment['status'] === 'created'): ?>
              <?php $submittedPacket = json_decode((string) ($packetaShipment['submitted_json'] ?? ''), true); ?>
              <p class="panel-help">Zásilka je vytvořená v systému Zásilkovny. <?= $packetaShipment['method'] === 'zasilkovna_home' && !$packetaShipment['courier_number'] ? 'U doručení domů nejprve vyžádej číslo dopravce, potom stáhni štítek.' : 'Štítek je připraven ke stažení.' ?> Po zabalení označ balík čitelným číslem nebo na něj nalep štítek.</p>
              <?php if (!empty($packetaShipment['last_error'])): ?><p class="panel-error" role="alert"><?= $escape($packetaShipment['last_error']) ?></p><?php endif; ?>
              <dl class="panel-order-facts">
                <div><dt>Číslo na balík</dt><dd><strong><?= $escape($packetaShipment['barcode_text'] ?: $packetaShipment['barcode']) ?></strong></dd></div>
                <div><dt>Kód zásilky</dt><dd><strong><?= $escape($packetaShipment['barcode']) ?></strong></dd></div>
                <?php if ($packetaShipment['courier_number']): ?><div><dt>Číslo dopravce</dt><dd><?= $escape($packetaShipment['courier_number']) ?></dd></div><?php endif; ?>
                <div><dt>Hmotnost</dt><dd><?= $escape($packetaShipment['weight_kg']) ?> kg</dd></div>
                <?php if (is_array($submittedPacket)): ?>
                  <div><dt>Podaný kontakt</dt><dd><?= $escape(trim((string) ($submittedPacket['name'] ?? '') . ' ' . (string) ($submittedPacket['surname'] ?? ''))) ?><br><?= $escape($submittedPacket['email'] ?? '') ?><br><?= $escape($submittedPacket['phone'] ?? '') ?></dd></div>
                  <?php if ($packetaShipment['method'] === 'zasilkovna_home'): ?>
                    <div><dt>Podaná adresa HD</dt><dd><?= $escape(trim((string) ($submittedPacket['street'] ?? '') . ' ' . (string) ($submittedPacket['houseNumber'] ?? ''))) ?><br><?= $escape(trim((string) ($submittedPacket['zip'] ?? '') . ' ' . (string) ($submittedPacket['city'] ?? ''))) ?></dd></div>
                  <?php else: ?><div><dt>ID výdejního místa</dt><dd><?= $escape($submittedPacket['addressId'] ?? '') ?></dd></div><?php endif; ?>
                <?php endif; ?>
              </dl>
              <?php if ($packetaConfigured && $packetaShipment['method'] === 'zasilkovna_home' && !$packetaShipment['courier_number']): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-courier"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <button class="panel-button" type="submit">Vyžádat číslo dopravce pro HD</button>
                </form>
              <?php elseif ($packetaConfigured): ?>
                <p><a class="panel-button" href="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id'] . '&packeta_label=1') ?>">Stáhnout štítek PDF</a></p>
              <?php endif; ?>
              <?php if ($packetaTrackingUrl !== null): ?><p><a class="panel-text-link" href="<?= $escape($packetaTrackingUrl) ?>" target="_blank" rel="noopener noreferrer">Sledovat zásilku u Zásilkovny →</a></p><?php endif; ?>
              <?php if ($packetaCancelReady && $packetaConfigured && !in_array($order['status'], ['shipped', 'completed', 'cancelled', 'test'], true)): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-cancel"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label class="panel-check"><input type="checkbox" name="packeta_cancel_confirmed" value="1" required> Potvrzuji, že balík ještě nebyl fyzicky předán dopravci. Storno ruší zásilku u Zásilkovny, nikoli objednávku nebo platbu.</label>
                  <button class="panel-button" type="submit">Stornovat zásilku u Zásilkovny</button>
                </form>
              <?php elseif (!$packetaCancelReady): ?><p class="panel-help">Pro možnost storna <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
              <p class="panel-help">Samotné vytvoření čísla ještě neznamená, že je balík fyzicky odeslaný.</p>
            <?php elseif ($packetaShipment !== null && in_array($packetaShipment['status'], ['cancelling', 'cancel_uncertain'], true)): ?>
              <p class="panel-error" role="alert">Výsledek storna zásilky <?= $escape($packetaShipment['barcode'] ?? '') ?> není jistý. Zkontroluj zásilku v klientské sekci Zásilkovny. Nové podání je zatím zablokované.</p>
              <?php if ($packetaShipment['status'] !== 'cancelling' || strtotime((string) $packetaShipment['updated_at'] . ' UTC') <= time() - 60): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-cancel-confirmed"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label class="panel-check"><input type="checkbox" name="packeta_cancel_checked" value="1" required> Ověřil/a jsem v klientské sekci, že je zásilka stornovaná.</label>
                  <button class="panel-button" type="submit">Potvrdit storno a povolit nové podání</button>
                </form>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-cancel-not-done"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label class="panel-check"><input type="checkbox" name="packeta_cancel_checked" value="1" required> Ověřil/a jsem v klientské sekci, že zásilka zůstala aktivní.</label>
                  <button class="panel-button" type="submit">Ponechat aktivní zásilku</button>
                </form>
              <?php else: ?><p class="panel-help">Storno ještě probíhá. Po minutě obnov stránku.</p><?php endif; ?>
            <?php elseif ($packetaShipment !== null && in_array($packetaShipment['status'], ['submitting', 'uncertain'], true)): ?>
              <p class="panel-error" role="alert">Výsledek podání není jistý. Vyhledej v klientské sekci Zásilkovny objednávku <?= $escape($order['order_number']) ?>. Další podání neprováděj, dokud nezjistíš, zda zásilka vznikla.</p>
              <?php if ($packetaShipment['status'] !== 'submitting' || strtotime((string) $packetaShipment['updated_at'] . ' UTC') <= time() - 60): ?>
              <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-reconcile"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                <label>Nalezené číslo zásilky<input name="packeta_barcode" placeholder="Z1234567890" pattern="Z[0-9]{1,20}" required></label>
                <label><input type="checkbox" name="packeta_checked" value="1" required> Ověřil/a jsem, že tato zásilka patří k objednávce.</label>
                <button class="panel-button" type="submit">Uložit nalezenou zásilku</button>
              </form>
              <?php else: ?><p class="panel-help">Podání ještě probíhá. Pro kontrolu po minutě obnov stránku.</p><?php endif; ?>
              <?php if (strtotime((string) $packetaShipment['updated_at'] . ' UTC') <= time() - 60): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-retry"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label><input type="checkbox" name="packeta_not_created" value="1" required> Ověřil/a jsem v klientské sekci, že zásilka nevznikla.</label>
                  <button class="panel-button" type="submit">Povolit nový pokus</button>
                </form>
              <?php endif; ?>
            <?php else: ?>
              <?php if ($packetaShipment !== null && $packetaShipment['status'] === 'cancelled'): ?><p class="panel-notice">Původní zásilka <?= $escape($packetaShipment['barcode'] ?? '') ?> byla stornována.<?= ($order['fulfillment_source'] ?? 'own') === 'external' ? '' : ' Oprav údaje a vytvoř novou zásilku.' ?></p><?php endif; ?>
              <?php if ($packetaShipment !== null && $packetaShipment['status'] === 'rejected'): ?>
                <p class="panel-error" role="alert"><?= $escape($packetaShipment['last_error'] ?: 'Zásilkovna zásilku odmítla.') ?></p>
                <?php if (str_contains((string) ($packetaShipment['last_error'] ?? ''), 'eshop_id:')): ?>
                  <?php $rejectedPacket = json_decode((string) ($packetaShipment['submitted_json'] ?? ''), true); ?>
                  <?php $sentSender = is_array($rejectedPacket) ? ($rejectedPacket['eshop'] ?? '') : ''; ?>
                  <p class="panel-help">Odeslané označení odesílatele: <strong><?= $escape(is_string($sentSender) ? $sentSender : '') ?></strong>. Zkopíruj přesné <strong>Označení</strong> ze čtvrtého sloupce <a href="https://client.packeta.com/senders/" target="_blank" rel="noopener noreferrer">seznamu odesílatelů Zásilkovny</a> do <a href="<?= $escape($adminUrl . '?section=settings') ?>">nastavení obchodu</a>. Potom zásilku podej znovu.</p>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!$paid): ?><p class="panel-help"><?= $onlineGateway ? 'Nejdřív vyčkej na potvrzení platby ' . $gatewayName . ' nebo načti aktuální stav brány.' : 'Nejdřív ověř platbu na bankovním výpisu a označ ji jako přijatou.' ?></p><?php endif; ?>
              <?php if (($order['fulfillment_source'] ?? 'own') === 'external'): ?><p class="panel-help">Expedici zajišťuje externí dodavatel. Stav objednávky nastav v panelu Vyřízení; zásilku tímto účtem Zásilkovny nepodávej.</p><?php endif; ?>
              <?php if (($order['fulfillment_source'] ?? 'own') !== 'external' && ($shipping['method'] ?? '') === 'zasilkovna_pickup' &&
                  (($shipping['pickup_verified'] ?? false) !== true ||
                  preg_match('/^[0-9]{1,12}$/D', (string) ($shipping['pickup_code'] ?? '')) !== 1)): ?>
                <p class="panel-help">Výdejní místo nebylo při objednávce ověřeno přes widget. Při ručním odeslání zkontroluj název a adresu; pro podání přes API zadej správné ID, které server před podáním ověří.</p>
              <?php endif; ?>
              <?php if ($packetaReady && $packetaConfigured && $paid && !$gatewayDispatchBlocked && ($order['fulfillment_source'] ?? 'own') !== 'external' && !in_array($order['status'], ['shipped', 'cancelled', 'completed', 'test'], true)): ?>
                <?php
                $recipientParts = preg_split('/\s+/u', trim((string) ($shipping['recipient'] ?? $shipping['name'] ?? ''))) ?: [];
                $defaultSurname = count($recipientParts) > 1 ? array_pop($recipientParts) : '';
                $defaultFirstName = implode(' ', $recipientParts);
                $streetOriginal = trim((string) ($shipping['street'] ?? ''));
                $streetMatch = [];
                $streetSplit = preg_match('/^(.+?)\s+(\d+[a-zA-Z]?(?:\/\d+[a-zA-Z]?)?)$/uD', $streetOriginal, $streetMatch) === 1;
                $entered = ($method ?? 'GET') === 'POST' && $packetaAction === 'packeta-create' &&
                    (string) ($_POST['id'] ?? '') === (string) $order['id'] ?
                    array_filter($_POST, 'is_string') : [];
                ?>
                <details class="panel-order-accordion panel-order-edit-draft" <?= $entered !== [] ? 'open' : '' ?>><summary>Zkontrolovat údaje a vytvořit zásilku</summary>
                <p class="panel-help">Zkontroluj údaje příjemce a hmotnost již zabaleného balíku. API použije číslo objednávky <?= $escape($order['order_number']) ?> a platbu bez dobírky. Případné opravy kontaktu a adresy níže se uloží k zásilce; původní objednávka zůstane v historii.</p>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="packeta-create"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label>Jméno<input name="first_name" value="<?= $escape($entered['first_name'] ?? $defaultFirstName) ?>" maxlength="70" required></label>
                  <label>Příjmení<input name="surname" value="<?= $escape($entered['surname'] ?? $defaultSurname) ?>" maxlength="70" required></label>
                  <label>E-mail<input name="email" type="email" value="<?= $escape($entered['email'] ?? $order['customer_email']) ?>" maxlength="254" required></label>
                  <label>Telefon<input name="phone" type="tel" value="<?= $escape($entered['phone'] ?? $shipping['phone'] ?? '') ?>" maxlength="40" required></label>
                  <label>Hmotnost balíku v kg<input name="weight_kg" type="text" inputmode="decimal" value="<?= $escape($entered['weight_kg'] ?? '1') ?>" placeholder="např. 0,75" required></label>
                  <?php if ($shipping['method'] === 'zasilkovna_home'): ?>
                    <label>Ulice<input name="street" value="<?= $escape($entered['street'] ?? ($streetSplit ? $streetMatch[1] : $streetOriginal)) ?>" maxlength="120" required></label>
                    <label>Číslo domu<input name="house_number" value="<?= $escape($entered['house_number'] ?? ($streetSplit ? $streetMatch[2] : '')) ?>" maxlength="30" required></label>
                    <label>Město<input name="city" value="<?= $escape($entered['city'] ?? $shipping['city'] ?? '') ?>" maxlength="120" required></label>
                    <label>PSČ<input name="postal_code" value="<?= $escape($entered['postal_code'] ?? $shipping['postal_code'] ?? '') ?>" maxlength="20" required></label>
                    <p class="panel-help">Ověř rozdělení ulice a čísla domu a úplnou dodací adresu před podáním.</p>
                  <?php else: ?>
                    <label>ID výdejního místa Zásilkovny<input name="pickup_point_id" value="<?= $escape($entered['pickup_point_id'] ?? $shipping['pickup_code'] ?? '') ?>" maxlength="80" required></label>
                    <p class="panel-help">Původní místo: <?= $escape($shipping['pickup_point'] ?? '') ?> (ID <?= $escape($shipping['pickup_code'] ?? '') ?>). Pokud se místo mění, zadej ID nového místa z klientské sekce Zásilkovny. Před podáním ho server ověří; původní objednávka se nemění.</p>
                  <?php endif; ?>
                  <button class="panel-button" type="submit">Vytvořit zásilku u Zásilkovny</button>
                </form>
                </details>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($cancelledPackets !== []): ?>
              <p class="panel-help">Dříve stornované zásilky: <?php foreach ($cancelledPackets as $old): ?><?= $escape($old['barcode']) ?> (<?= $escape($old['cancelled_at']) ?>) <?php endforeach; ?></p>
            <?php endif; ?>
          </div>
          </details>
        <?php endif; ?>
