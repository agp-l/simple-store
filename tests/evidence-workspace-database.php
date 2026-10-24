<?php
declare(strict_types=1);
// Disposable CI database only. Tests real transactions, originals, exports and edits.
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Accounting\{DocumentNumbering,EvidenceDocumentRepository,EvidenceJournal,EvidenceArchive,TaxEvidenceRepository,InvoiceEditor,InvoiceRepository,InvoiceCorrectionRepository};
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
$db=ConnectionFactory::create(['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306]);
(new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $f)use($assert):void{try{$f();}catch(InvalidArgumentException){return;}$assert(false,'Invalid or stale write accepted');};
$n=new DocumentNumbering($db);$docs=new EvidenceDocumentRepository($db);$tax=new TaxEvidenceRepository($db);$journal=new EvidenceJournal($db);
$assert($n->installed()&&$docs->installed(),'Migration missing');
$suffix=strtoupper(bin2hex(random_bytes(4)));$year=2095;$activity='Masáže '.$suffix;
$series=$n->save(0,['name'=>'Masáže '.$suffix,'kind'=>'entry','pattern'=>$suffix.'-MAS-{YYYY}-{SEQ:4}','activity'=>$activity,'next_number'=>'150','counter_year'=>'2095'],1);
$base=['entry_date'=>'2095-10-03','direction'=>'income','account'=>'bank','tax_kind'=>'taxable','amount_czk'=>'1800.15','description'=>'=HYPERLINK("bad")','counterparty'=>'Klient <test>','series_id'=>$series,'activity'=>''];
$entry=$tax->addEntry($base);$e=$tax->entry($entry);
$assert($e['document_number']===$suffix.'-MAS-2095-0150'&&$e['activity']===$activity,'Configured counter/activity ignored');
$tax->amendEntry($entry,['version'=>1,'document_number'=>$suffix.'-MANUAL','amount_czk'=>'1800.15'],1,'Oprava interního čísla');
$reject(fn()=>$tax->addEntry(array_replace($base,['document_number'=>$e['document_number']])));
$reject(fn()=>$tax->amendEntry($entry,['version'=>1,'amount_czk'=>'1'],1,'Stará verze'));
$entry2=$tax->addEntry(array_replace($base,['entry_date'=>'2095-10-06','direction'=>'expense','tax_kind'=>'deductible','amount_czk'=>'242.25','description'=>'Obaly']));
$refund=$tax->addEntry(array_replace($base,['entry_date'=>'2095-10-11','direction'=>'expense','tax_kind'=>'income_return','amount_czk'=>'100.10','description'=>'Částečná vratka']));
$assert($tax->entry($entry2)['document_number']===$suffix.'-MAS-2095-0151','Collision changed counter');
$nextYear=$tax->addEntry(array_replace($base,['entry_date'=>'2096-01-01']));
$assert($tax->entry($nextYear)['document_number']===$suffix.'-MAS-2096-0001','Yearly counter did not reset');
$life=$n->save(0,['name'=>'Průběžná '.$suffix,'kind'=>'entry','pattern'=>$suffix.'-L-{SEQ:3}'],1);
$l1=$tax->addEntry(array_replace($base,['series_id'=>$life]));$l2=$tax->addEntry(array_replace($base,['series_id'=>$life,'entry_date'=>'2096-01-01']));
$assert($tax->entry($l1)['document_number']===$suffix.'-L-001'&&$tax->entry($l2)['document_number']===$suffix.'-L-002','Yearless counter reused numbers');
// An outer transaction owns numbering and entries together.
$db->startTransaction();$rollbackEntry=$tax->addEntry(array_replace($base,['description'=>'Rollback']));$rollbackNumber=$tax->entry($rollbackEntry)['document_number'];$db->rollback();
$assert($tax->entry($rollbackEntry)===null && !(int)$db->queryFirstField('SELECT COUNT(*) FROM shop_document_numbers WHERE document_number=%s',$rollbackNumber),'Number reservation escaped rollback');
$seller=['name'=>'Podnikatel test','ico'=>'12345678','street'=>'Ulice 1','city'=>'Praha','postal_code'=>'11000'];
$bytes="datum;castka\n2095-10-03;1800.15\n2095-10-06;-242.25\n";
$input=['kind'=>'bank_statement','issue_date'=>'2095-10-31','amount_czk'=>'0','description'=>'Výpis '.$suffix,'external_number'=>'FIO-10','activity'=>$activity,'entry_ids'=>[$entry,$entry2],'request_key'=>bin2hex(random_bytes(16))];
$doc=$docs->save(0,$input,$seller,1,EvidenceDocumentRepository::fileData('fio.csv',$bytes));
$reject(fn()=>$docs->save(0,$input,$seller,1));
$assert($docs->linkedEntries($doc)===[$entry,$entry2],'One statement cannot link many movements');
$original=$docs->byId($doc);$docs->save($doc,array_replace($original,['entry_ids'=>[$entry,$entry2],'description'=>'Opravený popis','reason'=>'Upřesnění popisu']),$seller,1);
$reject(fn()=>$docs->save($doc,array_replace($original,['reason'=>'Stará verze']),$seller,1));
$files=$docs->files($doc);$assert(count($files)===1&&$docs->file((int)$files[0]['id'])['file_data']===$bytes&&count($docs->history('document',$doc))===2,'Original changed or history missing');
$bad=array_replace($input,['entry_ids'=>[999999999],'request_key'=>bin2hex(random_bytes(16))]);$reject(fn()=>$docs->save(0,$bad,$seller,1));
$assert(!(int)$db->queryFirstField('SELECT COUNT(*) FROM shop_tax_write_requests WHERE request_key=%s',$bad['request_key']),'Failed document consumed replay token');
$unpaid=$docs->save(0,['kind'=>'invoice','issue_date'=>'2095-10-01','amount_czk'=>'250','description'=>'Neuhrazená masáž','activity'=>$activity],$seller,1);
$before=$journal->totals($year,'',$activity);
$assert($before['income']==='1700.05'&&$before['expense']==='242.25','Invoice counted as cash or refund lost');
$rows=$journal->page($year,0,'',$activity)['items'];$assert(count($rows)===4,'Linked statement duplicated table row or unpaid invoice missing');
$match=array_values(array_filter($rows,fn($r)=>(int)$r['id']===$entry&&$r['kind']==='entry'))[0];
$linked=$journal->links($match);$assert((int)$linked['documents'][0]['id']===$doc&&count($linked['documents'][0]['files'])===1,'Statement/original links absent');
$assert(count($journal->page($year,0,'HYPERLINK',$activity)['items'])===1,'Journal search failed');
$csv=fopen('php://temp','w+b');$journal->csv($csv,$year,'',$activity);rewind($csv);$csvText=stream_get_contents($csv);fclose($csv);
$assert(str_contains($csvText,"'=HYPERLINK")&&str_contains($csvText,'doklady/'.$doc.'.html')&&str_contains($csvText,';-100.10;')&&!str_contains($csvText,"'-100.10"),'CSV links, formula escaping or refund lost');
// Editing the original order invoice cannot alter orders or cash.
$db->insert('shop_orders',['order_number'=>'WS-'.$suffix,'status'=>'shipped','customer_email'=>'test@example.test','subtotal_czk'=>100,'shipping_czk'=>0,'total_czk'=>100,'items_json'=>'[]','shipping_json'=>'{}','payment_method'=>'bank_transfer','payment_status'=>'paid']);$order=(int)$db->insertId();
$db->insert('shop_invoices',['order_id'=>$order,'order_number'=>'WS-'.$suffix,'document_number'=>'WS-F-'.$suffix,'issue_date'=>'2095-10-01','due_date'=>'2095-10-10','seller_json'=>json_encode($seller,JSON_THROW_ON_ERROR),'buyer_json'=>'{"name":"Klient"}','items_json'=>'[{"name":"Služba","quantity":1,"unit_price_czk":100}]','subtotal_czk'=>100,'shipping_czk'=>0,'total_czk'=>100,'activity'=>$activity]);$invoice=(int)$db->insertId();
$editor=new InvoiceEditor($db);$edit=['version'=>1,'issue_date'=>'2095-10-01','due_date'=>'2095-10-10','delivery_date'=>'2095-10-03','paid_date'=>'2095-10-05','items'=>[['name'=>'Služba upravená','quantity'=>'2','unit_price_czk'=>'80']],'shipping_czk'=>'0','reason'=>'Překlep v množství','acknowledged'=>'1'];
$editor->save($invoice,$edit,1);$reject(fn()=>$editor->save($invoice,$edit,1));
$assert((int)$db->queryFirstField('SELECT total_czk FROM shop_invoices WHERE id=%i',$invoice)===160&&(int)$db->queryFirstField('SELECT total_czk FROM shop_orders WHERE id=%i',$order)===100&&$journal->totals($year,'',$activity)===$before,'Direct edit changed money/order or lost calculation');
$history=$docs->history('invoice',$invoice);$assert(count($history)===1&&(int)json_decode($history[0]['old_json'],true)['total_czk']===100,'Invoice original snapshot missing');
$credit=(new InvoiceCorrectionRepository($db))->issue($invoice,['amount_czk'=>'20.25','issue_date'=>'2095-10-12','reason'=>'Snížení ceny'],1);
$creditRow=(new InvoiceCorrectionRepository($db))->byId($credit);$reject(fn()=>$tax->addEntry(array_replace($base,['document_number'=>$creditRow['document_number']])));
// Both selected-year and all-history archives must be self-contained and preserve originals.
foreach([false,true]as$all){$archive=(new EvidenceArchive($db))->build($year,$all);try{$zip=new ZipArchive();$assert($zip->open($archive['path'])===true,'ZIP corrupt');$index=$zip->getFromName('index.html');$assert(is_string($index)&&str_contains($index,'doklady/'.$doc.'.html'),'Offline index missing');$assert($zip->getFromName('soubory/'.$files[0]['id'].'.csv')===$bytes,'Archived original differs');$assert(str_contains($zip->getFromName('faktury/'.$invoice.'.html'),'Služba upravená'),'Invoice print missing');$assert(str_contains($zip->getFromName('opravy/'.$credit.'.html'),'20,25'),'Correction print missing');preg_match_all('/href="([^"]+)"/',$index,$links);foreach($links[1]as$link)$assert($zip->locateName(html_entity_decode($link))!==false,'Broken offline link '.$link);$assert($zip->locateName('historie/shop_evidence_events-0.json')!==false,'Missing edit history');if($all)$assert($zip->locateName('data/shop_orders-0.json')!==false&&$zip->locateName('data/shop_tax_balances-0.json')!==false,'Complete business archive omits data');$zip->close();}finally{EvidenceArchive::cleanup($archive['directory']);}}
echo "Evidence workspace database checks passed.\n";
