<?php
declare(strict_types=1);

$orderMoney = static fn (mixed $amount): string => number_format((int) $amount, 0, ',', ' ') . ' Kč';
$orderPaymentLabel = static fn (mixed $status): string => match ($status) {
    'paid' => 'Zaplaceno',
    'pending' => 'Čeká na platbu',
    'test' => 'Testovací objednávka',
    default => 'Stav platby: ' . (string) $status,
};
$packetaReady ??= false;
$packetaConfigured ??= false;
$packetaShipment ??= null;
$packetaAction ??= '';
?>
<div class="panel-intro">
  <div><p class="panel-eyebrow">Prodej</p><h1>Objednávky</h1>
    <p>Přehled přijatých objednávek. Převod označ jako zaplacený až po ověření částky a variabilního symbolu ve výpisu banky.</p></div>
  <?php if ($order !== null): ?><div class="panel-quick"><a href="<?= $escape($orderBaseUrl) ?>">← Všechny objednávky</a></div><?php endif; ?>
</div>
<?php if (!$ordersReady): ?>
  <p class="panel-error" role="alert">Pro objednávky nejdřív <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
<?php endif; ?>
<?php if ($orderError !== ''): ?><p class="panel-error" role="alert"><?= $escape($orderError) ?></p><?php endif; ?>
<?php if ($order !== null): ?>
  <?php
  $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
  $payment = is_array($order['payment_details'] ?? null) ? $order['payment_details'] : [];
  $bankTransfer = ($order['payment_method'] ?? '') === 'bank_transfer';
  $paid = ($order['payment_status'] ?? '') === 'paid';
  ?>
  <?php if (($_GET['paid'] ?? null) === '1' && $paid): ?><p class="panel-notice" role="status">Platba byla ručně označena jako přijatá.</p><?php endif; ?>
  <div class="panel-grid panel-order-detail">
    <div class="panel-workspace">
      <section class="panel-panel">
        <div class="panel-panel-head"><h2>Objednávka <?= $escape($order['order_number'] ?? '') ?></h2>
          <span class="panel-order-state <?= $paid ? 'is-paid' : 'is-pending' ?>"><?= $escape($orderPaymentLabel($order['payment_status'] ?? '')) ?></span></div>
        <p class="panel-help">Přijato <?= $escape($order['created_at'] ?? '') ?></p>
        <div class="panel-order-lines">
          <?php foreach (($order['items'] ?? []) as $item): ?>
            <?php if (!is_array($item)): continue; endif; ?>
            <div class="panel-order-line">
              <div><strong><?= $escape($item['name'] ?? '') ?></strong>
                <small><?= (int) ($item['quantity'] ?? 0) ?> ks × <?= $orderMoney($item['unit_price_czk'] ?? 0) ?>
                <?php foreach (($item['options'] ?? []) as $option => $value): ?>
                  <?php if (is_scalar($value)): ?> · <?= $escape($option) ?>: <?= $escape($value) ?><?php endif; ?>
                <?php endforeach; ?></small></div>
              <strong><?= $orderMoney((int) ($item['quantity'] ?? 0) * (int) ($item['unit_price_czk'] ?? 0)) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
        <dl class="panel-order-totals">
          <div><dt>Produkty</dt><dd><?= $orderMoney($order['subtotal_czk'] ?? 0) ?></dd></div>
          <div><dt>Doprava</dt><dd><?= $orderMoney($order['shipping_czk'] ?? 0) ?></dd></div>
          <div><dt>Celkem</dt><dd><?= $orderMoney($order['total_czk'] ?? 0) ?></dd></div>
        </dl>
      </section>
      <section class="panel-panel">
        <h2>Doručení a kontakt</h2>
        <dl class="panel-order-facts">
          <div><dt>Doprava</dt><dd><?= $escape($shipping['label'] ?? $shipping['method'] ?? 'Neuvedeno') ?></dd></div>
          <div><dt>Příjemce</dt><dd><?= $escape($shipping['recipient'] ?? $shipping['name'] ?? 'Neuvedeno') ?></dd></div>
          <div><dt>E-mail</dt><dd><?= $escape($order['customer_email'] ?? $shipping['email'] ?? 'Neuvedeno') ?></dd></div>
          <div><dt>Telefon</dt><dd><?= $escape($shipping['phone'] ?? 'Neuvedeno') ?></dd></div>
          <?php if (!empty($shipping['pickup_point'])): ?><div><dt>Výdejní místo</dt><dd><?= $escape($shipping['pickup_point']) ?><br><?= $escape($shipping['pickup_address'] ?? '') ?><?php if (!empty($shipping['pickup_code'])): ?><br>Kód: <?= $escape($shipping['pickup_code']) ?><?php endif; ?></dd></div>
          <?php else: ?><div><dt>Adresa</dt><dd><?= $escape($shipping['street'] ?? '') ?><br><?= $escape(trim((string) ($shipping['postal_code'] ?? '') . ' ' . (string) ($shipping['city'] ?? ''))) ?><br><?= $escape($shipping['country'] ?? 'CZ') ?></dd></div><?php endif; ?>
        </dl>
        <?php if (in_array($shipping['method'] ?? '', ['zasilkovna_pickup', 'zasilkovna_home'], true)): ?>
          <div class="panel-packeta-dispatch">
            <h3>Podání zásilky Zásilkovně</h3>
            <?php if (!$packetaReady): ?>
              <p class="panel-help">Nejdřív <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>, aby vznikla tabulka zásilek.</p>
            <?php elseif (!$packetaConfigured): ?>
              <p class="panel-help">V <a href="<?= $escape($adminUrl . '?section=settings') ?>">nastavení obchodu</a> vyplň soukromé API heslo a označení odesílatele z klientské sekce Zásilkovny.</p>
            <?php endif; ?>
            <?php if (($_GET['packeta_saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Údaje zásilky byly uloženy. Stav odeslání objednávky se nastavuje zvlášť po předání balíku.</p><?php endif; ?>
            <?php if ($packetaShipment !== null && $packetaShipment['status'] === 'created'): ?>
              <?php $submittedPacket = json_decode((string) ($packetaShipment['submitted_json'] ?? ''), true); ?>
              <p class="panel-help">Zásilka je vytvořená v systému Zásilkovny. Po zabalení označ balík čitelným číslem nebo na něj nalep štítek.</p>
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
              <p class="panel-help">Samotné vytvoření čísla ještě neznamená, že je balík fyzicky odeslaný.</p>
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
              <?php if ($packetaShipment !== null && $packetaShipment['status'] === 'rejected'): ?><p class="panel-error" role="alert"><?= $escape($packetaShipment['last_error'] ?: 'Zásilkovna zásilku odmítla.') ?></p><?php endif; ?>
              <?php if (!$paid): ?><p class="panel-help">Nejdřív ověř platbu na bankovním výpisu a označ ji jako přijatou.</p><?php endif; ?>
              <?php if (($shipping['method'] ?? '') === 'zasilkovna_pickup' &&
                  (($shipping['pickup_verified'] ?? false) !== true ||
                  preg_match('/^[0-9]{1,12}$/D', (string) ($shipping['pickup_code'] ?? '')) !== 1)): ?>
                <p class="panel-help">U této starší objednávky nebylo výdejní místo ověřeno přes widget. Před podáním ho ověř v klientské sekci Zásilkovny; automatické podání této objednávky není dostupné.</p>
              <?php elseif ($packetaReady && $packetaConfigured && $paid && !in_array($order['status'], ['cancelled', 'completed', 'test'], true)): ?>
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
                    <p class="panel-help">Výdejní místo: <?= $escape($shipping['pickup_point'] ?? '') ?> (ID <?= $escape($shipping['pickup_code'] ?? '') ?>).</p>
                  <?php endif; ?>
                  <button class="panel-button" type="submit">Vytvořit zásilku u Zásilkovny</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
    <aside class="panel-panel panel-order-payment">
      <h2>Platba</h2>
      <p class="panel-order-state <?= $paid ? 'is-paid' : 'is-pending' ?>"><?= $escape($orderPaymentLabel($order['payment_status'] ?? '')) ?></p>
      <dl class="panel-order-facts">
        <div><dt>Metoda</dt><dd><?= $bankTransfer ? 'Bankovní převod' : $escape($order['payment_method'] ?? 'Neuvedeno') ?></dd></div>
        <div><dt>Částka</dt><dd><strong><?= $orderMoney($order['total_czk'] ?? 0) ?></strong></dd></div>
        <?php if ($bankTransfer): ?><div><dt>Variabilní symbol</dt><dd><strong><?= $escape($order['variable_symbol'] ?? 'Neuveden') ?></strong></dd></div><?php endif; ?>
        <?php if ($paid && !empty($order['payment_paid_at'])): ?><div><dt>Ověřeno</dt><dd><?= $escape($order['payment_paid_at']) ?><?php if (!empty($order['payment_verified_by'])): ?> · správce #<?= (int) $order['payment_verified_by'] ?><?php endif; ?></dd></div><?php endif; ?>
        <?php if ($bankTransfer): ?><div><dt>Účet</dt><dd><?= $escape($payment['account_display'] ?? 'Neuveden') ?></dd></div><?php endif; ?>
        <?php if (!empty($payment['iban'])): ?><div><dt>IBAN</dt><dd><?= $escape($payment['iban']) ?></dd></div><?php endif; ?>
        <?php if (!empty($order['payment_due_at'])): ?><div><dt>Splatnost</dt><dd><?= $escape($order['payment_due_at']) ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($bankTransfer && !$paid && ($order['payment_status'] ?? '') === 'pending'): ?>
        <form class="panel-order-confirm" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
          <input type="hidden" name="action" value="mark-order-paid">
          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
          <label><input type="checkbox" name="bank_checked" value="1" required> Ověřil/a jsem na bankovním výpisu částku a variabilní symbol této objednávky.</label>
          <button class="panel-button" type="submit">Označit platbu jako přijatou</button>
        </form>
      <?php endif; ?>
      <p class="panel-help">Stav platby se z banky nenačítá automaticky.</p>
      <?php if (!in_array($order['status'], ['completed', 'cancelled', 'test'], true)): ?>
      <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="set-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
        <label>Vyřízení objednávky <select name="order_status">
          <?php if ($paid): ?>
            <option value="processing" <?= $order['status'] === 'processing' ? 'selected' : '' ?>>Připravuje se</option>
            <option value="shipped" <?= $order['status'] === 'shipped' ? 'selected' : '' ?>>Odesláno</option>
            <option value="completed">Dokončeno</option>
          <?php else: ?><option value="cancelled">Zrušeno (bez přijaté platby)</option><?php endif; ?>
        </select></label>
        <button class="panel-button" type="submit">Uložit stav</button>
        <p class="panel-help">Odesláno vyber až po skutečném odeslání zásilky. Dokončené a zrušené objednávky se zákazníkovi přesunou do historie.</p>
      </form>
      <?php endif; ?>
    </aside>
  </div>
<?php elseif ($ordersReady): ?>
  <nav class="panel-quick panel-order-filters" aria-label="Stav platby">
    <?php foreach (['all' => 'Všechny', 'pending' => 'Čeká na platbu', 'paid' => 'Zaplaceno', 'test' => 'Testovací'] as $filter => $label): ?>
      <a href="<?= $escape($orderBaseUrl . '&status=' . $filter) ?>" <?= $statusFilter === $filter ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <section class="panel-panel" aria-labelledby="panel-orders-list">
    <h2 id="panel-orders-list">Přijaté objednávky <span><?= count($orderPage['items']) ?> na stránce</span></h2>
    <?php if ($orderPage['items'] === []): ?><p class="panel-empty">V tomto přehledu zatím nejsou objednávky.</p><?php endif; ?>
    <div class="panel-order-list">
      <?php foreach ($orderPage['items'] as $listed): ?>
        <?php $listedPaid = ($listed['payment_status'] ?? '') === 'paid'; ?>
        <a class="panel-order-row" href="<?= $escape($orderBaseUrl . '&id=' . (int) $listed['id']) ?>">
          <span><strong><?= $escape($listed['order_number'] ?? '') ?></strong><small><?= $escape($listed['created_at'] ?? '') ?> · <?= $escape($listed['customer_email'] ?? '') ?></small></span>
          <span class="panel-order-symbol"><?= ($listed['payment_method'] ?? '') === 'test' ? 'TEST' : 'VS ' . $escape($listed['variable_symbol'] ?? '–') ?></span>
          <strong><?= $orderMoney($listed['total_czk'] ?? 0) ?></strong>
          <span class="panel-order-state <?= $listedPaid ? 'is-paid' : 'is-pending' ?>"><?= $escape($orderPaymentLabel($listed['payment_status'] ?? '')) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($ordersPreviousUrl !== '' || $ordersNextUrl !== ''): ?>
      <nav class="panel-quick panel-order-pages" aria-label="Stránky objednávek">
        <?php if ($ordersPreviousUrl !== ''): ?><a href="<?= $escape($ordersPreviousUrl) ?>">← Předchozí</a><?php endif; ?>
        <?php if ($ordersNextUrl !== ''): ?><a href="<?= $escape($ordersNextUrl) ?>">Další →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>
<?php endif; ?>
