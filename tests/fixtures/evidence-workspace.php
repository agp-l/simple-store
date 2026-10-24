<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never{throw new RuntimeException($message);});
$mode=$argv[1]??'overview';$accountingTab=$mode==='document-draft'?'documents':$mode;
$basePath='/';$adminUrl='/admin/';$csrf='test-csrf';$taxYear=2026;$taxReady=$invoicesReady=$workspaceReady=true;$accountingError='';
$taxSettings=['name'=>'Jan Podnikatel','ico'=>'12345678','street'=>'Lesní 1','city'=>'Praha','postal_code'=>'11000','expense_method'=>'actual'];
$escape=static fn(mixed $v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$journalSearch=$journalActivity='';$journalOffset=0;$journalActivities=['E-shop Dobrodruzi','Masáže'];$journalTotals=['income'=>'1800.00','expense'=>'242.25','nonincome'=>'1678.00','nonexpense'=>'1678.00'];
$journalPage=['items'=>[],'nextOffset'=>50];
$links=['invoices'=>[['id'=>3,'document_number'=>'ESH-2026-0001','corrections'=>[['id'=>1,'document_number'=>'OPR-2026-0001','voided_at'=>null]]]],'documents'=>[['id'=>5,'document_number'=>'BANK-2026-0010','kind'=>'bank_statement','voided_at'=>null,'files'=>[['id'=>1,'filename'=>'Fio-vypis-2026-10.pdf']]]]];
for($i=0;$i<5;$i++)$journalPage['items'][]=['kind'=>'entry','id'=>$i+1,'date'=>'2026-10-03','document_number'=>'PD-2026-000'.($i+1),'reference'=>'BANK-20952','description'=>$i===0?'Úhrada objednávky – trekové boty, velikost 43':'Platba za asistovaný nákup a doplňky','counterparty'=>'Eva Novotná','activity'=>'E-shop Dobrodruzi','amount_czk'=>$i===0?'1800.00':'1678.00','tax_kind'=>$i===0?'taxable':($i%2?'nontaxable':'nondeductible'),'direction'=>$i%2||$i===0?'income':'expense','account'=>'bank','order_id'=>8,'order_number'=>'DB-2026-000008','links'=>$links];
$journalPage['items'][]=array_replace($journalPage['items'][0],['kind'=>'document','description'=>'Faktura za dosud neuhrazenou masáž','document_number'=>'MAS-2026-0001','activity'=>'Masáže','links'=>['invoices'=>[],'documents'=>[['id'=>9,'document_number'=>'MAS-2026-0001','kind'=>'invoice','voided_at'=>null,'files'=>[]]]]]);
$documentSeries=[['id'=>1,'name'=>'Faktury e-shop','kind'=>'invoice','pattern'=>'ESH-{YYYY}-{SEQ:4}','activity'=>'E-shop Dobrodruzi','is_default'=>1,'version'=>2],['id'=>2,'name'=>'Masáže','kind'=>'invoice','pattern'=>'MAS-{YYYY}-{SEQ:4}','activity'=>'Masáže','is_default'=>0,'version'=>1]];
$evidenceDocument=null;$documentFiles=$documentHistory=$documentEntries=$documentEntryOptions=[];
$documentEntryOptions=[['id'=>1,'entry_date'=>'2026-10-03','document_number'=>'PD-2026-0001','description'=>'Boty a příslušenství','amount_czk'=>'1800.00']];
if($mode==='document-draft'){$accountingError='Zkontroluj částku.';$_POST=['action'=>'evidence-document-save','id'=>'0','description'=>'Neztratit fakturu <boty>','counterparty'=>'Dodavatel & syn','amount_czk'=>'chyba','entry_ids'=>['1'],'external_number'=>'REF-NEZTRATIT','activity'=>'Masáže','issue_date'=>'2026-10-01'];}
$taxYearRegimeReady=true;$taxYearMode=['method'=>'actual','saved'=>true];
$invoiceRows=[];$invoicePreviousUrl=$invoiceNextUrl='';
$resetReport=['database'=>'test','tables'=>['shop_orders'=>4,'shop_invoices'=>2,'shop_evidence_events'=>7],'stock_movements_preserved'=>3,'reservations'=>[],'shop_product_revisions'=>12];
$invoiceEditHistory=[];$selectedInvoice=['id'=>3,'version'=>2,'issue_date'=>'2026-10-04','due_date'=>'2026-10-10','delivery_date'=>'2026-10-04','paid_date'=>'2026-10-03','variable_symbol'=>'1234567890','shipping_czk'=>0,'activity'=>'E-shop Dobrodruzi','seller'=>$taxSettings,'buyer'=>['name'=>'Eva Novotná'],'items'=>[['name'=>'Boty','quantity'=>1,'unit_price_czk'=>1800]]];
if($mode==='invoices')$selectedInvoice=null;
if (($argv[2] ?? '') === 'shell') {
    $site = ['default_language' => 'cs', 'languages' => ['cs']];
    $screen = 'accounting';
    require dirname(__DIR__, 2) . '/view/admin/layout.php';
    exit;
}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/panel.css"><link rel="stylesheet" href="/assets/panel-actions.css"><style>body{margin:0}*{box-sizing:border-box}.fixture-sidebar{padding:24px}.fixture-main{min-width:0;padding:24px 0}</style></head><body class="panel-area"><div class="panel-shell panel-shell--evidence"><aside class="fixture-sidebar"><strong>DOBRODRUZI</strong><p>Administrace</p><p>Evidence podnikání</p><p>Produkty</p><p>Objednávky</p></aside><main class="fixture-main"><?php if($mode==='invoice-edit'){$taxUrl='/admin/?section=accounting';require dirname(__DIR__,2).'/view/admin/invoice-edit.php';}else require dirname(__DIR__,2).'/view/admin/accounting.php';?></main></div></body></html>
