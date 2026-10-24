<?php
declare(strict_types=1);
namespace SimpleStore\Accounting;
use MeekroDB;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Offline HTML documents, original files, complete CSV and audit records. No secret configuration. */
final class EvidenceArchive
{
    public function __construct(private MeekroDB $db) {}
    public static function render(string $view,array $variables): string {
        extract($variables,EXTR_SKIP);ob_start();
        try { require dirname(__DIR__,2).'/view/admin/'.$view.'.php';return (string)ob_get_clean(); }
        catch(Throwable $e) { ob_end_clean();throw $e; }
    }
    public static function entry(array $entry,array $seller): array {
        return ['kind'=>'entry','document_number'=>$entry['document_number']?:'ZAPIS-'.$entry['id'],'issue_date'=>$entry['entry_date'],'paid_date'=>$entry['entry_date'],
            'description'=>$entry['description'],'amount_czk'=>$entry['amount_czk'],'counterparty'=>$entry['counterparty'],'activity'=>$entry['activity']??'',
            'seller_json'=>json_encode($seller,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'version'=>$entry['version']??1,
            'notes'=>($entry['direction']==='income'?'Příjem':'Výdaj').' · '.(TaxEvidenceRepository::KINDS[$entry['tax_kind']]??$entry['tax_kind']).' · '.(TaxEvidenceRepository::ACCOUNTS[$entry['account']]??$entry['account'])."\nReference: ".$entry['reference']."\n".($entry['valuation_note']??'')];
    }
    public function build(int $year,bool $all=false): array {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Na serveru chybí rozšíření PHP ZIP. Zapni jej v hostingu; CSV a jednotlivé doklady fungují samostatně.');
        TaxEvidenceRepository::year($year);
        $dir=sys_get_temp_dir().'/store-evidence-'.bin2hex(random_bytes(16));
        if (!mkdir($dir,0700)) throw new RuntimeException('Nelze připravit soukromý export.');
        $zip=new ZipArchive();$size=0;$count=0;$opened=false;$ownsTransaction=$this->db->transactionDepth()===0;
        try {
            if($ownsTransaction){$this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->startTransaction();}
            if ($zip->open($dir.'/evidence.zip',ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Nelze vytvořit ZIP.');
            $opened=true;
            $add=function(string $name,string $contents) use($zip,$dir,&$size,&$count):void {
                $size+=strlen($contents);
                if ($size>512*1024*1024 || ++$count>50000) throw new RuntimeException('Archiv je příliš velký pro jeden přenos. Exportuj jednotlivé roky.');
                $file=$dir.'/part-'.$count;
                if (file_put_contents($file,$contents)!==strlen($contents) || !$zip->addFile($file,$name)) throw new RuntimeException('Nepodařilo se zapsat celý archiv.');
            };
            $years=$all?array_map('intval',$this->db->queryFirstColumn('SELECT YEAR(entry_date) y FROM shop_tax_entries UNION SELECT YEAR(issue_date) FROM shop_invoices UNION SELECT YEAR(issue_date) FROM shop_evidence_documents ORDER BY y')):[$year];
            if ($years===[]) $years=[$year];
            $journal=new EvidenceJournal($this->db);$documents=new EvidenceDocumentRepository($this->db);$invoices=new InvoiceRepository($this->db);$tax=new TaxEvidenceRepository($this->db);
            $documentIds=$invoiceIds=$entryIds=[];
            $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $index='<!doctype html><html lang="cs"><meta charset="utf-8"><title>Daňová evidence</title><style>body{font:15px system-ui;margin:30px}table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ccc;padding:10px;text-align:left}small{display:block}a{color:#285c32}</style><h1>Daňová evidence – archiv</h1><p>Částky představují jen zaznamenané peněžní pohyby. Doklady bez zapsané platby mají nulový daňový dopad. Originály a historie jsou v přiložených souborech.</p>';
            foreach($years as $y) {
                $stream=fopen('php://temp','w+b');try {$journal->csv($stream,$y);rewind($stream);$add('evidence-'.$y.'.csv',stream_get_contents($stream));}finally{fclose($stream);}
                $index.='<h2>'.$y.'</h2><p><a href="evidence-'.$y.'.csv">Stáhnout tabulku CSV</a></p><table><tr><th>Datum</th><th>Číslo / doklady</th><th>Popis</th><th>Daňové příjmy</th><th>Daňové výdaje</th><th>Nedaňové + / −</th></tr>';
                $offset=0;
                do {
                    $page=$journal->page($y,$offset,'','',200);
                    foreach($page['items'] as $row) {
                        $links=$journal->links($row);$paths=[];
                        if($row['kind']==='entry') {$entryIds[(int)$row['id']]=true;$paths['zapisy/'.$row['id'].'.html']=$row['document_number']?:'Zápis #'.$row['id'];}
                        foreach($links['documents'] as $d) {$documentIds[(int)$d['id']]=true;$paths['doklady/'.$d['id'].'.html']=$d['document_number'];}
                        foreach($links['invoices'] as $i) {$invoiceIds[(int)$i['id']]=true;$paths['faktury/'.$i['id'].'.html']=$i['document_number'];foreach($i['corrections'] as $c)$paths['opravy/'.$c['id'].'.html']=$c['document_number'];}
                        $a=array_map(static fn($v)=>TaxMoney::format(TaxMoney::decimal($v)),EvidenceJournal::amounts($row));
                        $index.='<tr><td>'.$e($row['date']).'</td><td>';
                        foreach($paths as $path=>$label)$index.='<a href="'.$e($path).'">'.$e($label).'</a><br>';
                        $index.='</td><td>'.$e($row['description']).'<small>'.$e($row['activity']).'</small></td><td>'.$a['income'].'</td><td>'.$a['expense'].'</td><td>+ '.$a['nonincome'].' / − '.$a['nonexpense'].'</td></tr>';
                    }
                    $offset=$page['nextOffset'];
                }while($offset!==null);
                $index.='</table>';
            }
            // Include any document linked to a selected invoice, even when dated in another year.
            foreach(array_keys($invoiceIds) as $id) foreach($this->db->query('SELECT id FROM shop_evidence_documents WHERE invoice_id=%i',$id) as $d)$documentIds[(int)$d['id']]=true;
            foreach(array_keys($entryIds) as $id) $add('zapisy/'.$id.'.html',self::render('evidence-document-print',['evidenceDocument'=>self::entry($tax->entry($id),$tax->settings())]));
            foreach(array_keys($invoiceIds) as $id) {
                $invoice=$invoices->byId($id);
                $html=self::render('invoice-print',['selectedInvoice'=>$invoice,'printAssetBase'=>'../']);
                $add('faktury/'.$id.'.html',$html);
                foreach((new InvoiceCorrectionRepository($this->db))->forInvoice($id) as $c)$add('opravy/'.$c['id'].'.html',self::render('correction-print',['selectedCorrection'=>$c]));
            }
            foreach(array_keys($documentIds) as $id) {
                $html=self::render('evidence-document-print',['evidenceDocument'=>$documents->byId($id)]);
                $fileLinks='<h2>Původní soubory</h2><ul>';
                foreach($documents->files($id) as $f) {
                    $file=$documents->file((int)$f['id']);$path='soubory/'.$f['id'].'.'.strtolower(pathinfo($f['filename'],PATHINFO_EXTENSION));
                    $add($path,$file['file_data']);$fileLinks.='<li><a href="../'.$path.'">'.$e($f['filename']).'</a> · SHA-256 '.$e($f['sha256']).'</li>';
                }
                $add('doklady/'.$id.'.html',str_replace('</body>',$fileLinks.'</ul></body>',$html));
            }
            $add('index.html',$index.($all?'<p><a href="data/index.html">Všechny obchodní záznamy v JSON</a></p>':'').'<p>Historie oprav je ve složce historie. Původní soubory najdete z jednotlivých dokladů.</p></html>');
            foreach(['payment-qr.js','payment-qr.mjs'] as $asset) if(is_file(dirname(__DIR__,2).'/assets/'.$asset))$add('assets/'.$asset,file_get_contents(dirname(__DIR__,2).'/assets/'.$asset));
            // Audit is deliberately complete in the all-history archive, scoped to related records otherwise.
            $auditTables=['shop_evidence_events','shop_tax_entry_events','shop_tax_register_events','shop_invoice_number_events','shop_order_admin_events','shop_order_financial_events','shop_deleted_sale_lines','shop_deleted_shipments'];
            foreach($auditTables as $table) {
                if(!$all && !in_array($table,['shop_evidence_events','shop_tax_entry_events','shop_invoice_number_events'],true))continue;
                $params=[];$where='';
                if(!$all) {
                    if($table==='shop_tax_entry_events') {$ids=array_keys($entryIds);$where=' WHERE entry_id IN %li';}
                    elseif($table==='shop_invoice_number_events'){$ids=array_keys($invoiceIds);$where=' WHERE invoice_id IN %li';}
                    else {
                        $where=' WHERE (entity_kind=%s AND entity_id IN %li) OR (entity_kind=%s AND entity_id IN %li)';
                        $params=['invoice',array_keys($invoiceIds)?:[0],'document',array_keys($documentIds)?:[0]];$ids=null;
                    }
                    if($ids===[])continue;if($ids!==null)$params[]=$ids;
                }
                $offset=0;
                do {$rows=$this->db->query('SELECT * FROM '.$table.$where.' ORDER BY id LIMIT 500 OFFSET %i',...array_merge($params,[$offset]));if($rows!==[])$add('historie/'.$table.'-'.$offset.'.json',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));$offset+=500;}while(count($rows)===500);
            }
            if($all) {
                // Structured business data complements the human-readable archive. Credentials,
                // user accounts and configuration are deliberately outside this export.
                $tables=['shop_orders','shop_order_legal_snapshots','shop_order_tracking','shop_sale_lines',
                    'shop_order_variant_reservations','shop_order_stock_reservations','shop_stock_movements',
                    'shop_invoices','shop_invoice_corrections','shop_tax_entries','shop_tax_balances',
                    'shop_flat_tax_advances','shop_tax_year_regimes','shop_document_series','shop_document_counters',
                    'shop_document_numbers','shop_invoice_sequence','shop_evidence_documents','shop_evidence_links',
                    'shop_after_sales_cases','shop_after_sales_events','shop_packeta_cancelled_shipments',
                    'shop_packeta_shipments','shop_carrier_shipments','shop_comgate_payments','shop_gopay_payments',
                    'shop_btcpay_payments','shop_fio_matches','shop_mail_outbox'];
                $dataLinks='';
                foreach($tables as $table) {
                    $columns=$this->db->queryFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s ORDER BY ORDINAL_POSITION',$table);
                    if($columns===[])continue;
                    $offset=0;$sort=$columns[0];
                    do {
                        $rows=$this->db->query('SELECT * FROM %b ORDER BY %b LIMIT 500 OFFSET %i',$table,$sort,$offset);
                        if($rows!==[]){$name='data/'.$table.'-'.$offset.'.json';$add($name,json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));$dataLinks.='<li><a href="'.$name.'">'.$e($table).' · '.$offset.'</a></li>';}
                        $offset+=500;
                    }while(count($rows)===500);
                }
                $add('data/index.html','<!doctype html><meta charset="utf-8"><h1>Strukturované obchodní záznamy</h1><p>JSON obsahuje i záznamy bez vystaveného dokladu. Toto není úplná obnovitelná záloha databáze; chybí nastavení, účty a katalog.</p><ul>'.str_replace('href="data/','href="',$dataLinks).'</ul>');
            }
            $add('CTI-ME.txt',"Otevřete index.html pro tabulku a odkazy na doklady. CSV obsahuje relativní cesty v archivu. HTML doklady vytiskněte nebo uložte jako PDF z prohlížeče. Soubory/ obsahuje nahrané originály; historie/ původní a nové hodnoty oprav v JSON. Archiv všech let navíc obsahuje data/ s obchodními záznamy. Nejde o úplnou zálohu aplikace. Uchovávejte jej soukromě: obsahuje osobní a finanční údaje.\n");
            if(!$zip->close()) throw new RuntimeException('Archiv se nepodařilo dokončit.');
            $opened=false;
            if($ownsTransaction)$this->db->commit();
            return ['path'=>$dir.'/evidence.zip','directory'=>$dir];
        } catch(Throwable $e) { if($opened){try{$zip->close();}catch(Throwable $ignored){}}if($ownsTransaction && $this->db->transactionDepth()>0)$this->db->rollback();self::cleanup($dir);throw $e; }
    }
    public static function cleanup(string $dir): void { foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir); }
}
