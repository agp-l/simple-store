<?php
declare(strict_types=1);
$taxUrl = $adminUrl . '?section=accounting';
$taxMoney = static fn (mixed $value): string => number_format((int) $value, 0, ',', ' ') . ' Kč';
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d');
$taxKind = ['taxable'=>'Zdanitelný příjem', 'nontaxable'=>'Nezdanitelný příjem',
    'deductible'=>'Daňový výdaj', 'nondeductible'=>'Nedaňový výdaj'];
$balanceKind = ['receivable'=>'Pohledávka', 'liability'=>'Dluh', 'asset'=>'Majetek'];
$selectedMethod = $taxYearMode['method'] ?? ($taxSettings['expense_method'] ?? 'actual');
?>
<?php if (!$taxReady || !$invoicesReady): ?>
  <p class="panel-error" role="alert">Pro doklady a deník <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
<?php endif; ?>
<?php if ($accountingError !== ''): ?><p class="panel-error" role="alert"><?= $escape($accountingError) ?></p><?php endif; ?>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Změna byla uložena.</p><?php endif; ?>
<?php if (in_array($accountingTab, ['money', 'balances', 'stock', 'invoices'], true)): ?>
<form class="panel-search" method="get" action="<?= $escape($adminUrl) ?>">
  <input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="<?= $escape($accountingTab) ?>">
  <label>Rok evidence<input type="number" name="year" min="2000" max="2100" value="<?= (int) $taxYear ?>"></label>
  <button class="panel-button" type="submit">Zobrazit rok</button>
</form>
<?php endif; ?>
<?php if ($taxReady): ?>
  <?php if ($accountingTab === 'overview'): ?>
    <section class="panel-panel">
      <h2>Jak postupovat u objednávky</h2>
      <p>1. Ověř platbu u objednávky. 2. Na jejím detailu vystav doklad. 3. Zapiš skutečný příjem podle banky či pokladny. U platební brány porovnej také vyúčtování a výplatu na účet. V tabulce níže hned uvidíš, co je hotové a co chybí. <a href="<?= $escape($taxUrl . '&tab=guide&year=' . $taxYear) ?>">Podrobný postup pro OSVČ →</a></p>
    </section>
    <section class="panel-panel">
      <h2>Zápisy v peněžním deníku za rok <?= (int) $taxYear ?></h2>
      <dl class="panel-order-facts">
        <div><dt>Zapsané zdanitelné příjmy</dt><dd><?= $taxMoney($taxSummary['income']) ?></dd></div>
        <?php if ($selectedMethod === 'actual'): ?>
          <div><dt>Zapsané skutečné daňové výdaje</dt><dd><?= $taxMoney($taxSummary['expenses']) ?></dd></div>
          <div><dt>Rozdíl v deníku</dt><dd><strong><?= $taxMoney($taxSummary['income'] - $taxSummary['expenses']) ?></strong></dd></div>
        <?php elseif ($selectedMethod === 'percentage'): ?>
          <div><dt>Režim pro vybraný rok</dt><dd>Výdaje procentem z příjmů (<?= (int) ($taxYearMode['expense_percentage'] ?? 60) ?> %)</dd></div>
        <?php else: ?>
          <div><dt>Režim pro vybraný rok</dt><dd>Paušální daň · pásmo <?= (int) ($taxYearMode['flat_tax_band'] ?? 1) ?></dd></div>
        <?php endif; ?>
      </dl>
      <p class="panel-help">Součet zahrnuje jen zapsané peněžní pohyby. Není to součet všech uhrazených objednávek, výpočet daně ani přehled za všechny tvoje činnosti. Chybějící, vrácené a nesprávně zařazené platby ověř podle zdrojových dokladů.<?php if (!($taxYearMode['saved'] ?? false)): ?> Pro tento rok zatím není uložený vlastní režim; zobrazuje se starší obecná volba. <a href="<?= $escape($taxUrl . '&tab=settings&year=' . $taxYear) ?>">Potvrdit režim roku</a>.<?php endif; ?></p>
      <?php if ($selectedMethod === 'flat_tax' && !($taxYearMode['flat_tax_confirmed'] ?? false)): ?><p class="panel-notice">Paušální režim je zatím jen naplánovaný. Potvrď v <a href="<?= $escape($taxUrl . '&tab=settings&year=' . $taxYear) ?>">nastavení roku</a>, že vstup byl skutečně oznámen finančnímu úřadu. E-shop tvou účast neumí ověřit.</p><?php endif; ?>
      <?php if (!\SimpleStore\Accounting\TaxEvidenceRepository::invoiceReady($taxSettings)): ?><p class="panel-notice">Pro vystavování dokladů doplň <a href="<?= $escape($taxUrl . '&tab=settings') ?>">údaje podnikatele</a>.</p><?php endif; ?>
    </section>
    <?php require __DIR__ . '/evidence-book.php'; ?>
    <?php if ($selectedMethod === 'flat_tax'): ?>
      <?php require __DIR__ . '/flat-tax-advances.php'; ?>
    <?php endif; ?>
    <details class="panel-panel panel-accounting-more"><summary>Archivní a kontrolní sestavy</summary>
      <p><a href="<?= $escape($taxUrl . '&tab=orders&year=' . $taxYear) ?>">Kontrola potvrzených plateb a oprav</a> slouží k dohledání zásahů v objednávkách; není součtem peněžních příjmů. <a href="<?= $escape($taxUrl . '&tab=stock&year=' . $taxYear) ?>">Starší skladové podklady a CSV</a> ponecháváme pro dříve uložená data. Aktuální kusy se spravují u produktů; tato sestava nenahrazuje inventuru.</p>
    </details>
  <?php elseif ($accountingTab === 'money'): ?>
    <section class="panel-panel"><h2>Peněžní pohyby podle výpisu</h2>
      <p>Příjem z objednávky placené převodem zapiš na <a href="<?= $escape($adminUrl . '?section=orders') ?>">jejím detailu</a>, aby se k ní automaticky připojil. Výplaty platebních bran a jejich poplatky zapisuj podle skutečného vyúčtování. U procentních výdajů jednotlivé nákupy nesnižují daňový základ nad rámec procenta.</p>
      <?php if ($selectedMethod === 'flat_tax'): ?><p class="panel-notice">Paušální zálohy zapisuj v <a href="<?= $escape($taxUrl . '&tab=overview&year=' . $taxYear) ?>">přehledu roku</a>. Odtud se automaticky vloží i do tohoto deníku; stejnou platbu zde znovu nevkládej.</p><?php endif; ?>
      <p class="panel-help"><a href="<?= $escape($taxUrl . '&tab=guide') ?>">Co znamená daňové zařazení? →</a> Doklady a výpisy uschovej; formulář nyní ukládá referenci, nikoli soubor s originálem dokladu.</p>
      <?php foreach (['income' => ['Přidat příjem', 'Zdanitelný příjem', 'Nezdanitelný příjem'], 'expense' => ['Přidat výdaj', 'Daňový výdaj', 'Nedaňový výdaj']] as $direction => [$heading, $primaryKind, $secondaryKind]): ?>
        <details class="panel-entry-add"><summary><?= $escape($heading) ?></summary>
          <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>">
            <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="money"><input type="hidden" name="action" value="tax-add-entry"><input type="hidden" name="direction" value="<?= $direction ?>">
            <label>Datum skutečné platby<input type="date" name="entry_date" value="<?= $escape($today) ?>" required></label>
            <label>Kde se peníze pohnuly<select name="account"><option value="bank">Bankovní účet</option><option value="cash">Pokladna</option></select></label>
            <label>Vliv na daň z příjmů<select name="tax_kind">
              <?php if ($direction === 'expense' && $selectedMethod !== 'actual'): ?>
                <option value="nondeductible">Nedaňový výdaj – <?= $selectedMethod === 'flat_tax' ? 'v paušálním režimu' : 'při procentních výdajích' ?></option>
                <option value="deductible">Daňový výdaj – jen pro rok se skutečnými výdaji</option>
              <?php else: ?>
                <option value="<?= $direction === 'income' ? 'taxable' : 'deductible' ?>"><?= $escape($primaryKind) ?></option>
                <option value="<?= $direction === 'income' ? 'nontaxable' : 'nondeductible' ?>"><?= $escape($secondaryKind) ?></option>
              <?php endif; ?>
            </select></label>
            <label>Částka v Kč<input type="number" name="amount_czk" min="1" required></label>
            <label>Co se stalo<input name="description" maxlength="255" required></label>
            <label>Od koho / komu<input name="counterparty" maxlength="190"></label>
            <label>Číslo dokladu nebo reference z výpisu<input name="reference" maxlength="100"></label>
            <button class="panel-button" type="submit"><?= $escape($heading) ?></button>
          </form>
        </details>
      <?php endforeach; ?>
    </section>
    <?php if ($taxEditEntry !== null): ?>
      <section class="panel-panel"><h2>Opravit peněžní zápis #<?= (int) $taxEditEntry['id'] ?></h2>
        <p class="panel-help">Oprava zachová původní údaje a důvod v historii. U úhrady objednávky po opravě ověř soulad s výpisem banky.</p>
        <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="tax-amend-entry"><input type="hidden" name="id" value="<?= (int) $taxEditEntry['id'] ?>">
          <label>Datum<input type="date" name="entry_date" value="<?= $escape($taxEditEntry['entry_date']) ?>" required></label>
          <label>Pohyb<select name="direction"><option value="income" <?= $taxEditEntry['direction'] === 'income' ? 'selected' : '' ?>>Příjem</option><option value="expense" <?= $taxEditEntry['direction'] === 'expense' ? 'selected' : '' ?>>Výdaj</option></select></label>
          <label>Účet<select name="account"><option value="bank" <?= $taxEditEntry['account'] === 'bank' ? 'selected' : '' ?>>Banka</option><option value="cash" <?= $taxEditEntry['account'] === 'cash' ? 'selected' : '' ?>>Hotovost</option></select></label>
          <label>Vliv na daň z příjmů<select name="tax_kind"><?php foreach ($taxKind as $value=>$label): ?><option value="<?= $escape($value) ?>" <?= $taxEditEntry['tax_kind'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
          <label>Částka Kč<input type="number" name="amount_czk" min="1" value="<?= (int) $taxEditEntry['amount_czk'] ?>" required></label>
          <label>Popis<input name="description" maxlength="255" value="<?= $escape($taxEditEntry['description']) ?>" required></label>
          <label>Protistrana<input name="counterparty" maxlength="190" value="<?= $escape($taxEditEntry['counterparty']) ?>"></label>
          <label>Doklad / reference<input name="reference" maxlength="100" value="<?= $escape($taxEditEntry['reference']) ?>"></label>
          <label>Důvod opravy<textarea name="reason" minlength="8" maxlength="190" required></textarea></label>
          <button class="panel-button" type="submit">Uložit opravu</button>
        </form>
        <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="tax-void-entry"><input type="hidden" name="id" value="<?= (int) $taxEditEntry['id'] ?>">
          <label>Důvod vyřazení chybného záznamu<textarea name="reason" minlength="8" maxlength="190" required></textarea></label>
          <label class="panel-check"><input type="checkbox" name="confirmed" value="1" required> Potvrzuji, že tento zápis nemá být v peněžním deníku.</label>
          <button class="panel-button" type="submit">Vyřadit chybný zápis</button>
        </form>
      </section>
    <?php endif; ?>
    <section class="panel-panel"><div class="panel-panel-head"><h2>Peněžní deník <?= (int) $taxYear ?></h2><a class="panel-button" href="<?= $escape($taxUrl . '&tab=money&year=' . $taxYear . '&download=ledger') ?>">Stáhnout CSV</a></div>
      <?php if ($taxEntries === []): ?><p class="panel-empty">Zatím tu nejsou peněžní pohyby.</p><?php endif; ?>
      <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Datum</th><th>Účet / doklad</th><th>Popis / protistrana</th><th>Zařazení</th><th>Částka</th><th>Úprava</th></tr></thead><tbody>
      <?php foreach ($taxEntries as $entry): ?><tr><td><?= $escape($entry['entry_date']) ?></td>
        <td><?= $escape($entry['account'] === 'bank' ? 'Banka' : 'Hotovost') ?><br><small><?= $escape($entry['reference']) ?></small></td>
        <td><strong><?= $escape($entry['description']) ?></strong><br><small><?= $escape($entry['counterparty']) ?><?php if ($entry['order_id'] !== null): ?> · <a href="<?= $escape($adminUrl . '?section=orders&id=' . (int) $entry['order_id']) ?>">Objednávka #<?= (int) $entry['order_id'] ?></a><?php endif; ?></small></td>
        <td><?= $escape($taxKind[$entry['tax_kind']] ?? $entry['tax_kind']) ?></td>
        <td class="panel-table-money"><?= $entry['direction'] === 'income' ? '+' : '−' ?><?= $taxMoney($entry['amount_czk']) ?></td>
        <td><a href="<?= $escape($taxUrl . '&tab=money&year=' . $taxYear . '&edit_entry=' . (int) $entry['id']) ?>">Opravit</a></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <nav class="panel-quick panel-order-pages" aria-label="Stránky peněžního deníku">
        <?php if ($taxEntriesPreviousUrl !== ''): ?><a href="<?= $escape($taxEntriesPreviousUrl) ?>">← Předchozí</a><?php endif; ?>
        <?php if ($taxEntriesNextUrl !== ''): ?><a href="<?= $escape($taxEntriesNextUrl) ?>">Další →</a><?php endif; ?>
      </nav>
      <p class="panel-help">Zobrazuje se 50 pohybů na stránku. Export CSV zahrnuje celý vybraný rok.</p>
    </section>
    <section class="panel-panel"><h2>Historie oprav deníku</h2>
      <?php if ($taxEntryHistory === []): ?><p class="panel-empty">Žádné opravy ve vybraném roce.</p><?php endif; ?>
      <?php foreach ($taxEntryHistory as $change): ?><p>Zápis #<?= (int) $change['entry_id'] ?> · <?= $change['action'] === 'voided' ? 'Vyřazeno' : 'Opraveno' ?> · <?= $escape($change['created_at']) ?> UTC · správce #<?= (int) $change['admin_id'] ?><br><?= $escape($change['reason']) ?></p><?php endforeach; ?>
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
    <section class="panel-panel"><h2>Objednávky čekající na platbu</h2>
      <p class="panel-help">Provozní seznam nejnovějších objednávek; neúplný přehled pohledávek za rok. Pro úplnou uzávěrku ověř vlastní podklady, částečné platby a další pohledávky mimo e-shop.</p>
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
    <section class="panel-panel"><div class="panel-panel-head"><h2>Sklad produktů a starší podklady</h2><div><a class="panel-button" href="<?= $escape($taxUrl . '&tab=stock&year=' . $taxYear . '&download=stock') ?>">Skladové pohyby CSV</a> <a class="panel-button" href="<?= $escape($taxUrl . '&tab=stock&year=' . $taxYear . '&download=sales') ?>">Prodané kusy CSV</a></div></div>
      <p class="panel-notice">Údaj „Volné kusy v e-shopu“ se čte ze skutečného skladu produktů. Starý ruční přehled níže není propojený s tímto skladem; jeho rozdíl proto <strong>není aktuální dostupnost</strong>. Počet kusů nastavuj na <a href="<?= $escape($adminUrl . '?section=products') ?>">kartě produktu</a>. Historické záznamy a CSV zde zůstávají ke kontrole.</p>
      <p class="panel-help">U skutečných výdajů si k nákupu uchovej dodavatelský doklad a na konci roku fyzicky zjisti skutečný stav zásob. Tento přehled sám neprokazuje hodnotu inventury.</p>
      <form method="get" action="<?= $escape($adminUrl) ?>" class="panel-search"><input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="stock"><label>Najít produkt<input name="search" value="<?= $escape($_GET['search'] ?? '') ?>"></label><button class="panel-button" type="submit">Hledat</button></form>
      <div class="panel-order-list">
        <?php foreach ($taxProducts as $product): ?>
          <div class="panel-order-row"><span><strong><?= $escape($product['name']) ?></strong><small><?= $escape($product['product_key']) ?></small></span>
            <span>Volné kusy v e-shopu: <strong><?= isset($product['stock_quantity']) ? (int) $product['stock_quantity'] . ' ks' : 'neznámé' ?></strong><br><small>Staré ruční příjmy <?= (int) $product['received'] ?> ks · odeslané položky <?= (int) $product['dispatched'] ?> ks</small></span>
            <span>Rozdíl starých záznamů <?= (int) $product['received'] - (int) $product['dispatched'] ?> ks</span>
            </div>
        <?php endforeach; ?>
      </div>
      <p class="panel-help">Zobrazuje se prvních 60 odpovídajících produktů. Hledání funguje podle názvu nebo klíče.</p>
    </section>
    <section class="panel-panel"><h2>Položky objednávek <?= (int) $taxYear ?></h2>
      <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Objednávka</th><th>Produkt</th><th>Kusů</th><th>Cena/ks</th><th>Celkem</th><th>Stav</th></tr></thead><tbody>
      <?php foreach ($saleLines as $line): ?><tr><td><?php if ($line['order_id'] !== null): ?><a href="<?= $escape($adminUrl . '?section=orders&id=' . (int) $line['order_id']) ?>"><?= $escape($line['order_number']) ?></a><?php else: ?><?= $escape($line['order_number']) ?> <small>(smazaná)</small><?php endif; ?></td><td><?= $escape($line['name']) ?></td><td><?= (int) $line['quantity'] ?></td><td><?= $taxMoney($line['unit_price_czk']) ?></td><td><?= $taxMoney((int) $line['quantity'] * (int) $line['unit_price_czk']) ?></td><td><?= $escape($line['status']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if ($saleLines === []): ?><p class="panel-empty">Zatím žádné zachycené položky.</p><?php endif; ?>
    </section>
    <section class="panel-panel"><h2>Poslední skladové pohyby</h2>
      <div class="panel-table-wrap"><table class="panel-table"><thead><tr><th>Datum</th><th>Produktový klíč</th><th>Změna</th><th>Pořizovací cena/ks</th><th>Důvod / doklad</th></tr></thead><tbody>
      <?php foreach ($stockMovements as $movement): ?><tr><td><?= $escape($movement['movement_date']) ?></td><td><?= $escape($movement['product_key']) ?></td><td><?= (int) $movement['quantity_change'] ?> ks</td><td><?= $movement['unit_cost_czk'] === null ? '—' : $taxMoney($movement['unit_cost_czk']) ?></td><td><?= $escape($movement['description']) ?> · <?= $escape($movement['reference']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </section>
  <?php elseif ($accountingTab === 'invoices'): ?>
    <section class="panel-panel"><h2>Vystavené faktury <?= (int) $taxYear ?></h2>
      <p class="panel-help">Fakturu vystavíš na detailu zaplacené objednávky. Čísla přiděluje roční řada; po opravě čísla zůstane původní číslo v historii. Faktury vytvořené před změnou údajů OSVČ si zachovají své původní údaje.</p>
      <?php if ($invoiceRows === []): ?><p class="panel-empty">Žádné faktury za tento rok.</p><?php endif; ?>
      <div class="panel-order-list"><?php foreach ($invoiceRows as $row): ?>
        <a class="panel-order-row" href="<?= $escape($taxUrl . '&tab=invoices&year=' . $taxYear . '&invoice_id=' . (int) $row['id']) ?>"><strong><?= $escape($row['document_number']) ?></strong><span><?= $escape($row['order_number']) ?> · <?= $escape($row['issue_date']) ?></span><strong><?= $taxMoney($row['total_czk']) ?></strong><span><?= $row['emailed_at'] !== null ? 'Odesláno e-mailem' : 'E-mail čeká' ?></span></a>
      <?php endforeach; ?></div>
      <nav class="panel-quick panel-order-pages" aria-label="Stránky vystavených faktur">
        <?php if ($invoicePreviousUrl !== ''): ?><a href="<?= $escape($invoicePreviousUrl) ?>">← Předchozí</a><?php endif; ?>
        <?php if ($invoiceNextUrl !== ''): ?><a href="<?= $escape($invoiceNextUrl) ?>">Další →</a><?php endif; ?>
      </nav>
    </section>
    <?php if ($selectedInvoice !== null): ?>
      <section class="panel-panel"><h2>Faktura <?= $escape($selectedInvoice['document_number']) ?></h2>
        <p><a class="panel-button" href="<?= $escape($taxUrl . '&invoice_id=' . (int) $selectedInvoice['id'] . '&print=1') ?>" target="_blank" rel="noopener noreferrer">Tisk / uložit jako PDF</a> <?php if ($selectedInvoice['order_id'] !== null): ?><a href="<?= $escape($adminUrl . '?section=orders&id=' . (int) $selectedInvoice['order_id']) ?>">Objednávka</a><?php else: ?><span class="panel-help">Objednávka byla smazána; vydaný doklad zůstává uchován.</span><?php endif; ?></p>
        <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="invoices"><input type="hidden" name="action" value="invoice-renumber"><input type="hidden" name="id" value="<?= (int) $selectedInvoice['id'] ?>"><label>Opravené číslo dokladu<input name="document_number" value="<?= $escape($selectedInvoice['document_number']) ?>" maxlength="40" required></label><label>Důvod opravy<input name="reason" minlength="8" maxlength="190" required></label><button class="panel-button" type="submit">Opravit číslo</button></form>
        <p class="panel-help">Pokud už zákazník dostal původní doklad, opravu s ním sladíš; dříve odeslaný e-mail se zpětně nemění.</p>
        <?php foreach ($invoiceHistory as $change): ?><p><?= $escape($change['old_number']) ?> → <?= $escape($change['new_number']) ?> · <?= $escape($change['created_at']) ?> UTC · <?= $escape($change['reason']) ?></p><?php endforeach; ?>
        <?php if ($selectedInvoice['emailed_at'] === null): ?><form method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="mail"><input type="hidden" name="action" value="invoice-email"><input type="hidden" name="id" value="<?= (int) $selectedInvoice['id'] ?>"><button class="panel-button" type="submit">Odeslat fakturu e-mailem</button></form><?php endif; ?>
      </section>
    <?php endif; ?>
  <?php elseif ($accountingTab === 'mail'): ?>
    <section class="panel-panel"><h2>Oznámení zákazníkům</h2>
      <p class="panel-help">Potvrzení objednávky, změny stavu a faktury se připravují do fronty. Odesílatele a texty nastavíš v <a href="<?= $escape($adminUrl . '?section=settings&tab=mail') ?>">nastavení e-mailů</a>. Odmítnuté zprávy můžeš zopakovat; přijetí zprávy poštovním serverem samo nezaručuje doručení do schránky.</p>
      <?php if (($taxSettings['mail_from'] ?? '') === '' && $mailQueue->sender() === ''): ?><p class="panel-notice">Vyplň adresu odesílatele v <a href="<?= $escape($adminUrl . '?section=settings&tab=mail') ?>">nastavení e-mailů</a>.</p><?php endif; ?>
      <?php foreach ($mailRows as $row): ?><div class="panel-order-row"><span><strong><?= $escape($row['subject']) ?></strong><small><?= $escape($row['recipient_email']) ?></small></span><span><?= $escape($row['state']) ?> · pokusů <?= (int) $row['attempts'] ?><?php if ($row['last_error'] !== null): ?><br><?= $escape($row['last_error']) ?><?php endif; ?></span><?php if (in_array($row['state'], ['queued','failed'], true)): ?><form method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="mail"><input type="hidden" name="action" value="mail-retry"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="panel-button" type="submit">Zkusit odeslat</button></form><?php endif; ?></div><?php endforeach; ?>
      <?php if ($mailRows === []): ?><p class="panel-empty">Fronta je prázdná.</p><?php endif; ?>
    </section>
  <?php elseif ($accountingTab === 'settings'): ?>
    <section class="panel-panel"><h2>Údaje prodávajícího</h2>
      <p class="panel-help">Z těchto údajů vznikají nové faktury; již vystavené si ponechají původní podobu. Systém vytváří doklady neplátce DPH.</p>
      <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="settings"><input type="hidden" name="action" value="tax-save-settings">
        <input type="hidden" name="year" value="<?= (int) $taxYear ?>">
        <input type="hidden" name="mail_from" value="<?= $escape($taxSettings['mail_from'] ?? '') ?>">
        <label>Právní forma prodávajícího<select name="legal_form"><option value="sole_trader" <?= ($taxSettings['legal_form'] ?? 'sole_trader') === 'sole_trader' ? 'selected' : '' ?>>OSVČ</option><option value="company" <?= ($taxSettings['legal_form'] ?? '') === 'company' ? 'selected' : '' ?>>Společnost (např. s.r.o.)</option></select></label>
        <p class="panel-help">Pro s.r.o. není tato evidence OSVČ účetnictvím společnosti. Údaj změň podle skutečného prodávajícího.</p>
        <?php foreach (['name'=>'Jméno a příjmení podnikatele', 'ico'=>'IČO', 'street'=>'Ulice a číslo sídla', 'city'=>'Město', 'postal_code'=>'PSČ', 'email'=>'Kontaktní e-mail', 'phone'=>'Telefon', 'bank_account'=>'Číslo účtu na faktuře'] as $key=>$label): ?><label><?= $escape($label) ?><input name="<?= $key ?>" value="<?= $escape($taxSettings[$key] ?? '') ?>" maxlength="<?= $key === 'ico' ? 8 : 254 ?>"></label><?php endforeach; ?>
        <p class="panel-help">Odesílání zákaznických zpráv a jejich obsah najdeš v <a href="<?= $escape($adminUrl . '?section=settings&tab=mail') ?>">nastavení obchodu → E-maily</a>.</p>
        <button class="panel-button" type="submit">Uložit údaje prodávajícího</button>
      </form>
    </section>
    <section class="panel-panel"><h2>Režim pro rok <?= (int) $taxYear ?></h2>
      <p>Každý rok má vlastní volbu. Nastavení v e-shopu <strong>není oznámením finančnímu úřadu</strong> ani ověřením splnění podmínek. <a href="<?= $escape($taxUrl . '&tab=guide&year=' . $taxYear) ?>">Jak vybrat režim a pásmo →</a></p>
      <form class="panel-search" method="get" action="<?= $escape($adminUrl) ?>"><input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="settings"><label>Rok<input type="number" name="year" min="2000" max="2100" value="<?= (int) $taxYear ?>" required></label><button class="panel-button" type="submit">Zobrazit rok</button></form>
      <?php if (!($taxYearRegimeReady ?? false)): ?>
        <p class="panel-error">Roční volby ještě nemají tabulku. <a href="<?= $escape($adminUrl . '?section=database') ?>">Aktualizuj SQL tabulky</a>; původní nastavení zůstává zachováno.</p>
      <?php elseif (($taxSettings['legal_form'] ?? 'sole_trader') === 'company'): ?>
        <p class="panel-error">Paušální daň a výdaje OSVČ nelze použít pro obchodní společnost. Nejprve ověř údaje prodávajícího.</p>
      <?php else: ?>
        <?php if (!($taxYearMode['saved'] ?? false)): ?><p class="panel-notice">Pro <?= (int) $taxYear ?> zatím není uložený vlastní režim. Zobrazená volba vychází ze starého obecného nastavení; potvrď ji po kontrole podkladů.</p><?php endif; ?>
        <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>">
          <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="tax-save-year-mode"><input type="hidden" name="year" value="<?= (int) $taxYear ?>">
          <label>Režim OSVČ pro tento rok<select name="method">
            <option value="actual" <?= $selectedMethod === 'actual' ? 'selected' : '' ?>>Skutečné výdaje – daňová evidence</option>
            <option value="percentage" <?= $selectedMethod === 'percentage' ? 'selected' : '' ?>>Výdaje procentem z příjmů</option>
            <option value="flat_tax" <?= $selectedMethod === 'flat_tax' ? 'selected' : '' ?>>Paušální daň – oznámený paušální režim</option>
          </select></label>
          <label>Procento podle druhu činnosti (jen u výdajů procentem)<select name="expense_percentage"><?php foreach ([60 => '60 % – běžná živnost', 80 => '80 % – řemeslná živnost / zemědělství', 40 => '40 % – jiné samostatné činnosti', 30 => '30 % – nájem majetku v podnikání'] as $rate => $label): ?><option value="<?= $rate ?>" <?= (int) ($taxYearMode['expense_percentage'] ?? 60) === $rate ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
          <label>Pásmo (jen u paušální daně)<select name="flat_tax_band"><?php foreach ([1 => 'I. pásmo', 2 => 'II. pásmo', 3 => 'III. pásmo'] as $band => $label): ?><option value="<?= $band ?>" <?= (int) ($taxYearMode['flat_tax_band'] ?? 1) === $band ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
          <label class="panel-check"><input type="checkbox" name="flat_tax_confirmed" value="1" <?= ($taxYearMode['flat_tax_confirmed'] ?? false) ? 'checked' : '' ?>> Potvrzuji, že jsem pro tento rok skutečně oznámil vstup do paušálního režimu nebo v něm pokračuji. E-shop to neověřuje.</label>
          <p class="panel-help">Při paušální dani se procentní výdaje neuplatňují; druh činnosti slouží k posouzení pásma. Systém nevidí ostatní příjmy, zaměstnání ani DPH a nepočítá nárok nebo výslednou daň. Změna režimu v průběhu roku může vyžadovat samostatné posouzení.</p>
          <button class="panel-button" type="submit">Uložit režim roku <?= (int) $taxYear ?></button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
<?php endif; ?>
