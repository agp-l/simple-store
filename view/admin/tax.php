<?php
declare(strict_types=1);
$taxUrl = $adminUrl . '?section=accounting';
$taxMoney = static fn (mixed $value): string => \SimpleStore\Accounting\TaxMoney::format($value);
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format('Y-m-d');
$taxKind = \SimpleStore\Accounting\TaxEvidenceRepository::KINDS;
$balanceKind = ['receivable'=>'Pohledávka', 'liability'=>'Dluh', 'asset'=>'Majetek'];
$selectedMethod = $taxYearMode['method'] ?? ($taxSettings['expense_method'] ?? 'actual');
?>
<?php if (!$taxReady || !$invoicesReady): ?>
  <p class="panel-error" role="alert">Pro doklady a deník <a href="<?= $escape($adminUrl . '?section=database') ?>">aktualizuj SQL tabulky</a>.</p>
<?php endif; ?>
<?php if ($accountingError !== ''): ?><p class="panel-error" role="alert"><?= $escape($accountingError) ?></p><?php endif; ?>
<?php if (($_GET['saved'] ?? '') === '1'): ?><p class="panel-notice" role="status">Změna byla uložena.</p><?php endif; ?>
<?php if (in_array($accountingTab, ['money', 'balances', 'closing', 'stock', 'invoices'], true)): ?>
<form class="panel-search" method="get" action="<?= $escape($adminUrl) ?>">
  <input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="<?= $escape($accountingTab) ?>">
  <label>Rok evidence<input type="number" name="year" min="2000" max="2100" value="<?= (int) $taxYear ?>"></label>
  <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit rok</button>
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
      <p class="panel-help">Součet zahrnuje jen zapsané peněžní pohyby. Není to součet všech uhrazených objednávek, výpočet daně ani přehled za všechny tvoje činnosti. Chybějící, vrácené a nesprávně zařazené platby ověř podle zdrojových dokladů.<?php if (!($taxYearMode['saved'] ?? false)): ?> Pro tento rok zatím není uložený vlastní režim; zobrazuje se starší obecná volba. <a href="<?= $escape($taxUrl . '&tab=settings&year=' . $taxYear) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('check') ?> Potvrdit režim roku</a>.<?php endif; ?></p>
      <?php if ($selectedMethod === 'flat_tax' && !($taxYearMode['flat_tax_confirmed'] ?? false)): ?><p class="panel-notice">Paušální režim je zatím jen naplánovaný. Potvrď v <a href="<?= $escape($taxUrl . '&tab=settings&year=' . $taxYear) ?>">nastavení roku</a>, že vstup byl skutečně oznámen finančnímu úřadu. E-shop tvou účast neumí ověřit.</p><?php endif; ?>
      <?php if (!\SimpleStore\Accounting\TaxEvidenceRepository::invoiceReady($taxSettings)): ?><p class="panel-notice">Pro vystavování dokladů doplň <a href="<?= $escape($taxUrl . '&tab=settings') ?>">údaje podnikatele</a>.</p><?php endif; ?>
    </section>
    <section class="panel-panel"><h2>Uzávěrkové podklady</h2><p><a href="<?= $escape($taxUrl . '&tab=funds&year=' . $taxYear) ?>">Vyúčtování asistovaného nákupu</a> · <a href="<?= $escape($taxUrl . '&tab=balances&year=' . $taxYear) ?>">Majetek a dluhy</a> · <a href="<?= $escape($taxUrl . '&tab=closing&year=' . $taxYear) ?>">Inventura a úpravy roku</a></p><p class="panel-help">Samostatně zapsané uzávěrkové úpravy: příjmy <?= $taxMoney($taxAdjustments['income'] ?? 0) ?>, výdaje <?= $taxMoney($taxAdjustments['expenses'] ?? 0) ?>. Nejsou zahrnuté do peněžních součtů výše.</p></section>
    <?php require __DIR__ . '/evidence-book.php'; ?>
    <?php if ($selectedMethod === 'flat_tax'): ?>
      <?php require __DIR__ . '/flat-tax-advances.php'; ?>
    <?php endif; ?>
    <details class="panel-panel panel-accounting-more"><summary>Archivní a kontrolní sestavy</summary>
      <p><a href="<?= $escape($taxUrl . '&tab=orders&year=' . $taxYear) ?>">Kontrola potvrzených plateb a oprav</a> slouží k dohledání zásahů v objednávkách; není součtem peněžních příjmů. <a href="<?= $escape($taxUrl . '&tab=stock&year=' . $taxYear) ?>">Starší skladové podklady a CSV</a> ponecháváme pro dříve uložená data. Aktuální kusy se spravují u produktů; tato sestava nenahrazuje inventuru.</p>
    </details>
  <?php elseif ($accountingTab === 'money'): ?>
    <?php require __DIR__ . '/tax-ledger.php'; ?>
  <?php elseif ($accountingTab === 'funds'): ?>
    <?php require __DIR__ . '/tax-funds.php'; ?>
  <?php elseif (in_array($accountingTab, ['balances','closing'], true)): ?>
    <?php require __DIR__ . '/tax-register.php'; ?>
  <?php elseif ($accountingTab === 'stock'): ?>
    <section class="panel-panel"><div class="panel-panel-head"><h2>Sklad produktů a starší podklady</h2><div><a class="panel-button" href="<?= $escape($taxUrl . '&tab=stock&year=' . $taxYear . '&download=stock') ?>">Skladové pohyby CSV</a> <a class="panel-button" href="<?= $escape($taxUrl . '&tab=stock&year=' . $taxYear . '&download=sales') ?>">Prodané kusy CSV</a></div></div>
      <p class="panel-notice">Údaj „Volné kusy v e-shopu“ se čte ze skutečného skladu produktů. Starý ruční přehled níže není propojený s tímto skladem; jeho rozdíl proto <strong>není aktuální dostupnost</strong>. Počet kusů nastavuj na <a href="<?= $escape($adminUrl . '?section=products') ?>">kartě produktu</a>. Historické záznamy a CSV zde zůstávají ke kontrole.</p>
      <p class="panel-help">U skutečných výdajů si k nákupu uchovej dodavatelský doklad a na konci roku fyzicky zjisti skutečný stav zásob. Tento přehled sám neprokazuje hodnotu inventury.</p>
      <form method="get" action="<?= $escape($adminUrl) ?>" class="panel-search"><input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="stock"><label>Najít produkt<input name="search" value="<?= $escape($_GET['search'] ?? '') ?>"></label><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('search') ?> Hledat</button></form>
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
        <?php if ($invoicePreviousUrl !== ''): ?><a href="<?= $escape($invoicePreviousUrl) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('arrow-left') ?> Předchozí</a><?php endif; ?>
        <?php if ($invoiceNextUrl !== ''): ?><a href="<?= $escape($invoiceNextUrl) ?>" class="panel-icon-link"><?= \SimpleStore\Rendering\Icon::render('arrow-right') ?> Další</a><?php endif; ?>
      </nav>
    </section>
    <?php if ($selectedInvoice !== null): ?>
      <section class="panel-panel"><h2>Faktura <?= $escape($selectedInvoice['document_number']) ?></h2>
        <p><a class="panel-icon-link panel-button" href="<?= $escape($taxUrl . '&invoice_id=' . (int) $selectedInvoice['id'] . '&print=1') ?>" target="_blank" rel="noopener noreferrer"><?= \SimpleStore\Rendering\Icon::render('document') ?> Tisk / uložit jako PDF</a> <?php if ($selectedInvoice['order_id'] !== null): ?><a href="<?= $escape($adminUrl . '?section=orders&id=' . (int) $selectedInvoice['order_id']) ?>">Objednávka</a><?php else: ?><span class="panel-help">Objednávka byla smazána; vydaný doklad zůstává uchován.</span><?php endif; ?></p>
        <form class="panel-form" method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="invoices"><input type="hidden" name="action" value="invoice-renumber"><input type="hidden" name="id" value="<?= (int) $selectedInvoice['id'] ?>"><label>Opravené číslo dokladu<input name="document_number" value="<?= $escape($selectedInvoice['document_number']) ?>" maxlength="40" required></label><label>Důvod opravy<input name="reason" minlength="8" maxlength="190" required></label><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('edit') ?> Opravit číslo</button></form>
        <p class="panel-help">Pokud už zákazník dostal původní doklad, opravu s ním sladíš; dříve odeslaný e-mail se zpětně nemění.</p>
        <?php foreach ($invoiceHistory as $change): ?><p><?= $escape($change['old_number']) ?> → <?= $escape($change['new_number']) ?> · <?= $escape($change['created_at']) ?> UTC · <?= $escape($change['reason']) ?></p><?php endforeach; ?>
        <?php if ($selectedInvoice['emailed_at'] === null): ?><form method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="mail"><input type="hidden" name="action" value="invoice-email"><input type="hidden" name="id" value="<?= (int) $selectedInvoice['id'] ?>"><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('mail') ?> Odeslat fakturu e-mailem</button></form><?php endif; ?>
      </section>
      <?php require __DIR__ . '/invoice-corrections.php'; ?>
    <?php endif; ?>
  <?php elseif ($accountingTab === 'mail'): ?>
    <section class="panel-panel"><h2>Oznámení zákazníkům</h2>
      <p class="panel-help">Potvrzení objednávky, změny stavu a faktury se připravují do fronty. Odesílatele a texty nastavíš v <a href="<?= $escape($adminUrl . '?section=settings&tab=mail') ?>">nastavení e-mailů</a>. Plánovač zkouší odmítnuté zprávy nejvýše <?= \SimpleStore\Accounting\OrderMailQueue::MAX_AUTO_ATTEMPTS ?>krát; ruční pokus zůstává dostupný. Přijetí poštovním serverem samo nezaručuje doručení do schránky.</p>
      <p class="panel-help">Zpráva ve stavu <strong>sending</strong> mohla být při výpadku již přijata serverem. Plánovač ji nikdy sám neopakuje. Po 10 minutách zkontroluj log poštovního serveru podle adresy, předmětu a času pokusu; pokud výsledek nelze zjistit, ponech ji k dalšímu dořešení.</p>
      <?php if (($taxSettings['mail_from'] ?? '') === '' && $mailQueue->sender() === ''): ?><p class="panel-notice">Vyplň adresu odesílatele v <a href="<?= $escape($adminUrl . '?section=settings&tab=mail') ?>">nastavení e-mailů</a>.</p><?php endif; ?>
      <?php foreach ($mailRows as $row): ?>
        <div class="panel-order-row">
          <span><strong><?= $escape($row['subject']) ?></strong><small><?= $escape($row['recipient_email']) ?> · pokus <?= $escape($row['attempted_at'] ?? 'dosud neproběhl') ?> UTC</small></span>
          <span><?= $escape($row['state']) ?> · pokusů <?= (int) $row['attempts'] ?><?php if ($row['last_error'] !== null): ?><br><?= $escape($row['last_error']) ?><?php endif; ?>
            <?php if ($row['state'] === 'failed' && (int) $row['attempts'] >= \SimpleStore\Accounting\OrderMailQueue::MAX_AUTO_ATTEMPTS): ?><br>Automatické pokusy vyčerpány.<?php endif; ?>
            <?php if ($row['state'] === 'failed' && $row['next_attempt_at'] !== null && (int) $row['attempts'] < \SimpleStore\Accounting\OrderMailQueue::MAX_AUTO_ATTEMPTS): ?><br>Další pokus po <?= $escape($row['next_attempt_at']) ?> UTC.<?php endif; ?>
          </span>
          <?php if (in_array($row['state'], ['queued', 'failed'], true)): ?>
            <form method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="mail"><input type="hidden" name="action" value="mail-retry"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="panel-button panel-action" type="submit"><?= \SimpleStore\Rendering\Icon::render('mail') ?> Zkusit odeslat</button></form>
          <?php elseif ($row['state'] === 'sending' && !empty($row['stale_sending'])): ?>
            <div><p class="panel-error">Výsledek pokusu není jistý. Ověř přijetí v logu poštovního serveru.</p>
              <form method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="mail"><input type="hidden" name="action" value="mail-reconcile"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="outcome" value="accepted"><label><input type="checkbox" name="mail_checked" value="1" required> Server zprávu přijal; další odeslání by bylo duplicitní.</label><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('check') ?> Označit jako přijatou</button></form>
              <form method="post" action="<?= $escape($taxUrl) ?>"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="tab" value="mail"><input type="hidden" name="action" value="mail-reconcile"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="outcome" value="not_accepted"><label><input type="checkbox" name="mail_checked" value="1" required> Server zprávu nepřijal; opakování je bezpečné.</label><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('refresh') ?> Povolit nový pokus</button></form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
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
        <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit údaje prodávajícího</button>
      </form>
    </section>
    <section class="panel-panel"><h2>Režim pro rok <?= (int) $taxYear ?></h2>
      <p>Každý rok má vlastní volbu. Nastavení v e-shopu <strong>není oznámením finančnímu úřadu</strong> ani ověřením splnění podmínek. <a href="<?= $escape($taxUrl . '&tab=guide&year=' . $taxYear) ?>">Jak vybrat režim a pásmo →</a></p>
      <form class="panel-search" method="get" action="<?= $escape($adminUrl) ?>"><input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="settings"><label>Rok<input type="number" name="year" min="2000" max="2100" value="<?= (int) $taxYear ?>" required></label><button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('eye') ?> Zobrazit rok</button></form>
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
          <button class="panel-action panel-button" type="submit"><?= \SimpleStore\Rendering\Icon::render('save') ?> Uložit režim roku <?= (int) $taxYear ?></button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
<?php endif; ?>
