<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,2).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never {throw new RuntimeException($message);});
$mode=$argv[1]??'money';
$accountingTab=in_array($mode,['draft','edit'],true)?'money':$mode;
$basePath='/';$adminUrl='/admin.php';$csrf='fixture-token';$taxYear=2026;
$taxReady=$invoicesReady=true;$accountingError='';$taxSettings=['legal_form'=>'sole_trader','expense_method'=>'actual'];
$escape=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$taxSummary=['income'=>122.15,'expenses'=>2.5];$ledgerSummary=$taxSummary+['received'=>1800.15,'paid'=>1678];
$taxEditEntry=null;$taxEntriesPreviousUrl=$taxEntriesNextUrl='';$taxEntryHistory=[];$ledgerFilters=[];
$entry=['id'=>12,'entry_date'=>'2026-10-01','direction'=>'income','account'=>'bank','tax_kind'=>'taxable','amount_czk'=>'122.15','description'=>'Odměna za nákup <boty>','counterparty'=>'Eva Nová','reference'=>'BANK-123','order_id'=>8,'purpose'=>'own_receipt','order_number'=>'DB-2026-000008','currency'=>'CZK','foreign_amount'=>null,'valuation_note'=>'','version'=>2];
$taxEntries=[$entry,array_replace($entry,['id'=>13,'direction'=>'expense','purpose'=>'supplier_payment','tax_kind'=>'nondeductible','amount_czk'=>'1678.00','description'=>'Úhrada dodavateli za zákazníka','counterparty'=>'Gramino'])];
if ($mode==='edit') $taxEditEntry=$entry;
if ($mode==='draft') {
  $accountingError='Zkontroluj částku.';
  $_POST=['action'=>'tax-add-entry','description'=>'Neztratit rozpracované údaje','counterparty'=>'Dodavatel & syn','amount_czk'=>'chyba','reference'=>'REF-NEZTRATIT','purpose'=>'manual','entry_date'=>'2026-05-01'];
}
$fundsSearch='';$fundsMode='assisted';$fundsOffset=0;$fundsRefunds=[];
$fundsTotals=['received'=>'1800.15','returned'=>'0.00','held'=>'0.00','supplier_payment'=>'1678.00','manual'=>'0.00'];
$fundsOrder=['id'=>8,'order_number'=>'DB-2026-000008','customer_email'=>'eva@example.test','total_czk'=>1800,'principal_czk'=>1678,'payment_method'=>'bank_transfer','items'=>[['name'=>'Boty','sale_mode'=>'assisted','quantity'=>1,'seller'=>['name'=>'Gramino']]]];
$fundsEntries=$taxEntries;$fundsPage=['items'=>[$fundsOrder+['funds'=>$fundsTotals]],'nextOffset'=>null];
$registerEdit=null;$registerHistory=[];$registerOffset=0;$taxAdjustments=['income'=>'0.00','expenses'=>'250.75'];
$record=['id'=>4,'kind'=>$mode==='closing'?'inventory_stock':'receivable','opened_on'=>'2026-12-31','closed_on'=>null,'amount_czk'=>'250.75','book_value_czk'=>'300.75','description'=>'Zásoby <vlastní>','counterparty'=>'Dodavatel','reference'=>'INV-2026','quantity'=>'2 ks','note'=>'Doložená pořizovací cena.','version'=>1,'voided_at'=>null];
$registerPage=['items'=>[$record],'nextOffset'=>null];
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/panel.css"><link rel="stylesheet" href="/assets/panel-actions.css"><style>body{margin:0}.panel-test{max-width:1250px;margin:auto;padding:20px;min-width:0}*{box-sizing:border-box}</style></head><body class="panel-area"><main class="panel-test"><?php require dirname(__DIR__,2).'/view/admin/tax.php'; ?></main></body></html>
