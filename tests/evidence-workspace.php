<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Accounting\{DocumentNumbering,EvidenceJournal,EvidenceExamples,EvidenceArchive,EvidenceDocumentRepository};
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $f)use($check):void{try{$f();}catch(InvalidArgumentException){return;}$check(false,'Invalid input accepted');};
$check(DocumentNumbering::format('ESH-{YYYY}-{SEQ:4}',2026,1)==='ESH-2026-0001','Yearly pattern');
$check(DocumentNumbering::format('MAS/{YY}/{SEQ:2}',2027,123)==='MAS/27/123','Sequence must grow beyond minimum width');
foreach(['X','{SEQ:0}','{SEQ:10}','{SEQ:4}-{SEQ:4}','{YYYY}-{NOPE}-{SEQ:4}','../{SEQ:4}','lower-{SEQ:4}',str_repeat('A',40).'{SEQ:4}']as$p)$reject(fn()=>DocumentNumbering::format($p,2026,1));
$check(EvidenceJournal::amounts(['kind'=>'invoice','amount_czk'=>'1800.00'])===['income'=>0,'expense'=>0,'nonincome'=>0,'nonexpense'=>0],'Invoice counted as payment');
foreach(['taxable'=>['income',12215],'deductible'=>['expense',12215],'income_return'=>['income',-12215],'expense_return'=>['expense',-12215]]as$kind=>[$column,$amount])$check(EvidenceJournal::amounts(['kind'=>'entry','tax_kind'=>$kind,'direction'=>'income','amount_czk'=>'122.15'])[$column]===$amount,'Cash cents/return sign');
foreach(['proforma','invoice','receipt','expense','bank_statement','correction']as$kind){$d=EvidenceExamples::document($kind);$d['description']='<script>bad()</script>';$html=EvidenceArchive::render('evidence-document-print',['evidenceDocument'=>$d]);$check(str_contains($html,'VZOR – NENÍ SKUTEČNÝ DOKLAD')&&str_contains($html,'údaj o zápisu podnikatele')&&str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>bad()'),'Unsafe or unmarked sample');}
if(class_exists(finfo::class)){
 $f=EvidenceDocumentRepository::fileData('../výpis.csv',"datum;castka\n2026-10-03;1800\n");$check($f['filename']==='výpis.csv'&&$f['sha256']===hash('sha256',$f['file_data']),'Original identity/hash');
 foreach([['x.php','<?php die();'],['x.pdf','<html>fake</html>'],['x.svg','<svg/>'],["x\n.txt",'hello'],['x.csv','']]as[$name,$bytes])$reject(fn()=>EvidenceDocumentRepository::fileData($name,$bytes));
}
echo "Evidence workspace unit checks passed.\n";
