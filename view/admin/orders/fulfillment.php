      <h2>Vyřízení</h2>
      <p class="panel-order-state"><?= $escape($orderFulfillmentLabel($order['status'] ?? '')) ?></p>
      <?php if (!in_array($order['status'], ['completed', 'cancelled', 'test'], true) && !$gatewayDispatchBlocked): ?>
      <?php $packetaMethod = in_array($shipping['method'] ?? '', ['zasilkovna_pickup', 'zasilkovna_home'], true);
      $fulfillmentSource = $order['fulfillment_source'] ?? 'own'; ?>
      <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="set-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
        <?php if ($paid && $order['status'] !== 'shipped' && $fulfillmentSourceReady): ?>
          <label>Expedici zajišťuje <select name="fulfillment_source">
            <option value="own" <?= $fulfillmentSource === 'own' ? 'selected' : '' ?>>Obchod</option>
            <option value="external" <?= $fulfillmentSource === 'external' ? 'selected' : '' ?>>Externí dodavatel</option>
          </select></label>
          <label>Dodavatel nebo poznámka k externí expedici (volitelné)<input name="fulfillment_note" maxlength="190" value="<?= $escape($order['fulfillment_note'] ?? '') ?>"></label>
        <?php else: ?><input type="hidden" name="fulfillment_source" value="<?= $escape($fulfillmentSource) ?>"><input type="hidden" name="fulfillment_note" value="<?= $escape($order['fulfillment_note'] ?? '') ?>"><?php endif; ?>
        <label>Vyřízení objednávky <select name="order_status">
          <?php if ($paid): ?>
            <?php if ($order['status'] !== 'shipped'): ?>
              <option value="processing" <?= $order['status'] === 'processing' ? 'selected' : '' ?>>Připravuje se</option>
              <option value="ready_to_ship" <?= $order['status'] === 'ready_to_ship' ? 'selected' : '' ?>>Připravena k odeslání</option>
            <?php endif; ?>
            <option value="shipped" <?= $order['status'] === 'shipped' ? 'selected' : '' ?>>Předána dopravci</option>
            <?php if ($order['status'] === 'shipped'): ?><option value="completed">Dokončena po doručení</option><?php endif; ?>
          <?php else: ?><option value="cancelled">Stornována (bez přijaté platby)</option><?php endif; ?>
        </select></label>
        <button class="panel-button" type="submit">Uložit stav</button>
        <p class="panel-help">Připravena k odeslání znamená zabalenou zásilku, případně potvrzení připravenosti od dodavatele. Stav Předána dopravci nastav až po skutečném předání balíku (u dodavatele po jeho potvrzení), Dokončena po doručení. <?= $packetaMethod ? 'Při expedici obchodem přes Zásilkovnu musí být místní zásilka vytvořená. Dodavatel může expedovat bez místního podání.' : '' ?> Dokončené a stornované objednávky se zákazníkovi přesunou do historie.</p>
        <?php if ($paid && !$fulfillmentSourceReady): ?><p class="panel-help">Pro volbu externího dodavatele <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
      </form>
      <?php elseif ($gatewayDispatchBlocked): ?>
        <p class="panel-help">Běžnou expedici po vrácení nebo neověřeném stavu platby nelze potvrdit. Pokud opravuješ omylem uložený stav, použij níže ovládání oprav s uvedením důvodu.</p>
      <?php endif; ?>
      <?php if (!$orderControlsReady): ?>
        <p class="panel-help">Pro opravy a mazání objednávek <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
      <?php else: ?>
        <?php if (in_array($order['status'], ['shipped', 'completed'], true)): ?>
          <details class="panel-order-accordion panel-order-controls" aria-label="Oprava chybného odeslání">
            <summary>Opravit omylem nastavený stav</summary>
            <p class="panel-help">Použij jen když balík ve skutečnosti nebyl předán dopravci. Oprava nemění platbu ani zásilku u dopravce; zůstane zapsána v historii.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="confirmation" value="not_handed">
              <label>Skutečný stav <select name="order_status"><option value="processing">Připravuje se</option><option value="ready_to_ship">Připravena k odeslání</option></select></label>
              <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required placeholder="Například omylem označeno jako odeslané"></textarea></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Potvrzuji, že balík nebyl předán dopravci.</label>
              <button class="panel-button" type="submit">Opravit chybné odeslání</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($order['status'] === 'completed'): ?>
          <details class="panel-order-accordion panel-order-controls" aria-label="Oprava dokončení">
            <summary>Vrátit z dokončeno na předáno dopravci</summary>
            <p class="panel-help">Když objednávka stále cestuje a doručení bylo potvrzeno omylem.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="order_status" value="shipped"><input type="hidden" name="confirmation" value="not_delivered">
              <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required></textarea></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Potvrzuji, že zásilka ještě nebyla doručena.</label>
              <button class="panel-button" type="submit">Vrátit na předáno dopravci</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($order['status'] === 'cancelled' && !$paid): ?>
          <details class="panel-order-accordion panel-order-controls" aria-label="Obnovení objednávky">
            <summary>Obnovit stornovanou objednávku</summary>
            <p class="panel-help">Zrušení bylo omyl; platba zůstává neověřená.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="order_status" value="new"><input type="hidden" name="confirmation" value="reopen">
              <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required></textarea></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Potvrzuji, že chci zrušenou objednávku znovu otevřít.</label>
              <button class="panel-button" type="submit">Obnovit objednávku</button>
            </form>
          </details>
        <?php endif; ?>
        <?php $canOfferDeletion = in_array(($order['payment_method'] ?? ''),
            ['bank_transfer', 'comgate', 'gopay', 'btcpay', 'legacy'], true) ||
            (($order['status'] ?? '') === 'test' && ($order['payment_method'] ?? '') === 'test' &&
                ($order['payment_status'] ?? '') === 'test'); ?>
        <?php if ($canOfferDeletion): ?>
          <details class="panel-order-accordion panel-order-controls" aria-label="Smazání objednávky">
            <summary>Trvale smazat objednávku</summary>
            <p class="panel-help">Objednávka zmizí z běžného seznamu a účtu zákazníka bez ohledu na stav platby či vyřízení. Vystavená faktura, finanční záznamy a zaplacené položky zůstanou v účetnictví, čísla skutečných zásilek v databázi pro dohledání u dopravce. Odstranění objednávky nestornuje platbu ani fyzickou zásilku u dopravce. Nejasné založení platby u brány nejprve ověř. Do důvodu nepiš osobní údaje.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="delete-order"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="confirmation" value="delete">
              <label>Důvod smazání <textarea name="reason" minlength="8" maxlength="190" required placeholder="Například test pokladny"></textarea></label>
              <label>Opiš číslo <?= $escape($order['order_number']) ?><input name="order_number" autocomplete="off" required></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Rozumím trvalému smazání; případná platba na bankovním účtu tím nezmizí.</label>
              <button class="panel-button" type="submit">Trvale smazat objednávku</button>
            </form>
          </details>
        <?php endif; ?>
        <?php if ($orderEvents !== []): ?>
          <details class="panel-order-accordion panel-order-controls" aria-label="Historie zásahů">
            <summary>Historie zásahů správce (<?= count($orderEvents) ?>)</summary>
            <ul>
              <?php foreach ($orderEvents as $event): ?>
                <li><strong><?= $escape($event['created_at'] ?? '') ?></strong> · správce #<?= (int) ($event['admin_id'] ?? 0) ?> · <?php if (($event['action'] ?? '') === 'payment_correction'): ?>Platba: <?= $escape($orderPaymentLabel($event['old_status'] ?? '')) ?> → <?= $escape($orderPaymentLabel($event['new_status'] ?? '')) ?><?php elseif (($event['action'] ?? '') === 'shipping_changed'): ?>Doprava: <?= $escape($shippingMethodLabels[$event['old_status'] ?? ''] ?? $event['old_status'] ?? '') ?> → <?= $escape($shippingMethodLabels[$event['new_status'] ?? ''] ?? $event['new_status'] ?? '') ?><?php else: ?>Vyřízení: <?= $escape($orderFulfillmentLabel($event['old_status'] ?? '')) ?> → <?= $escape($orderFulfillmentLabel($event['new_status'] ?? '')) ?><?php endif; ?><br><?= $escape($event['reason'] ?? '') ?></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      <?php endif; ?>
