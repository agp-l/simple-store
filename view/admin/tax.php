<?php
declare(strict_types=1);
$taxUrl = $adminUrl . '?section=accounting';
$taxMoney = static fn (mixed $value): string => number_format((int) $value, 0, ',', ' ') . ' Kč';
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d');
$taxKind = ['taxable'=>'Zdanitelný příjem', 'nontaxable'=>'Nezdanitelný příjem',
    'deductible'=>'Daňový výdaj', 'nondeductible'=>'Nedaňový výdaj'];
$balanceKind = ['receivable'=>'Pohledávka', 'liability'=>'Dluh', 'asset'=>'Majetek'];
?>
<?php if (!$taxReady || !$invoicesReady || !$mailReady): ?>
  <p class="panel-error" role="alert">Pro daňovou evidenci <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
<?php endif; ?>
<?php if ($accountingError !== ''): ?><p class="panel-error" role="alert"><?= $escape($accountingError) ?></p><?php endif; ?>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Změna byla uložena.</p><?php endif; ?>
<form class="panel-search" method="get" action="<?= $escape($adminUrl) ?>">
  <input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="<?= $escape($accountingTab) ?>">
  <label>Rok evidence<input type="number" name="year" min="2000" max="2100" value="<?= (int) $taxYear ?>"></label>
  <button class="panel-button" type="submit">Zobrazit rok</button>
</form>
<?php if ($taxReady): ?>
  <?php if ($accountingTab === 'overview'): ?>
    <section class="panel-panel">
      <h2>Souhrn peněžního deníku <?= (int) $taxYear ?></h2>
      <dl class="panel-order-facts">
        <div><dt>Zdanitelné příjmy</dt><dd><?= $taxMoney($taxSummary['income']) ?></dd></div>
        <div><dt>Daňové výdaje</dt><dd><?= $taxMoney($taxSummary['expenses']) ?></dd></div>
        <div><dt>Rozdíl</dt><dd><strong><?= $taxMoney($taxSummary['income'] - $taxSummary['expenses']) ?></strong></dd></div>
        <div><dt>Neuhrazené objednávky</dt><dd><?= count($taxReceivables) ?> v posledních 100</dd></div>
      </dl>
      <p class="panel-help">Deník používá skutečné datum příjmu či výdaje zadané správcem. Potvrzení platby objednávky samo nevytváří bankovní pohyb: částku a datum zapiš podle bankovního výpisu na detailu objednávky. Souhrn nepředstavuje hotové daňové přiznání.</p>
      <?php if (!\SimpleStore\Accounting\TaxEvidenceRepository::invoiceReady($taxSettings)): ?><p class="panel-notice">Pro vystavování faktur doplň <a href="<?= $escape($taxUrl . '&tab=settings') ?>">údaje OSVČ</a>.</p><?php endif; ?>
    </section>
    <section class="panel-panel"><h2>Na konci roku zkontroluj</h2>
      <p>Skutečný stav zásob, majetku, pohledávek a dluhů porovnej se záznamy. Zapiš rozdíly ve skladu a doplň doklady o přijatých výdajích. Daňové zařazení jednotlivých výdajů si ověř podle skutečného podnikání.</p>
    </section>
  <?php elseif ($accountingTab === 'money'): ?>
    <section class="panel-panel"><h2>Nový peněžní pohyb</h2>
      <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="money"><input type="hidden" name="action" value="tax-add-entry">
        <label>Datum podle výpisu či pokladny<input type="date" name="entry_date" value="<?= $escape($today) ?>" required></label>
        <label>Pohyb<select name="direction"><option value="income">Příjem</option><option value="expense">Výdaj</option></select></label>
        <label>Účet<select name="account"><option value="bank">Banka</option><option value="cash">Hotovost</option></select></label>
        <label>Daňové zařazení<select name="tax_kind"><option value="taxable">Zdanitelný příjem</option><option value="nontaxable">Nezdanitelný příjem</option><option value="deductible">Daňový výdaj</option><option value="nondeductible">Nedaňový výdaj</option></select></label>
        <label>Částka v Kč<input type="number" name="amount_czk" min="1" required></label>
        <label>Popis<input name="description" maxlength="255" required></label>
        <label>Protistrana<input name="counterparty" maxlength="190"></label>
        <label>Doklad nebo bankovní reference<input name="reference" maxlength="100"></label>
        <button class="panel-button" type="submit">Zapsat pohyb</button>
      </form>
      <p class="panel-help">Příjem z objednávky zapiš na jejím detailu, aby se propojil s číslem objednávky. Zde eviduj další příjmy, výdaje a převody mezi bankou a pokladnou jako nedaňové pohyby.</p>
    </section>
    <section class="panel-panel"><h2>Peněžní deník <?= (int) $taxYear ?></h2>
      <?php if ($taxEntries === []): ?><p class="panel-empty">Zatím tu nejsou peněžní pohyby.</p><?php endif; ?>
      <div class="panel-order-list">
        <?php foreach ($taxEntries as $entry): ?>
          <div class="panel-order-row"><span><strong><?= $escape($entry['entry_date']) ?></strong><small><?= $escape($entry['account'] === 'bank' ? 'Banka' : 'Hotovost') ?> · <?= $escape($entry['reference']) ?></small></span>
            <span><strong><?= $escape($entry['description']) ?></strong><small><?= $escape($entry['counterparty']) ?><?php if ($entry['order_id'] !== null): ?> · <a href="<?= $escape($adminUrl . '?section=orders&id=' . (int) $entry['order_id']) ?>">Objednávka #<?= (int) $entry['order_id'] ?></a><?php endif; ?></small></span>
            <span><?= $escape($taxKind[$entry['tax_kind']] ?? $entry['tax_kind']) ?></span>
            <strong><?= $escape($entry['direction'] === 'income' ? '+' : '−') ?><?= $taxMoney($entry['amount_czk']) ?></strong></div>
        <?php endforeach; ?>
      </div>
      <p class="panel-help">Zobrazuje se posledních 500 pohybů ve vybraném roce.</p>
    </section>
  <?php elseif ($accountingTab === 'balances'): ?>
    <section class="panel-panel"><h2>Nový majetek, pohledávka nebo dluh</h2>
      <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>">
        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="balances"><input type="hidden" name="action" value="tax-add-balance">
        <label>Druh<select name="kind"><option value="receivable">Pohledávka</option><option value="liability">Dluh</option><option value="asset">Majetek</option></select></label>
        <label>Vznik<input type="date" name="opened_on" value="<?= $escape($today) ?>" required></label>
        <label>Hodnota Kč<input type="number" name="amount_czk" min="1" required></label>
        <label>Popis<input name="description" maxlength="255" required></label>
        <label>Protistrana<input name="counterparty" maxlength="190"></label>
        <label>Číslo dokladu<input name="reference" maxlength="100"></label>
        <button class="panel-button" type="submit">Přidat záznam</button>
      </form>
    </section>
    <section class="panel-panel"><h2>Neuhrazené objednávky</h2>
      <?php if ($taxReceivables === []): ?><p class="panel-empty">Žádné neuhrazené objednávky.</p><?php endif; ?>
      <div class="panel-order-list"><?php foreach ($taxReceivables as $receivable): ?>
        <a class="panel-order-row" href="<?= $escape($adminUrl . '?section=orders&id=' . (int) $receivable['id']) ?>"><strong><?= $escape($receivable['order_number']) ?></strong><span><?= $escape($receivable['customer_email']) ?></span><strong><?= $taxMoney($receivable['total_czk']) ?></strong></a>
      <?php endforeach; ?></div>
    </section>
    <section class="panel-panel"><h2>Ostatní majetek a dluhy</h2>
      <?php if ($taxBalances === []): ?><p class="panel-empty">Žádné další záznamy.</p><?php endif; ?>
      <?php foreach ($taxBalances as $balance): ?>
        <div class="panel-order-row"><span><strong><?= $escape($balanceKind[$balance['kind']] ?? $balance['kind']) ?></strong><small><?= $escape($balance['opened_on']) ?> · <?= $escape($balance['reference']) ?></small></span>
          <span><?= $escape($balance['description']) ?> · <?= $escape($balance['counterparty']) ?></span><strong><?= $taxMoney($balance['amount_czk']) ?></strong>
          <?php if ($balance['closed_on'] === null): ?><form method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="balances"><input type="hidden" name="action" value="tax-close-balance"><input type="hidden" name="id" value="<?= (int) $balance['id'] ?>"><input type="date" name="closed_on" value="<?= $escape($today) ?>" required><button class="panel-button" type="submit">Uzavřít</button></form><?php else: ?>Uzavřeno <?= $escape($balance['closed_on']) ?><?php endif; ?></div>
      <?php endforeach; ?>
    </section>
  <?php elseif ($accountingTab === 'stock'): ?>
    <section class="panel-panel"><h2>Stav zásob</h2>
      <p class="panel-help">Zapiš počáteční a přijaté kusy jako kladný pohyb; škodu či inventurní rozdíl jako záporný. U odeslaných objednávek systém odečítá množství z uloženého snímku položek. Skutečný stav na konci roku ověř fyzicky.</p>
      <form method="get" action="<?= $escape($adminUrl) ?>" class="panel-search"><input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="stock"><label>Najít produkt<input name="search" value="<?= $escape($_GET['search'] ?? '') ?>"></label><button class="panel-button" type="submit">Hledat</button></form>
      <div class="panel-order-list">
        <?php foreach ($taxProducts as $product): ?>
          <div class="panel-order-row"><span><strong><?= $escape($product['name']) ?></strong><small><?= $escape($product['product_key']) ?></small></span>
            <span>Zapsáno <?= (int) $product['received'] ?> ks · odesláno <?= (int) $product['dispatched'] ?> ks</span>
            <strong>Stav <?= (int) $product['received'] - (int) $product['dispatched'] ?> ks</strong>
            <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="stock"><input type="hidden" name="action" value="tax-add-stock"><input type="hidden" name="product_key" value="<?= $escape($product['product_key']) ?>">
              <label>Datum<input type="date" name="movement_date" value="<?= $escape($today) ?>" required></label><label>Změna ks<input type="number" name="quantity_change" placeholder="+10 / -2" required></label><label>Pořizovací Kč/ks<input type="number" name="unit_cost_czk" min="0"></label><label>Důvod<input name="description" maxlength="255" required></label><label>Doklad<input name="reference" maxlength="100"></label><button class="panel-button" type="submit">Zapsat</button>
            </form></div>
        <?php endforeach; ?>
      </div>
      <p class="panel-help">Zobrazuje se prvních 60 odpovídajících produktů. Hledání funguje podle názvu nebo klíče.</p>
      <form method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="stock"><input type="hidden" name="action" value="tax-backfill-sales"><button class="panel-button" type="submit">Doplnit položky starších objednávek (max. 100)</button></form>
    </section>
    <section class="panel-panel"><h2>Položky objednávek <?= (int) $taxYear ?></h2>
      <?php foreach ($saleLines as $line): ?><div class="panel-order-row"><a href="<?= $escape($adminUrl . '?section=orders&id=' . (int) $line['order_id']) ?>"><?= $escape($line['order_number']) ?></a><span><?= (int) $line['quantity'] ?> × <?= $escape($line['name']) ?></span><strong><?= $taxMoney((int) $line['quantity'] * (int) $line['unit_price_czk']) ?></strong><span><?= $escape($line['status']) ?></span></div><?php endforeach; ?>
      <?php if ($saleLines === []): ?><p class="panel-empty">Zatím žádné zachycené položky.</p><?php endif; ?>
    </section>
  <?php elseif ($accountingTab === 'invoices'): ?>
    <section class="panel-panel"><h2>Vystavené faktury <?= (int) $taxYear ?></h2>
      <p class="panel-help">Fakturu vystavíš na detailu zaplacené objednávky. Čísla přiděluje roční řada; po opravě čísla zůstane původní číslo v historii. Faktury vytvořené před změnou údajů OSVČ si zachovají své původní údaje.</p>
      <?php if ($invoiceRows === []): ?><p class="panel-empty">Žádné faktury za tento rok.</p><?php endif; ?>
      <div class="panel-order-list"><?php foreach ($invoiceRows as $row): ?>
        <a class="panel-order-row" href="<?= $escape($taxUrl . '&tab=invoices&year=' . $taxYear . '&invoice_id=' . (int) $row['id']) ?>"><strong><?= $escape($row['document_number']) ?></strong><span><?= $escape($row['order_number']) ?> · <?= $escape($row['issue_date']) ?></span><strong><?= $taxMoney($row['total_czk']) ?></strong><span><?= $row['emailed_at'] !== null ? 'Odesláno e-mailem' : 'E-mail čeká' ?></span></a>
      <?php endforeach; ?></div>
    </section>
    <?php if ($selectedInvoice !== null): ?>
      <section class="panel-panel"><h2>Faktura <?= $escape($selectedInvoice['document_number']) ?></h2>
        <p><a class="panel-button" href="<?= $escape($taxUrl . '&invoice_id=' . (int) $selectedInvoice['id'] . '&print=1') ?>" target="_blank" rel="noopener noreferrer">Tisk / uložit jako PDF</a> <a href="<?= $escape($adminUrl . '?section=orders&id=' . (int) $selectedInvoice['order_id']) ?>">Objednávka</a></p>
        <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="invoices"><input type="hidden" name="action" value="invoice-renumber"><input type="hidden" name="id" value="<?= (int) $selectedInvoice['id'] ?>"><label>Opravené číslo dokladu<input name="document_number" value="<?= $escape($selectedInvoice['document_number']) ?>" maxlength="40" required></label><label>Důvod opravy<input name="reason" minlength="8" maxlength="190" required></label><button class="panel-button" type="submit">Opravit číslo</button></form>
        <p class="panel-help">Pokud už zákazník dostal původní doklad, opravu s ním sladíš; dříve odeslaný e-mail se zpětně nemění.</p>
        <?php foreach ($invoiceHistory as $change): ?><p><?= $escape($change['old_number']) ?> → <?= $escape($change['new_number']) ?> · <?= $escape($change['created_at']) ?> UTC · <?= $escape($change['reason']) ?></p><?php endforeach; ?>
        <?php if ($selectedInvoice['emailed_at'] === null): ?><form method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="mail"><input type="hidden" name="action" value="invoice-email"><input type="hidden" name="id" value="<?= (int) $selectedInvoice['id'] ?>"><button class="panel-button" type="submit">Odeslat fakturu e-mailem</button></form><?php endif; ?>
      </section>
    <?php endif; ?>
  <?php elseif ($accountingTab === 'mail'): ?>
    <section class="panel-panel"><h2>Oznámení zákazníkům</h2>
      <p class="panel-help">Potvrzení objednávky se vytvoří při dokončení pokladny. Faktura se vytvoří při jejím vystavení. E-mail se odešle přes PHP mail() s odesílatelem z nastavení OSVČ; pokud server odeslání odmítne, zůstane zde k opakování. Doručení do schránky samotné odeslání nezaručuje.</p>
      <?php if (($taxSettings['mail_from'] ?? '') === ''): ?><p class="panel-notice">Vyplň adresu odesílatele v <a href="<?= $escape($taxUrl . '&tab=settings') ?>">údajích OSVČ</a>.</p><?php endif; ?>
      <?php foreach ($mailRows as $row): ?><div class="panel-order-row"><span><strong><?= $escape($row['subject']) ?></strong><small><?= $escape($row['recipient_email']) ?></small></span><span><?= $escape($row['state']) ?> · pokusů <?= (int) $row['attempts'] ?><?php if ($row['last_error'] !== null): ?><br><?= $escape($row['last_error']) ?><?php endif; ?></span><?php if (in_array($row['state'], ['queued','failed'], true)): ?><form method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="mail"><input type="hidden" name="action" value="mail-retry"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="panel-button" type="submit">Zkusit odeslat</button></form><?php endif; ?></div><?php endforeach; ?>
      <?php if ($mailRows === []): ?><p class="panel-empty">Fronta je prázdná.</p><?php endif; ?>
    </section>
  <?php elseif ($accountingTab === 'settings'): ?>
    <section class="panel-panel"><h2>Údaje OSVČ pro faktury a e-maily</h2>
      <p class="panel-help">Režim této evidence je OSVČ s daňovou evidencí bez DPH. Při změně na plátce DPH bude potřeba doplnit příslušnou evidenci a doklady. Uložené faktury se po změně nastavení nepřepisují.</p>
      <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="settings"><input type="hidden" name="action" value="tax-save-settings">
        <?php foreach (['name'=>'Jméno a příjmení podnikatele', 'ico'=>'IČO', 'street'=>'Ulice a číslo sídla', 'city'=>'Město', 'postal_code'=>'PSČ', 'email'=>'Kontaktní e-mail', 'phone'=>'Telefon', 'bank_account'=>'Číslo účtu na faktuře', 'mail_from'=>'E-mail odesílatele (vlastní doména)'] as $key=>$label): ?><label><?= $escape($label) ?><input name="<?= $key ?>" value="<?= $escape($taxSettings[$key] ?? '') ?>" maxlength="<?= $key === 'ico' ? 8 : 254 ?>"></label><?php endforeach; ?>
        <button class="panel-button" type="submit">Uložit údaje OSVČ</button>
      </form>
    </section>
  <?php endif; ?>
<?php endif; ?>
