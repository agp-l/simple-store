<?php
declare(strict_types=1);

$orderMoney = static fn (mixed $amount): string => number_format((int) $amount, 0, ',', ' ') . ' Kč';
$orderPaymentLabel = static fn (mixed $status): string => match ($status) {
    'paid' => 'Zaplaceno',
    'pending' => 'Čeká na platbu',
    'test' => 'Testovací objednávka',
    default => 'Stav platby: ' . (string) $status,
};
$orderFulfillmentLabel = static fn (mixed $status): string => match ($status) {
    'processing' => 'Připravuje se', 'ready_to_ship' => 'Připraveno k odeslání',
    'shipped' => 'Odesláno', 'completed' => 'Dokončeno', 'cancelled' => 'Zrušeno',
    'test' => 'Testovací', default => 'Nová objednávka',
};
$packetaReady ??= false;
$packetaConfigured ??= false;
$packetaCancelReady ??= false;
$packetaShipment ??= null;
$packetaTrackingUrl ??= null;
$cancelledPackets ??= [];
$packetaAction ??= '';
$carrierReady ??= false;
$carrierShipment ??= null;
$carrierAction ??= '';
$fulfillmentSourceReady ??= false;
$orderControlsReady ??= false;
$orderEvents ??= [];
$deletedOrders ??= [];
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
<?php if ($order === null && ($_GET['deleted'] ?? '') === '1'): ?><p class="panel-notice" role="status">Objednávka byla smazána. Záznam o zásahu správce zůstal v databázi.</p><?php endif; ?>
<?php if ($order !== null): ?>
  <?php
  $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
  $payment = is_array($order['payment_details'] ?? null) ? $order['payment_details'] : [];
  $bankTransfer = ($order['payment_method'] ?? '') === 'bank_transfer';
  $paid = ($order['payment_status'] ?? '') === 'paid';
  ?>
  <?php if (($_GET['paid'] ?? null) === '1' && $paid): ?><p class="panel-notice" role="status">Platba byla ručně označena jako přijatá.</p><?php endif; ?>
  <?php if (($_GET['corrected'] ?? null) === '1'): ?><p class="panel-notice" role="status">Stav byl opraven. Důvod a původní stav jsou v historii zásahů níže.</p><?php endif; ?>
  <div class="panel-grid panel-order-detail">
    <div class="panel-workspace">
      <section class="panel-panel">
        <div class="panel-panel-head"><h2>Objednávka <?= $escape($order['order_number'] ?? '') ?></h2>
          <span class="panel-order-state <?= $paid ? 'is-paid' : 'is-pending' ?>"><?= $escape($orderPaymentLabel($order['payment_status'] ?? '')) ?> · <?= $escape($orderFulfillmentLabel($order['status'] ?? '')) ?></span></div>
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
          <div><dt>Expeduje</dt><dd><?= ($order['fulfillment_source'] ?? 'own') === 'external' ? 'Externí dodavatel' : 'Obchod' ?><?php if (!empty($order['fulfillment_note'])): ?><br><?= $escape($order['fulfillment_note']) ?><?php endif; ?></dd></div>
          <div><dt>Příjemce</dt><dd><?= $escape($shipping['recipient'] ?? $shipping['name'] ?? 'Neuvedeno') ?></dd></div>
          <div><dt>E-mail</dt><dd><?= $escape($order['customer_email'] ?? $shipping['email'] ?? 'Neuvedeno') ?></dd></div>
          <div><dt>Telefon</dt><dd><?= $escape($shipping['phone'] ?? 'Neuvedeno') ?></dd></div>
          <?php if (!empty($shipping['pickup_point'])): ?><div><dt>Výdejní místo</dt><dd><?= $escape($shipping['pickup_point']) ?><br><?= $escape($shipping['pickup_address'] ?? '') ?><?php if (!empty($shipping['pickup_code'])): ?><br>Kód: <?= $escape($shipping['pickup_code']) ?><?php endif; ?><?php if (($shipping['method'] ?? '') === 'balikovna_pickup' && !empty($shipping['pickup_postal_code'])): ?><br>PSČ Balíkovny: <?= $escape($shipping['pickup_postal_code']) ?><?php endif; ?></dd></div>
          <?php else: ?><div><dt>Adresa</dt><dd><?= $escape($shipping['street'] ?? '') ?><br><?= $escape(trim((string) ($shipping['postal_code'] ?? '') . ' ' . (string) ($shipping['city'] ?? ''))) ?><br><?= $escape($shipping['country'] ?? 'CZ') ?></dd></div><?php endif; ?>
        </dl>
        <?php if (in_array($shipping['method'] ?? '', ['zasilkovna_pickup', 'zasilkovna_home'], true)): ?>
          <div class="panel-packeta-dispatch">
            <h3>Podání zásilky Zásilkovně</h3>
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
              <?php if (!$paid): ?><p class="panel-help">Nejdřív ověř platbu na bankovním výpisu a označ ji jako přijatou.</p><?php endif; ?>
              <?php if (($order['fulfillment_source'] ?? 'own') === 'external'): ?><p class="panel-help">Expedici zajišťuje externí dodavatel. Stav objednávky nastav v panelu Vyřízení; zásilku tímto účtem Zásilkovny nepodávej.</p><?php endif; ?>
              <?php if (($order['fulfillment_source'] ?? 'own') !== 'external' && ($shipping['method'] ?? '') === 'zasilkovna_pickup' &&
                  (($shipping['pickup_verified'] ?? false) !== true ||
                  preg_match('/^[0-9]{1,12}$/D', (string) ($shipping['pickup_code'] ?? '')) !== 1)): ?>
                <p class="panel-help">U této starší objednávky nebylo výdejní místo ověřeno. Zadej správné ID; před podáním ho server ověří přes Zásilkovnu.</p>
              <?php endif; ?>
              <?php if ($packetaReady && $packetaConfigured && $paid && ($order['fulfillment_source'] ?? 'own') !== 'external' && !in_array($order['status'], ['shipped', 'cancelled', 'completed', 'test'], true)): ?>
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
                    <label>ID výdejního místa Zásilkovny<input name="pickup_point_id" value="<?= $escape($entered['pickup_point_id'] ?? $shipping['pickup_code'] ?? '') ?>" maxlength="80" required></label>
                    <p class="panel-help">Původní místo: <?= $escape($shipping['pickup_point'] ?? '') ?> (ID <?= $escape($shipping['pickup_code'] ?? '') ?>). Pokud se místo mění, zadej ID nového místa z klientské sekce Zásilkovny. Před podáním ho server ověří; původní objednávka se nemění.</p>
                  <?php endif; ?>
                  <button class="panel-button" type="submit">Vytvořit zásilku u Zásilkovny</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($cancelledPackets !== []): ?>
              <p class="panel-help">Dříve stornované zásilky: <?php foreach ($cancelledPackets as $old): ?><?= $escape($old['barcode']) ?> (<?= $escape($old['cancelled_at']) ?>) <?php endforeach; ?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if (in_array($shipping['method'] ?? '', ['balikovna_pickup', 'gls_pickup', 'gls_home'], true)): ?>
          <div class="panel-packeta-dispatch">
            <h3>Podklady k podání <?= ($shipping['method'] ?? '') === 'balikovna_pickup' ? 'Balíkovně' : 'GLS' ?></h3>
            <?php if (!$carrierReady): ?>
              <p class="panel-help">Nejdřív <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
            <?php else: ?>
              <?php if (($_GET['carrier_saved'] ?? '') === 'carrier-save'): ?><p class="panel-notice" role="status">Podklady byly uloženy. Zásilka ještě nevznikla u dopravce.</p><?php endif; ?>
              <?php if (($_GET['carrier_saved'] ?? '') === 'carrier-register'): ?><p class="panel-notice" role="status">Číslo zásilky od dopravce bylo uloženo. Objednávku označ jako odeslanou až po předání balíku.</p><?php endif; ?>
              <?php if ($carrierShipment !== null): ?>
                <p class="panel-order-state"><?= $carrierShipment['status'] === 'draft' ? 'Podklady připraveny · čeká na import' : 'Číslo dopravce zapsáno · čeká na předání' ?></p>
                <?php if ($carrierShipment['status'] === 'registered'): ?>
                  <dl class="panel-order-facts"><div><dt>Číslo zásilky</dt><dd><strong><?= $escape($carrierShipment['tracking_number']) ?></strong></dd></div></dl>
                <?php endif; ?>
                <p><a class="panel-button" href="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id'] . '&carrier_csv=1') ?>">Stáhnout CSV s podklady</a></p>
              <?php endif; ?>
              <?php if (($shipping['method'] ?? '') === 'balikovna_pickup'): ?>
                <p class="panel-help">CSV pro vlastní konfiguraci importu v Podání Online: vyber službu NB, jako cílové PSČ mapuj <strong>ID Balíkovny <?= $escape($shipping['pickup_code'] ?? '') ?></strong>, nikoliv fyzické PSČ <?= $escape($shipping['pickup_postal_code'] ?? '') ?>. Nastav odesílatele, podací místo a kódování UTF-8 v portálu. Před potvrzením zkontroluj náhled a přidělené číslo zásilky.</p>
              <?php else: ?>
                <p class="panel-help">CSV pro vlastní profil importu GLS Online / MyGLS. U výdejního místa mapuj sloupec Services jako <strong>PSD(ID místa)</strong>, adresu místa jako doručovací adresu a zákazníka jako kontaktní osobu. Vyber v portálu svůj účet a zkontroluj náhled; číslo a štítek vzniknou až tam.</p>
              <?php endif; ?>
              <?php if ($carrierShipment === null || $carrierShipment['status'] === 'draft'): ?>
                <?php if (!$paid || ($order['fulfillment_source'] ?? 'own') !== 'own' || in_array($order['status'], ['shipped', 'completed', 'cancelled', 'test'], true)): ?>
                  <p class="panel-help">Podklady lze připravovat jen pro zaplacenou aktivní objednávku expedovanou obchodem. Externí dodavatel podává sám.</p>
                <?php else: ?>
                  <?php
                    $addressDefaults = \SimpleStore\Checkout\CarrierShipmentDraft::addressDefaults($shipping);
                    $savedDraft = is_array($carrierShipment['draft'] ?? null) ? $carrierShipment['draft'] : [];
                    $enteredDraft = ($method ?? 'GET') === 'POST' && $carrierAction === 'carrier-save' &&
                        (string) ($_POST['id'] ?? '') === (string) $order['id'] ? $_POST : [];
                    $draftValue = static fn (string $key, string $default): string =>
                        is_string($enteredDraft[$key] ?? null) ? $enteredDraft[$key] :
                        (is_string($savedDraft[$key] ?? null) ? $savedDraft[$key] : $default);
                  ?>
                  <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="carrier-save"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                    <label>Příjemce / kontaktní osoba<input name="recipient" value="<?= $escape($draftValue('recipient', (string) ($shipping['recipient'] ?? $shipping['name'] ?? ''))) ?>" maxlength="140" required></label>
                    <label>E-mail<input type="email" name="email" value="<?= $escape($draftValue('email', (string) ($order['customer_email'] ?? ''))) ?>" maxlength="254" required></label>
                    <label>Telefon<input type="tel" name="phone" value="<?= $escape($draftValue('phone', (string) ($shipping['phone'] ?? ''))) ?>" maxlength="40" required></label>
                    <label>Hmotnost zabalené zásilky v kg<input name="weight_kg" inputmode="decimal" value="<?= $escape($draftValue('weight_kg', '1')) ?>" required></label>
                    <?php if (($shipping['method'] ?? '') === 'balikovna_pickup'): ?>
                      <?php $pickupCity = ''; if (preg_match('/\b[0-9]{3}\s?[0-9]{2}\s+([^,]+)$/u', (string) ($shipping['pickup_address'] ?? ''), $cityMatch)) $pickupCity = trim($cityMatch[1]); ?>
                      <label>Obec vybrané Balíkovny<input name="city" value="<?= $escape($draftValue('city', $pickupCity)) ?>" maxlength="120" required></label>
                      <p class="panel-help">Vybrané místo: <?= $escape($shipping['pickup_point'] ?? '') ?> · <?= $escape($shipping['pickup_address'] ?? '') ?>. Ověř obec podle mapy.</p>
                    <?php else: ?>
                      <p class="panel-help"><?= ($shipping['method'] ?? '') === 'gls_pickup' ? 'Adresu místa GLS zkontroluj podle vybraného bodu v objednávce.' : 'Zkontroluj adresu příjemce.' ?></p>
                      <label>Ulice a číslo<input name="street" value="<?= $escape($draftValue('street', $addressDefaults['street'])) ?>" maxlength="120" required></label>
                      <label>Obec<input name="city" value="<?= $escape($draftValue('city', $addressDefaults['city'])) ?>" maxlength="120" required></label>
                      <label>PSČ<input name="postal_code" value="<?= $escape($draftValue('postal_code', $addressDefaults['postal_code'])) ?>" maxlength="12" required></label>
                    <?php endif; ?>
                    <button class="panel-button" type="submit"><?= $carrierShipment === null ? 'Uložit podklady k podání' : 'Upravit podklady' ?></button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($carrierShipment !== null && $carrierShipment['status'] === 'draft'): ?>
                <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
                  <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="carrier-register"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                  <label>Skutečné číslo zásilky od dopravce<input name="tracking_number" autocomplete="off" minlength="6" maxlength="50" required></label>
                  <label class="panel-check"><input type="checkbox" name="carrier_confirmed" value="1" required> Zkontroloval/a jsem import v portálu dopravce a opisuji číslo skutečně vytvořené zásilky.</label>
                  <button class="panel-button" type="submit">Zapsat číslo dopravce</button>
                </form>
              <?php endif; ?>
              <p class="panel-help">CSV a místní číslo nenahrazují štítek ani potvrzení podání. Balík označ až štítkem nebo kódem od dopravce. Stav vyřízení objednávky nastav zvlášť po skutečném předání.</p>
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
      <?php if ($bankTransfer && !$paid && ($order['payment_status'] ?? '') === 'pending' && !in_array($order['status'], ['cancelled', 'test'], true)): ?>
        <form class="panel-order-confirm" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
          <input type="hidden" name="action" value="mark-order-paid">
          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
          <label><input type="checkbox" name="bank_checked" value="1" required> Ověřil/a jsem na bankovním výpisu částku a variabilní symbol této objednávky.</label>
          <button class="panel-button" type="submit">Označit platbu jako přijatou</button>
        </form>
      <?php endif; ?>
      <p class="panel-help">Stav platby se z banky nenačítá automaticky.</p>
      <?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Stav vyřízení objednávky byl uložen.</p><?php endif; ?>
      <h2>Vyřízení</h2>
      <p class="panel-order-state"><?= $escape($orderFulfillmentLabel($order['status'] ?? '')) ?></p>
      <?php if (!in_array($order['status'], ['completed', 'cancelled', 'test'], true)): ?>
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
              <option value="ready_to_ship" <?= $order['status'] === 'ready_to_ship' ? 'selected' : '' ?>>Připraveno k odeslání</option>
            <?php endif; ?>
            <option value="shipped" <?= $order['status'] === 'shipped' ? 'selected' : '' ?>>Odesláno po předání dopravci</option>
            <?php if ($order['status'] === 'shipped'): ?><option value="completed">Dokončeno po doručení</option><?php endif; ?>
          <?php else: ?><option value="cancelled">Zrušeno (bez přijaté platby)</option><?php endif; ?>
        </select></label>
        <button class="panel-button" type="submit">Uložit stav</button>
        <p class="panel-help">Připraveno znamená zabalenou zásilku, případně potvrzení připravenosti od dodavatele. Odesláno nastav až po skutečném předání dopravci (u dodavatele po jeho potvrzení), dokončeno po doručení. <?= $packetaMethod ? 'Při expedici obchodem přes Zásilkovnu musí být místní zásilka vytvořená. Dodavatel může expedovat bez místního podání.' : '' ?> Dokončené a zrušené objednávky se zákazníkovi přesunou do historie.</p>
        <?php if ($paid && !$fulfillmentSourceReady): ?><p class="panel-help">Pro volbu externího dodavatele <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p><?php endif; ?>
      </form>
      <?php endif; ?>
      <?php if (!$orderControlsReady): ?>
        <p class="panel-help">Pro opravy a mazání objednávek <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
      <?php else: ?>
        <?php if (in_array($order['status'], ['shipped', 'completed'], true)): ?>
          <section class="panel-order-controls" aria-label="Oprava chybného odeslání">
            <h2>Opravit omylem nastavený stav</h2>
            <p class="panel-help">Použij jen když balík ve skutečnosti nebyl předán dopravci. Oprava nemění platbu ani zásilku u dopravce; zůstane zapsána v historii.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="confirmation" value="not_handed">
              <label>Skutečný stav <select name="order_status"><option value="processing">Připravuje se</option><option value="ready_to_ship">Připraveno k odeslání</option></select></label>
              <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required placeholder="Například omylem označeno jako odeslané"></textarea></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Potvrzuji, že balík nebyl předán dopravci.</label>
              <button class="panel-button" type="submit">Opravit chybné odeslání</button>
            </form>
          </section>
        <?php endif; ?>
        <?php if ($order['status'] === 'completed'): ?>
          <section class="panel-order-controls" aria-label="Oprava dokončení">
            <h2>Vrátit z dokončeno na odesláno</h2>
            <p class="panel-help">Když objednávka stále cestuje a doručení bylo potvrzeno omylem.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="order_status" value="shipped"><input type="hidden" name="confirmation" value="not_delivered">
              <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required></textarea></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Potvrzuji, že zásilka ještě nebyla doručena.</label>
              <button class="panel-button" type="submit">Vrátit na odesláno</button>
            </form>
          </section>
        <?php endif; ?>
        <?php if ($order['status'] === 'cancelled' && !$paid): ?>
          <section class="panel-order-controls" aria-label="Obnovení objednávky">
            <h2>Obnovit zrušenou objednávku</h2>
            <p class="panel-help">Zrušení bylo omyl; platba zůstává neověřená.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="correct-order-status"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="order_status" value="new"><input type="hidden" name="confirmation" value="reopen">
              <label>Důvod opravy <textarea name="reason" minlength="8" maxlength="190" required></textarea></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required> Potvrzuji, že chci zrušenou objednávku znovu otevřít.</label>
              <button class="panel-button" type="submit">Obnovit objednávku</button>
            </form>
          </section>
        <?php endif; ?>
        <?php $canOfferDeletion = ($order['status'] === 'test' && ($order['payment_method'] ?? '') === 'test' && ($order['payment_status'] ?? '') === 'test') ||
            ($bankTransfer && !$paid && ($order['payment_status'] ?? '') === 'pending' && in_array($order['status'], ['new', 'cancelled'], true)); ?>
        <?php if ($canOfferDeletion && $packetaShipment === null && $carrierShipment === null && $cancelledPackets === []): ?>
          <section class="panel-order-controls" aria-label="Smazání objednávky">
            <h2>Smazat zkušební nebo nezaplacenou objednávku</h2>
            <p class="panel-help">Trvalé smazání odstraní objednávku i z účtu zákazníka. Nelze ho vrátit. Zásah s důvodem zůstane v administrátorském záznamu; do důvodu nepiš osobní údaje.</p>
            <form class="panel-form" method="post" action="<?= $escape($orderBaseUrl . '&id=' . (int) $order['id']) ?>">
              <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="delete-order"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="confirmation" value="delete">
              <label>Důvod smazání <textarea name="reason" minlength="8" maxlength="190" required placeholder="Například test pokladny"></textarea></label>
              <label>Opiš číslo <?= $escape($order['order_number']) ?><input name="order_number" autocomplete="off" required></label>
              <label class="panel-check"><input type="checkbox" name="verified" value="1" required><?= $bankTransfer ? ' Ověřil/a jsem, že objednávka nebyla uhrazena, a rozumím trvalému smazání.' : ' Rozumím, že jde o trvalé smazání testovací objednávky.' ?></label>
              <button class="panel-button" type="submit">Trvale smazat objednávku</button>
            </form>
          </section>
        <?php else: ?><p class="panel-help">Zaplacenou objednávku, objednávku se zásilkou nebo účetním dokladem nelze trvale smazat. Její historii zachovej.</p><?php endif; ?>
        <?php if ($orderEvents !== []): ?>
          <section class="panel-order-controls" aria-label="Historie zásahů">
            <h2>Historie zásahů správce</h2>
            <ul>
              <?php foreach ($orderEvents as $event): ?>
                <li><strong><?= $escape($event['created_at'] ?? '') ?></strong> · správce #<?= (int) ($event['admin_id'] ?? 0) ?> · <?= $escape($orderFulfillmentLabel($event['old_status'] ?? '')) ?> → <?= $escape($orderFulfillmentLabel($event['new_status'] ?? '')) ?><br><?= $escape($event['reason'] ?? '') ?></li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endif; ?>
      <?php endif; ?>
    </aside>
  </div>
<?php elseif ($ordersReady): ?>
  <nav class="panel-quick panel-order-filters" aria-label="Filtrovat objednávky">
    <?php foreach (['all' => 'Všechny', 'pending' => 'Čeká na platbu', 'paid' => 'Zaplaceno', 'processing' => 'Připravuje se', 'ready_to_ship' => 'Připraveno', 'shipped' => 'Odesláno', 'completed' => 'Dokončeno', 'cancelled' => 'Zrušeno', 'test' => 'Testovací'] as $filter => $label): ?>
      <a href="<?= $escape($orderBaseUrl . '&status=' . $filter) ?>" <?= $statusFilter === $filter ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <section class="panel-panel" aria-labelledby="panel-orders-list">
    <h2 id="panel-orders-list">Přijaté objednávky <span><?= count($orderPage['items']) ?> na stránce</span></h2>
    <?php if ($orderPage['items'] === []): ?><p class="panel-empty">V tomto přehledu zatím nejsou objednávky.</p><?php endif; ?>
    <div class="panel-order-list">
      <?php foreach ($orderPage['items'] as $listed): ?>
        <?php $listedPaid = ($listed['payment_status'] ?? '') === 'paid'; ?>
        <?php $listedShipment = match ($listed['shipment_status'] ?? '') {
            'created' => 'Zásilka vytvořena', 'cancelled' => 'Zásilka stornována',
            'cancelling', 'cancel_uncertain' => 'Storno k ověření',
            'submitting', 'uncertain' => 'Podání k ověření', 'rejected' => 'Podání odmítnuto',
            default => '',
        }; ?>
        <?php $listedCarrier = match ($listed['carrier_shipment_status'] ?? '') {
            'draft' => 'Podklady k podání', 'registered' => 'Číslo zásilky zapsáno',
            default => '',
        }; ?>
        <a class="panel-order-row" href="<?= $escape($orderBaseUrl . '&id=' . (int) $listed['id']) ?>">
          <span><strong><?= $escape($listed['order_number'] ?? '') ?></strong><small><?= $escape($listed['created_at'] ?? '') ?> · <?= $escape($listed['customer_email'] ?? '') ?></small></span>
          <span class="panel-order-symbol"><?= ($listed['payment_method'] ?? '') === 'test' ? 'TEST' : 'VS ' . $escape($listed['variable_symbol'] ?? '–') ?></span>
          <strong><?= $orderMoney($listed['total_czk'] ?? 0) ?></strong>
          <span class="panel-order-state <?= $listedPaid ? 'is-paid' : 'is-pending' ?>"><?= $escape($orderPaymentLabel($listed['payment_status'] ?? '')) ?> · <?= $escape($orderFulfillmentLabel($listed['status'] ?? '')) ?><?= ($listed['fulfillment_source'] ?? 'own') === 'external' ? ' · Externí dodavatel' : '' ?><?= $listedShipment !== '' ? ' · ' . $escape($listedShipment) : '' ?><?= $listedCarrier !== '' ? ' · ' . $escape($listedCarrier) : '' ?></span>
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
  <?php if ($deletedOrders !== []): ?>
    <section class="panel-panel" aria-labelledby="deleted-orders-heading">
      <h2 id="deleted-orders-heading">Nedávno smazané objednávky</h2>
      <p class="panel-help">Ponechává se číslo, správce, čas a zadaný důvod; neukládá se e-mail ani dodací adresa.</p>
      <ul class="panel-deleted-orders">
        <?php foreach ($deletedOrders as $deleted): ?>
          <li><strong><?= $escape($deleted['order_number'] ?? '') ?></strong> · <?= $escape($deleted['created_at'] ?? '') ?> · správce #<?= (int) ($deleted['admin_id'] ?? 0) ?><br><?= $escape($deleted['reason'] ?? '') ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
<?php endif; ?>
