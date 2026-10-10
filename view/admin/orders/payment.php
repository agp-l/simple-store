    <aside class="panel-panel panel-order-payment">
      <h2>Platba</h2>
      <p class="panel-order-state <?= $paymentHighlight ? 'is-paid' : 'is-pending' ?>"><?= $escape($paymentDisplayLabel) ?></p>
      <dl class="panel-order-facts">
        <div><dt>Metoda</dt><dd><?= $bankTransfer ? 'Bankovní převod' : ($onlineGateway ? $gatewayName : $escape($order['payment_method'] ?? 'Neuvedeno')) ?></dd></div>
        <div><dt>Částka</dt><dd><strong><?= $orderMoney($order['total_czk'] ?? 0) ?></strong></dd></div>
        <?php if ($bankTransfer): ?><div><dt>Variabilní symbol</dt><dd><strong><?= $escape($order['variable_symbol'] ?? 'Neuveden') ?></strong></dd></div><?php endif; ?>
        <?php if ($onlineGateway && !empty($order['provider_reference'])): ?><div><dt><?= $btcpayPayment ? 'Faktura' : 'Transakce' ?> <?= $gatewayName ?></dt><dd><strong><?= $escape($order['provider_reference']) ?></strong></dd></div><?php endif; ?>
        <?php if ($goPayPayment && $goPayState !== null): ?>
          <div><dt>Stav u GoPay</dt><dd><?= $escape($goPayStatusLabel($goPayState['status'] ?? '')) ?><?= (int) ($goPayState['test_mode'] ?? 0) === 1 ? ' · testovací' : '' ?></dd></div>
          <?php if (empty($order['provider_reference']) && !empty($goPayState['payment_id'])): ?><div><dt>ID platby GoPay</dt><dd><?= $escape($goPayState['payment_id']) ?></dd></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($btcpayPayment && $btcpayState !== null): ?>
          <div><dt>Stav u BTCPay</dt><dd><?= $escape($btcpayStatusLabel($btcpayState['status'] ?? '')) ?></dd></div>
          <?php if (empty($order['provider_reference']) && !empty($btcpayState['invoice_id'])): ?><div><dt>ID faktury BTCPay</dt><dd><?= $escape($btcpayState['invoice_id']) ?></dd></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($paid && !empty($order['payment_paid_at'])): ?><div><dt><?= $onlineGateway ? 'Potvrzeno bránou' : 'Ověřeno' ?></dt><dd><?= $escape($order['payment_paid_at']) ?><?php if (!empty($order['payment_verified_by'])): ?> · správce #<?= (int) $order['payment_verified_by'] ?><?php endif; ?></dd></div><?php endif; ?>
        <?php if ($bankTransfer): ?><div><dt>Účet</dt><dd><?= $escape($payment['account_display'] ?? 'Neuveden') ?></dd></div><?php endif; ?>
        <?php if ($bankTransfer && !empty($payment['iban'])): ?><div><dt>IBAN</dt><dd><?= $escape($payment['iban']) ?></dd></div><?php endif; ?>
        <?php if ($bankTransfer && !empty($order['payment_due_at'])): ?><div><dt>Splatnost</dt><dd><?= $escape($order['payment_due_at']) ?> UTC<?= $orderOverdue ? ' · Po splatnosti' : '' ?></dd></div><?php endif; ?>
      </dl>
      <div class="panel-order-invoice">
        <strong>Faktura</strong>
        <?php if ($orderInvoice !== null): ?>
          <span>Číslo <?= $escape($orderInvoice['document_number']) ?></span>
          <div class="panel-order-head-actions"><a class="panel-button" href="<?= $escape($invoiceDetailUrl . '&print=1') ?>" target="_blank" rel="noopener noreferrer">Zobrazit fakturu ↗</a><a class="panel-text-link" href="<?= $escape($invoiceDetailUrl) ?>">Detail a odeslání</a></div>
        <?php else: ?><span>Dosud nevystavena</span><?php endif; ?>
      </div>
      <?php if ($goPayPayment && $goPayState !== null && in_array($goPayState['status'] ?? '', ['creating', 'uncertain'], true)): ?>
        <p class="panel-error" role="alert">Založení platby má nejasný výsledek. Neopakuj požadavek naslepo; nejprve vyhledej transakci v administraci GoPay podle čísla objednávky.</p>
      <?php endif; ?>
      <?php if ($btcpayPayment && $btcpayState !== null && in_array($btcpayState['status'] ?? '', ['creating', 'uncertain'], true)): ?>
        <p class="panel-error" role="alert">Založení faktury má nejasný výsledek. Před dalším pokusem ji vyhledej v BTCPay Serveru podle čísla objednávky.</p>
      <?php endif; ?>
      <?php if ($bankTransfer && !$paid && ($order['payment_status'] ?? '') === 'pending' && !in_array($order['status'], ['cancelled', 'test'], true)): ?>
        <form class="panel-order-confirm" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
          <input type="hidden" name="action" value="mark-order-paid">
          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
          <label><input type="checkbox" name="bank_checked" value="1" required> Ověřil/a jsem na bankovním výpisu částku a variabilní symbol této objednávky.</label>
          <button class="panel-button" type="submit">Označit platbu jako přijatou</button>
        </form>
      <?php endif; ?>
      <?php if ($onlineGateway && !empty($order['provider_reference'])): ?>
        <?php if ($gatewayConfigured): ?>
          <form class="panel-order-confirm" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
            <input type="hidden" name="action" value="<?= $btcpayPayment ? 'btcpay-refresh' : ($goPayPayment ? 'gopay-refresh' : 'comgate-refresh') ?>">
            <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
            <button class="panel-button" type="submit">Ověřit stav u <?= $gatewayName ?></button>
          </form>
        <?php else: ?><p class="panel-help">Pro opětovné ověření stavu u <?= $gatewayName ?> vyplň přihlašovací údaje v <a href="<?= $escape($adminUrl . '?section=settings') ?>">nastavení obchodu</a>.</p><?php endif; ?>
      <?php endif; ?>
      <?php if ($bankTransfer && $paid && $orderControlsReady): ?>
        <details class="panel-order-accordion panel-order-controls" aria-label="Oprava platby">
          <summary>Opravit chybně potvrzenou platbu</summary>
          <p class="panel-help">Vrátí stav na „Čeká na platbu“. Bankovní pohyb se tím nemění. Původní potvrzení, částka a důvod zůstanou v účetních zásazích.</p>
          <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-payment"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="confirmation" value="not_received">
            <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required placeholder="Například platba označena jako přijatá omylem"></textarea></label>
            <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Ověřil/a jsem výpis a opravuji ručně potvrzený stav platby.</label>
            <button class="panel-button" type="submit">Vrátit platbu na čekající</button>
          </form>
        </details>
      <?php endif; ?>
      <?php if ($bankTransfer): ?><p class="panel-help"><?= !empty($fioConfigured) ? 'Převody na propojený účet Fio lze ověřit tlačítkem v přehledu objednávek. Pravidelnou kontrolu lze spouštět z cronu; jiné účty kontroluj ručně.' : 'Platbu zkontroluj ve výpisu banky, nebo zapni ověřování Fio v nastavení plateb.' ?></p><?php endif; ?>
      <?php if ($onlineGateway): ?><p class="panel-help">Stav platby potvrzuje <?= $gatewayName ?>. Samotný návrat zákazníka na web platbu nepotvrzuje.</p><?php endif; ?>
      <?php if (($bankTransfer || $onlineGateway) && $paid && $orderTaxReady && ($order['status'] ?? '') !== 'test'): ?>
        <details class="panel-order-accordion panel-order-controls" aria-label="Daňová evidence objednávky">
          <summary>Daňová evidence a vystavení faktury</summary>
          <?php if ($bankTransfer): ?>
          <?php if ($orderReceipt !== null): ?>
            <p class="panel-notice">Příjem <?= $orderMoney($orderReceipt['amount_czk']) ?> ze dne <?= $escape($orderReceipt['entry_date']) ?> je zapsaný v <a href="<?= $escape($adminUrl . '?section=accounting&tab=money') ?>">peněžním deníku</a>.</p>
          <?php else: ?>
            <p class="panel-help">Zaplaceno je stav objednávky. Do deníku zapiš skutečné datum připsání částky podle bankovního výpisu.</p>
            <form class="panel-form" method="post" action="<?= $escape($adminUrl . '?section=accounting') ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="tax-link-payment"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
              <label>Datum připsání<input type="date" name="entry_date" required></label>
              <label>Bankovní reference<input name="reference" maxlength="100" value="<?= $escape($order['variable_symbol'] ?? '') ?>"></label>
              <button class="panel-button" type="submit">Zapsat příjem <?= $orderMoney($order['total_czk']) ?></button>
            </form>
          <?php endif; ?>
          <?php elseif ($btcpayPayment): ?>
            <p class="panel-help">BTCPay potvrdil úhradu zákazníka. Příjem bitcoinu a jeho hodnotu v Kč zapiš do <a href="<?= $escape($adminUrl . '?section=accounting&tab=money') ?>">peněžního deníku</a> podle skutečných podkladů.</p>
          <?php else: ?>
            <p class="panel-help"><?= $gatewayName ?> potvrdil úhradu zákazníka. Výplatu a poplatky zaznamenej v <a href="<?= $escape($adminUrl . '?section=accounting&tab=money') ?>">peněžním deníku</a> podle skutečného vyúčtování brány a bankovního výpisu; mohou zahrnovat více objednávek.</p>
          <?php endif; ?>
          <?php if ($orderInvoice !== null): ?>
            <p>Faktura <strong><?= $escape($orderInvoice['document_number']) ?></strong> · <a href="<?= $escape($invoiceDetailUrl) ?>">detail, tisk a e-mail</a></p>
          <?php elseif ($gatewayDispatchBlocked): ?>
            <p class="panel-help">Před vystavením faktury nejprve ověř u <?= $gatewayName ?> její aktuální stav a případné vrácení platby.</p>
          <?php elseif ($orderInvoiceReady && \SimpleStore\Accounting\TaxEvidenceRepository::invoiceReady($sellerSettings)): ?>
            <h3>Vystavit fakturu</h3>
            <form class="panel-form" method="post" action="<?= $escape($adminUrl . '?section=accounting') ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="invoice-issue"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
              <label>Odběratel<input name="buyer_name" value="<?= $escape($shipping['recipient'] ?? $shipping['name'] ?? '') ?>" maxlength="120" required></label>
              <label>Ulice a číslo<input name="buyer_street" value="<?= $escape($shipping['street'] ?? '') ?>" maxlength="160"></label>
              <label>Město<input name="buyer_city" value="<?= $escape($shipping['city'] ?? '') ?>" maxlength="100"></label>
              <label>PSČ<input name="buyer_postal_code" value="<?= $escape($shipping['postal_code'] ?? '') ?>" maxlength="6"></label>
              <label>IČO odběratele (volitelné)<input name="buyer_ico" maxlength="8"></label>
              <button class="panel-button" type="submit">Vystavit a připravit e-mail s fakturou</button>
              <p class="panel-help">Zkontroluj fakturační údaje. Tiskový doklad lze uložit jako PDF v prohlížeči.</p>
            </form>
          <?php else: ?><p class="panel-help">Před vystavením faktury doplň <a href="<?= $escape($adminUrl . '?section=accounting&tab=settings') ?>">údaje OSVČ</a> a aktualizuj SQL tabulky.</p><?php endif; ?>
        </details>
      <?php endif; ?>
      <?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Stav vyřízení objednávky byl uložen.</p><?php endif; ?>
      <?php require __DIR__ . "/fulfillment.php"; ?>
    </aside>
