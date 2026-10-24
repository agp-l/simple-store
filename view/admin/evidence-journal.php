<?php
$u=static fn(string $tail):string=>\SimpleStore\Navigation\PanelUrl::escape($taxUrl.$tail);
$filter=['tab'=>'overview','year'=>$taxYear,'q'=>$journalSearch,'activity'=>$journalActivity];
?>
<section class="panel-panel evidence-overview">
  <div class="panel-panel-head"><div><h2>Daňová evidence · <?= (int)$taxYear ?></h2><p>Platby, daňové členění a všechny související doklady na jednom místě.</p></div><a class="panel-button" href="<?= $u('&tab=money&year='.$taxYear.'&new=1#ledger-edit') ?>">+ Peněžní pohyb</a></div>
  <div class="evidence-toolbar"><a class="panel-button" href="<?= $u('&tab=documents&year='.$taxYear) ?>">+ Doklad / bankovní výpis</a><a href="<?= $u('&download=journal-csv&'.http_build_query($filter)) ?>">Stáhnout tabulku CSV</a><a href="<?= $u('&download=documents-zip&year='.$taxYear) ?>">Doklady a tabulka za rok · ZIP</a><a href="<?= $u('&download=documents-zip&scope=all&year='.$taxYear) ?>">Všechny roky · ZIP</a></div>
  <form class="panel-ledger-filters" method="get" action="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl) ?>"><input type="hidden" name="section" value="accounting"><input type="hidden" name="tab" value="overview">
    <label>Rok<input type="number" name="year" min="2000" max="2100" value="<?= (int)$taxYear ?>" required></label>
    <label>Číslo dokladu, popis, protistrana<input name="q" maxlength="150" value="<?= $escape($journalSearch) ?>"></label>
    <label>Činnost / e-shop<select name="activity"><option value="">Všechny činnosti</option><?php foreach($journalActivities as $activity):?><option <?= $journalActivity===$activity?'selected':'' ?>><?= $escape($activity) ?></option><?php endforeach;?></select></label>
    <button class="panel-button">Zobrazit</button>
  </form>
  <dl class="panel-ledger-totals"><?php foreach(['income'=>'Daňové příjmy po vratkách','expense'=>'Daňové výdaje po vratkách','nonincome'=>'Nedaňové příjmy','nonexpense'=>'Nedaňové výdaje'] as $key=>$label):?><div><dt><?= $label ?></dt><dd><?= $taxMoney($journalTotals[$key]??0) ?></dd></div><?php endforeach;?></dl>
  <p class="panel-help">Datum u pohybu = skutečná platba. Doklad bez propojeného pohybu má ve sloupcích „—“ a neovlivňuje součty. Výdaje jsou daňově uplatnitelné podle skutečné povahy a režimu roku; při výdajovém paušálu je neuplatňuj navíc. CSV respektuje filtr; ZIP zahrnuje celý vybraný rok nebo všechny roky.</p>
</section>
<section class="panel-panel evidence-table-panel"><div class="panel-table-wrap" tabindex="0" aria-label="Přehled daňové evidence – posuvná tabulka"><table class="panel-table evidence-journal-table"><thead><tr><th>Datum</th><th>Číslo dokladu</th><th>Popis transakce</th><th>Daňové příjmy</th><th>Daňové výdaje</th><th>Nedaňové výdaje / příjmy</th></tr></thead><tbody>
<?php foreach($journalPage['items'] as $row):$values=\SimpleStore\Accounting\EvidenceJournal::amounts($row);?>
<tr class="<?= $row['kind']==='entry'?'':'evidence-document-row' ?>">
  <td><?= $escape($row['date']) ?><small><?= $row['kind']==='entry'?'Datum platby':'Datum dokladu' ?></small></td>
  <td class="evidence-doc-links">
    <?php if($row['kind']==='entry'):?><a class="evidence-number" href="<?= $u('&tab=money&year='.$taxYear.'&edit_entry='.(int)$row['id'].'#ledger-edit') ?>"><?= $escape($row['document_number']?:'Zápis #'.$row['id']) ?></a><small><a href="<?= $u('&print_entry='.(int)$row['id']) ?>" target="_blank" rel="noopener">Tisk pohybu</a> · <a href="<?= $u('&tab=documents&entry_id='.(int)$row['id'].'&year='.$taxYear) ?>">Připojit doklad</a></small><?php endif;?>
    <?php foreach($row['links']['invoices'] as $invoice):?><a href="<?= $u('&tab=invoices&year='.$taxYear.'&invoice_id='.(int)$invoice['id']) ?>">Faktura <?= $escape($invoice['document_number']) ?></a><?php foreach($invoice['corrections'] as $correction):?><a href="<?= $u('&print_correction='.(int)$correction['id']) ?>" target="_blank" rel="noopener">Oprava <?= $escape($correction['document_number']) ?><?= $correction['voided_at']?' · vyřazená':'' ?></a><?php endforeach;?><?php endforeach;?>
    <?php foreach($row['links']['documents'] as $document):?><a href="<?= $u('&tab=documents&year='.$taxYear.'&document_id='.(int)$document['id']) ?>"><?= $escape($document['document_number']) ?><?= $document['voided_at']?' · vyřazený':'' ?><small><?= $escape(\SimpleStore\Accounting\EvidenceDocumentRepository::KINDS[$document['kind']]??'Doklad') ?></small></a><?php foreach($document['files']??[] as $file):?><small><a href="<?= $u('&evidence_file='.(int)$file['id']) ?>">↳ <?= $escape($file['filename']) ?></a></small><?php endforeach;?><?php endforeach;?>
    <?php if($row['reference']!==''):?><small>Zdroj: <?= $escape($row['reference']) ?></small><?php endif;?>
  </td>
  <td><strong><?= $escape($row['description']) ?></strong><small><?= $escape($row['counterparty']) ?><?= $row['activity']!==''?' · '.$escape($row['activity']):'' ?></small>
    <?php if($row['kind']==='entry'):?><small><?= $escape(\SimpleStore\Accounting\TaxEvidenceRepository::ACCOUNTS[$row['account']]??$row['account']) ?> · <?= $escape($taxKind[$row['tax_kind']]??'') ?></small><?php else:?><small>Doklad bez propojené platby v tomto roce. Částka dokladu <?= $taxMoney($row['amount_czk']) ?>.</small><?php endif;?>
    <?php if($row['order_id']!==null):?><small><a href="<?= \SimpleStore\Navigation\PanelUrl::escape($adminUrl.'?section=orders&id='.(int)$row['order_id']) ?>">Objednávka <?= $escape($row['order_number']?:'#'.$row['order_id']) ?></a></small><?php endif;?>
  </td>
  <td class="evidence-amount evidence-income"><?= $values['income']!==0?$taxMoney(\SimpleStore\Accounting\TaxMoney::decimal($values['income'])):'—' ?></td>
  <td class="evidence-amount evidence-expense"><?= $values['expense']!==0?$taxMoney(\SimpleStore\Accounting\TaxMoney::decimal($values['expense'])):'—' ?></td>
  <td class="evidence-amount"><?php if($values['nonincome']):?><span class="evidence-income">+ <?= $taxMoney(\SimpleStore\Accounting\TaxMoney::decimal($values['nonincome'])) ?></span><?php elseif($values['nonexpense']):?><span class="evidence-expense">− <?= $taxMoney(\SimpleStore\Accounting\TaxMoney::decimal($values['nonexpense'])) ?></span><?php else:?>—<?php endif;?></td>
</tr><?php endforeach;?>
<?php if($journalPage['items']===[]):?><tr><td colspan="6"><p>Ještě tu nejsou žádné pohyby ani doklady. Začni přidáním platby nebo dokladu. <a href="<?= $u('&tab=guide') ?>">Prohlédnout vysvětlené vzory</a></p></td></tr><?php endif;?>
</tbody></table></div><nav class="evidence-toolbar" aria-label="Stránky evidence"><?php if($journalOffset>0):?><a href="<?= $u('&'.http_build_query($filter+['offset'=>max(0,$journalOffset-50)])) ?>">← Předchozí</a><?php endif;?><?php if($journalPage['nextOffset']!==null):?><a href="<?= $u('&'.http_build_query($filter+['offset'=>$journalPage['nextOffset']])) ?>">Další →</a><?php endif;?><span>50 řádků na stránku · export obsahuje celý výběr.</span></nav></section>
<details class="panel-panel"><summary>Co ještě zkontrolovat na konci roku</summary><p>Porovnej pohyby s bankou a pokladnou. Doplň <a href="<?= $u('&tab=balances&year='.$taxYear) ?>">majetek, pohledávky a dluhy</a>, <a href="<?= $u('&tab=closing&year='.$taxYear) ?>">inventuru a uzávěrkové úpravy</a> a <a href="<?= $u('&tab=funds&year='.$taxYear) ?>">svěřené peníze zákazníků</a>. Samotná tabulka nenahrazuje tyto evidence ani daňové přiznání.</p></details>
<?php if($selectedMethod==='flat_tax')require __DIR__.'/flat-tax-advances.php';?>
