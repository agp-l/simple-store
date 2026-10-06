  <?php
  $paymentFilter ??= 'all';
  $orderSearch ??= '';
  $offset ??= 0;
  $orderPageUrl ??= $orderBaseUrl . '&status=' . rawurlencode($statusFilter) .
      '&payment=' . rawurlencode($paymentFilter) . '&q=' . rawurlencode($orderSearch);
  $orderMethodLabel = static fn (mixed $method): string => match ($method) {
      'bank_transfer' => 'Převod na účet', 'comgate' => 'Comgate',
      'gopay' => 'GoPay', 'btcpay' => 'BTCPay Server', 'test' => 'Test', default => (string) $method,
  };
  ?>
  <nav class="panel-quick panel-order-filters" aria-label="Filtrovat objednávky">
    <?php foreach (['all' => 'Všechny', 'pending' => 'Čeká na platbu', 'overdue' => 'Po splatnosti', 'paid' => 'Zaplaceno', 'processing' => 'Připravuje se', 'ready_to_ship' => 'Připraveno', 'shipped' => 'Předáno dopravci', 'completed' => 'Dokončeno', 'cancelled' => 'Zrušeno', 'test' => 'Testovací'] as $filter => $label): ?>
      <a href="<?= $escape($orderBaseUrl . '&status=' . $filter . '&payment=' . rawurlencode($paymentFilter) . '&q=' . rawurlencode($orderSearch)) ?>" <?= $statusFilter === $filter ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="panel-order-search" method="get" action="<?= $escape($adminUrl) ?>" role="search">
    <input type="hidden" name="section" value="orders"><input type="hidden" name="status" value="<?= $escape($statusFilter) ?>">
    <label>Číslo objednávky, e-mail nebo VS
      <input type="search" name="q" value="<?= $escape($orderSearch) ?>" maxlength="100" placeholder="Hledat objednávku">
    </label>
    <label>Způsob platby
      <select name="payment">
        <?php foreach (['all' => 'Všechny platby', 'bank_transfer' => 'Převod na účet', 'comgate' => 'Comgate', 'gopay' => 'GoPay', 'btcpay' => 'BTCPay Server', 'test' => 'Test'] as $method => $label): ?>
          <option value="<?= $escape($method) ?>" <?= $paymentFilter === $method ? 'selected' : '' ?>><?= $escape($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="panel-button" type="submit">Filtrovat</button>
    <?php if ($orderSearch !== '' || $paymentFilter !== 'all'): ?><a class="panel-text-link" href="<?= $escape($orderBaseUrl . '&status=' . $statusFilter) ?>">Vymazat filtry</a><?php endif; ?>
  </form>
  <?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Stav vyřízení byl uložen.</p><?php endif; ?>
  <?php if (($_GET['payment_saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Přijetí platby bylo potvrzeno.</p><?php endif; ?>
  <?php if (($_GET['overdue_cancelled'] ?? '') === '1'): ?><p class="panel-notice" role="status">Objednávka po splatnosti byla stornována; případné rezervace skladu se uvolnily.</p><?php endif; ?>
  <section class="panel-panel" aria-labelledby="panel-orders-list">
    <h2 id="panel-orders-list">Objednávky <span><?= count($orderPage['items']) ?> na stránce</span></h2>
    <?php if ($orderPage['items'] === []): ?><p class="panel-empty">V tomto přehledu zatím nejsou objednávky.</p><?php endif; ?>
    <?php if ($orderPage['items'] !== []): ?><div class="panel-order-table-wrap"><table class="panel-order-table">
      <thead><tr><th>Objednávka</th><th>Doručení</th><th>Platba</th><th>Vyřízení</th><th>Faktura</th><th>Celkem</th><th>Ovládání</th></tr></thead>
      <tbody>
      <?php foreach ($orderPage['items'] as $listed): ?>
        <?php
        $listedGoPay = ($listed['payment_method'] ?? '') === 'gopay';
        $listedGoPayState = $listedGoPay ? ($listed['gopay_payment_state'] ?? null) : null;
        $listedBtcpay = ($listed['payment_method'] ?? '') === 'btcpay';
        $listedBtcpayState = $listedBtcpay ? ($listed['btcpay_payment_state'] ?? null) : null;
        $listedRefunded = in_array($listedGoPayState, ['refunded', 'partially_refunded'], true);
        $listedGatewayBlocked = ($listed['payment_status'] ?? '') === 'paid' &&
            (($listedGoPay && $listedGoPayState !== 'paid') ||
            ($listedBtcpay && $listedBtcpayState !== 'settled'));
        $listedPaid = ($listed['payment_status'] ?? '') === 'paid' && !$listedGatewayBlocked;
        $listedPaymentLabel = $listedGoPayState === 'refunded' ? 'Platba vrácena' :
            ($listedGoPayState === 'partially_refunded' ? 'Platba částečně vrácena' :
            ($listedGatewayBlocked ? 'Platba k ověření' :
            ($listedBtcpay && $listedBtcpayState === 'processing' ? 'Platba se potvrzuje' :
            $orderPaymentLabel($listed['payment_status'] ?? ''))));
        $listedStatus = $listed['status'] ?? '';
        $listedOverdue = ($listed['payment_method'] ?? '') === 'bank_transfer' &&
            ($listed['payment_status'] ?? '') === 'pending' && $listedStatus === 'new' &&
            is_string($listed['payment_due_at'] ?? null) &&
            $listed['payment_due_at'] < gmdate('Y-m-d H:i:s');
        $listedPaidAfterCancel = $listedStatus === 'cancelled' &&
            ($listed['payment_status'] ?? '') === 'paid';
        $listedShipping = json_decode((string) ($listed['shipping_json'] ?? ''), true);
        $listedShipping = is_array($listedShipping) ? $listedShipping : [];
        $listedSource = ($listed['fulfillment_source'] ?? 'own') === 'external' ? 'external' : 'own';
        $listedShippingMethod = $listedShipping['method'] ?? '';
        $listedPacketaBlocked = $listedSource === 'own' &&
            in_array($listedShippingMethod, ['zasilkovna_pickup', 'zasilkovna_home'], true) &&
            ($listed['shipment_status'] ?? '') !== 'created';
        $listedNext = match ($listedStatus) {
            'new' => $listedPaid ? ['processing', 'ready_to_ship', 'shipped'] : [],
            'processing' => $listedPaid ? ['ready_to_ship', 'shipped'] : [],
            'ready_to_ship' => $listedPaid ? ['processing', 'shipped'] : [],
            'shipped' => $listedPaid ? ['completed'] : [],
            default => [],
        };
        if ($listedPacketaBlocked) {
            $listedNext = array_values(array_diff($listedNext, ['ready_to_ship', 'shipped']));
        }
        $listedQuickUrl = $orderPageUrl . '&offset=' . $offset;
        $listedId = (int) $listed['id'];
        ?>
        <tr>
          <td data-label="Objednávka">
            <a class="panel-order-primary" href="<?= $escape($orderBaseUrl . '&id=' . $listedId) ?>"><?= $escape($listed['order_number'] ?? '') ?></a>
            <small><?= $escape($listed['created_at'] ?? '') ?><br><?= $escape($listedShipping['recipient'] ?? $listed['customer_email'] ?? '') ?></small>
            <small><?= $escape($listed['customer_email'] ?? '') ?> · <?= ($listed['payment_method'] ?? '') === 'test' ? 'TEST' : 'VS ' . $escape($listed['variable_symbol'] ?? '–') ?></small>
          </td>
          <td data-label="Doručení"><strong><?= $escape($listedShipping['label'] ?? 'Neuvedeno') ?></strong>
            <?php if ($listedSource === 'external'): ?><small>Externí dodavatel</small><?php endif; ?>
            <?php if (($listed['shipment_status'] ?? '') === 'created'): ?><small>Zásilka vytvořena</small><?php endif; ?>
            <?php if (($listed['carrier_shipment_status'] ?? '') === 'registered'): ?><small>Číslo zásilky zapsáno</small><?php endif; ?>
          </td>
          <td data-label="Platba"><span class="panel-order-state <?= $listedRefunded ? 'is-refunded' : ($listedPaid ? 'is-paid' : 'is-pending') ?>"><?= $escape($listedPaymentLabel) ?></span><small><?= $escape($orderMethodLabel($listed['payment_method'] ?? '')) ?></small>
            <?php if (($listed['payment_method'] ?? '') === 'bank_transfer' && !empty($listed['payment_due_at'])): ?><small>Splatnost <?= $escape($listed['payment_due_at']) ?> UTC</small><?php endif; ?>
            <?php if ($listedOverdue): ?><span class="panel-order-state is-refunded">Po splatnosti</span><?php endif; ?>
            <?php if ($listedPaidAfterCancel): ?><p class="panel-error" role="alert">Zaplaceno po stornu – prověř vrácení platby.</p><?php endif; ?>
            <?php if ($listedGatewayBlocked): ?><small>Ověř transakci v detailu objednávky.</small><?php endif; ?>
          </td>
          <td data-label="Vyřízení"><span class="panel-order-state <?= in_array($listedStatus, ['shipped', 'completed'], true) ? 'is-paid' : 'is-pending' ?>"><?= $escape($orderFulfillmentLabel($listedStatus)) ?></span>
            <?php if ($listedPacketaBlocked && $listedPaid && $listedNext !== []): ?><small>Pro přípravu zásilky otevři detail.</small><?php endif; ?>
          </td>
          <td data-label="Faktura"><?php if ((int) ($listed['invoice_id'] ?? 0) > 0): ?>
            <a class="panel-text-link" href="<?= $escape($adminUrl . '?section=accounting&tab=invoices&invoice_id=' . (int) $listed['invoice_id'] . '&print=1') ?>" target="_blank" rel="noopener noreferrer"><?= $escape($listed['invoice_number'] ?? 'Otevřít fakturu') ?></a>
            <?php else: ?><span class="panel-order-muted">Nevystavena</span><?php endif; ?>
          </td>
          <td data-label="Celkem" class="panel-order-amount"><?= $orderMoney($listed['total_czk'] ?? 0) ?></td>
          <td data-label="Ovládání" class="panel-order-actions">
            <a class="panel-text-link" href="<?= $escape($orderBaseUrl . '&id=' . $listedId) ?>">Otevřít detail</a>
            <?php if (!$listedPaid && ($listed['payment_method'] ?? '') === 'bank_transfer' &&
                ($listed['payment_status'] ?? '') === 'pending' &&
                !in_array($listedStatus, ['cancelled', 'test'], true)): ?>
              <details class="panel-order-quick"><summary>Potvrdit platbu</summary>
                <form method="post" action="<?= $escape($listedQuickUrl) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="mark-order-paid">
                  <input type="hidden" name="id" value="<?= $listedId ?>"><input type="hidden" name="return_list" value="1">
                  <label><input type="checkbox" name="bank_checked" value="1" required> Platbu jsem ověřil ve výpisu banky.</label>
                  <button type="submit">Označit jako zaplacené</button>
                </form>
              </details>
            <?php endif; ?>
            <?php if ($listedNext !== []): ?>
              <form class="panel-order-quick-status" method="post" action="<?= $escape($listedQuickUrl) ?>">
                <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="set-order-status">
                <input type="hidden" name="id" value="<?= $listedId ?>"><input type="hidden" name="return_list" value="1">
                <input type="hidden" name="fulfillment_source" value="<?= $escape($listedSource) ?>">
                <input type="hidden" name="fulfillment_note" value="<?= $escape($listed['fulfillment_note'] ?? '') ?>">
                <label for="order-quick-<?= $listedId ?>">Nový stav objednávky <?= $escape($listed['order_number'] ?? '') ?></label>
                <select id="order-quick-<?= $listedId ?>" name="order_status">
                  <?php foreach ($listedNext as $next): ?><option value="<?= $escape($next) ?>"><?= $escape($orderFulfillmentLabel($next)) ?></option><?php endforeach; ?>
                </select>
                <button type="submit">Uložit stav</button>
              </form>
            <?php elseif ($listedOverdue): ?>
              <details class="panel-order-quick"><summary>Stornovat po splatnosti</summary>
                <form method="post" action="<?= $escape($listedQuickUrl) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="cancel-overdue-bank-order">
                  <input type="hidden" name="id" value="<?= $listedId ?>">
                  <label><input type="checkbox" name="bank_checked" value="1" required> Ověřil/a jsem v bankovním výpisu, že platba nedorazila.</label>
                  <button type="submit">Stornovat a uvolnit sklad</button>
                </form>
              </details>
            <?php elseif ($listedStatus === 'new' && !$listedPaid && ($listed['payment_status'] ?? '') === 'pending'): ?>
              <details class="panel-order-quick"><summary>Stornovat</summary>
                <form method="post" action="<?= $escape($listedQuickUrl) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="set-order-status">
                  <input type="hidden" name="id" value="<?= $listedId ?>"><input type="hidden" name="return_list" value="1">
                  <input type="hidden" name="order_status" value="cancelled">
                  <input type="hidden" name="fulfillment_source" value="<?= $escape($listedSource) ?>">
                  <input type="hidden" name="fulfillment_note" value="<?= $escape($listed['fulfillment_note'] ?? '') ?>">
                  <?php if (in_array(($listed['payment_method'] ?? ''), ['comgate', 'gopay', 'btcpay'], true)): ?><p>Před stornem ověř, že všechny platby u brány jsou zrušené nebo zamítnuté. Aktivní transakci nelze stornovat.</p><?php endif; ?>
                  <label><input type="checkbox" required> Potvrzuji storno objednávky.</label>
                  <button type="submit">Stornovat</button>
                </form>
              </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div><?php endif; ?>
    <?php if ($ordersPreviousUrl !== '' || $ordersNextUrl !== ''): ?>
      <nav class="panel-quick panel-order-pages" aria-label="Stránky objednávek">
        <?php if ($ordersPreviousUrl !== ''): ?><a href="<?= $escape($ordersPreviousUrl) ?>">← Předchozí</a><?php endif; ?>
        <?php if ($ordersNextUrl !== ''): ?><a href="<?= $escape($ordersNextUrl) ?>">Další →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>
