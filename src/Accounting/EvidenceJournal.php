<?php
declare(strict_types=1);
namespace SimpleStore\Accounting;
use InvalidArgumentException;
use MeekroDB;

/** A document can explain many movements. Only movement rows contribute to cash totals. */
final class EvidenceJournal
{
    public function __construct(private MeekroDB $db) {}
    public static function amounts(array $row): array {
        $out=['income'=>0,'expense'=>0,'nonincome'=>0,'nonexpense'=>0];
        if (($row['kind']??'')!=='entry') return $out;
        $amount=TaxMoney::cents((string)$row['amount_czk'],true);
        switch($row['tax_kind']) {
            case 'taxable':$out['income']=$amount;break;
            case 'deductible':$out['expense']=$amount;break;
            case 'income_return':$out['income']=-$amount;break;
            case 'expense_return':$out['expense']=-$amount;break;
            default:$out[$row['direction']==='income'?'nonincome':'nonexpense']=$amount;
        }
        return $out;
    }
    private function query(int $year,string $search,string $activity): array {
        TaxEvidenceRepository::year($year);
        $search=TaxRegisterRepository::text($search,150); $activity=TaxRegisterRepository::text($activity,120);
        $from=$year.'-01-01';$to=($year+1).'-01-01';
        $sql="SELECT * FROM (
            SELECT 'entry' AS kind,e.id,e.entry_date AS date,e.document_number,e.reference,e.description,e.counterparty,e.activity,e.amount_czk,e.tax_kind,e.direction,e.account,e.order_id,e.order_number,e.version,0 AS voided
            FROM shop_tax_entries e WHERE e.entry_date>=%s AND e.entry_date<%s
            UNION ALL
            SELECT 'invoice',i.id,i.issue_date,i.document_number,i.order_number,'Vystavená faktura · platba není v tomto výběru',JSON_UNQUOTE(JSON_EXTRACT(i.buyer_json,'$.name')),i.activity,i.total_czk,'','','',i.order_id,i.order_number,i.version,0
            FROM shop_invoices i WHERE i.issue_date>=%s AND i.issue_date<%s AND NOT EXISTS (SELECT 1 FROM shop_tax_entries e WHERE e.order_id=i.order_id AND e.entry_date>=%s AND e.entry_date<%s)
            UNION ALL
            SELECT 'document',d.id,d.issue_date,d.document_number,d.external_number,d.description,d.counterparty,d.activity,d.amount_czk,'','','',d.order_id,'',d.version,IF(d.voided_at IS NULL,0,1)
            FROM shop_evidence_documents d WHERE d.issue_date>=%s AND d.issue_date<%s AND NOT EXISTS (SELECT 1 FROM shop_evidence_links l JOIN shop_tax_entries e ON e.id=l.entry_id WHERE l.document_id=d.id AND e.entry_date>=%s AND e.entry_date<%s)
            ) journal WHERE 1=1";
        $params=[$from,$to,$from,$to,$from,$to,$from,$to,$from,$to];
        if ($search!=='') {
            $sql.=" AND (document_number LIKE %ss OR reference LIKE %ss OR description LIKE %ss OR counterparty LIKE %ss OR order_number LIKE %ss
                OR (journal.kind='entry' AND (EXISTS (SELECT 1 FROM shop_invoices i WHERE i.order_id=journal.order_id AND i.document_number LIKE %ss)
                    OR EXISTS (SELECT 1 FROM shop_evidence_documents d LEFT JOIN shop_evidence_links l ON l.document_id=d.id
                        WHERE (l.entry_id=journal.id OR d.order_id=journal.order_id OR d.invoice_id IN (SELECT i.id FROM shop_invoices i WHERE i.order_id=journal.order_id))
                        AND (d.document_number LIKE %ss OR d.external_number LIKE %ss)))))";
            array_push($params,$search,$search,$search,$search,$search,$search,$search,$search);
        }
        if ($activity!=='') { $sql.=' AND activity=%s';$params[]=$activity; }
        return [$sql,$params];
    }
    public function page(int $year,int $offset=0,string $search='',string $activity='',int $limit=50): array {
        if ($offset<0 || $offset>10000000 || $limit<1 || $limit>500) throw new InvalidArgumentException('Neplatná stránka evidence.');
        [$sql,$params]=$this->query($year,$search,$activity);
        $rows=$this->db->query($sql.' ORDER BY date DESC,kind,id DESC LIMIT %i OFFSET %i',...array_merge($params,[$limit+1,$offset]));
        return ['items'=>array_slice($rows,0,$limit),'nextOffset'=>count($rows)>$limit?$offset+$limit:null];
    }
    public function totals(int $year,string $search='',string $activity=''): array {
        [$sql,$params]=$this->query($year,$search,$activity);
        return $this->db->queryFirstRow("SELECT
            COALESCE(SUM(CASE tax_kind WHEN 'taxable' THEN amount_czk WHEN 'income_return' THEN -amount_czk ELSE 0 END),0) income,
            COALESCE(SUM(CASE tax_kind WHEN 'deductible' THEN amount_czk WHEN 'expense_return' THEN -amount_czk ELSE 0 END),0) expense,
            COALESCE(SUM(IF(tax_kind='nontaxable',amount_czk,0)),0) nonincome,
            COALESCE(SUM(IF(tax_kind='nondeductible',amount_czk,0)),0) nonexpense
            FROM ($sql) filtered",...$params);
    }
    public function links(array $row): array {
        $documents=[];$invoices=[];
        if ($row['kind']==='entry') {
            $documents=$this->db->query('SELECT DISTINCT d.id,d.document_number,d.kind,d.voided_at FROM shop_evidence_documents d LEFT JOIN shop_evidence_links l ON l.document_id=d.id WHERE l.entry_id=%i OR (d.order_id IS NOT NULL AND d.order_id=%i) OR d.invoice_id IN (SELECT id FROM shop_invoices WHERE order_id=%i)', $row['id'],(int)$row['order_id'],(int)$row['order_id']);
            if ($row['order_id']!==null) $invoices=$this->db->query('SELECT id,document_number FROM shop_invoices WHERE order_id=%i',$row['order_id']);
        } elseif ($row['kind']==='invoice') {
            $invoices=[['id'=>$row['id'],'document_number'=>$row['document_number']]];
            $documents=$this->db->query('SELECT id,document_number,kind,voided_at FROM shop_evidence_documents WHERE invoice_id=%i OR (order_id IS NOT NULL AND order_id=%i)',$row['id'],(int)$row['order_id']);
        } else {
            $document=$this->db->queryFirstRow('SELECT * FROM shop_evidence_documents WHERE id=%i',$row['id']);
            $documents=$document?[$document]:[];
            if($document && $document['invoice_id'])$invoices=$this->db->query('SELECT id,document_number FROM shop_invoices WHERE id=%i',$document['invoice_id']);
        }
        // Show originals and the directly related correction next to the same movement.
        $ids=array_column(array_filter($documents),'id');
        if($ids!==[]) {
            $related=$this->db->query('SELECT id,document_number,kind,voided_at FROM shop_evidence_documents WHERE related_id IN %li OR id IN (SELECT related_id FROM shop_evidence_documents WHERE id IN %li)',$ids,$ids);
            foreach($related as $d)if(!in_array($d['id'],$ids,true)){$documents[]=$d;$ids[]=$d['id'];}
        }
        foreach($documents as &$d)if($d)$d['files']=$this->db->query('SELECT id,filename FROM shop_evidence_files WHERE document_id=%i ORDER BY id',$d['id']);unset($d);
        foreach($invoices as &$invoice) $invoice['corrections']=$this->db->query('SELECT id,document_number,voided_at FROM shop_invoice_corrections WHERE invoice_id=%i ORDER BY id',$invoice['id']);unset($invoice);
        return ['documents'=>array_values(array_filter($documents)),'invoices'=>$invoices];
    }
    public function activities(): array { return $this->db->queryFirstColumn("SELECT activity FROM shop_tax_entries WHERE activity<>'' UNION SELECT activity FROM shop_evidence_documents WHERE activity<>'' UNION SELECT activity FROM shop_invoices WHERE activity<>'' UNION SELECT activity FROM shop_document_series WHERE activity<>'' ORDER BY activity"); }
    public function csv(mixed $stream,int $year,string $search='',string $activity='',string $adminBase=''): void {
        fwrite($stream,"\xEF\xBB\xBF");
        fputcsv($stream,['Datum','Číslo dokladu','Popis transakce','Daňové příjmy Kč','Daňové výdaje Kč','Nedaňové příjmy Kč','Nedaňové výdaje Kč','Činnost','Protistrana','Zdroj','Reference','Objednávka','Podklady','Odkazy na doklady'], ';','"','');
        $offset=0;
        do {
            $page=$this->page($year,$offset,$search,$activity,200);
            foreach($page['items'] as $row) {
                $amounts=array_map([TaxMoney::class,'decimal'],self::amounts($row));$links=$this->links($row);
                 $paths=[];
                $url=static fn(string $path,string $query):string=>$adminBase===''?$path:\SimpleStore\Navigation\PanelUrl::pretty($adminBase.'?section=accounting&'.$query);
                if($row['kind']==='entry')$paths[]=$url('zapisy/'.$row['id'].'.html','print_entry='.$row['id']);
                foreach($links['documents'] as $d)$paths[]=$url('doklady/'.$d['id'].'.html','tab=documents&document_id='.$d['id']);
                foreach($links['invoices'] as $i){$paths[]=$url('faktury/'.$i['id'].'.html','tab=invoices&invoice_id='.$i['id']);foreach($i['corrections'] as $c)$paths[]=$url('opravy/'.$c['id'].'.html','print_correction='.$c['id']);}
                $docNames=array_merge(array_column($links['documents'],'document_number'),array_column($links['invoices'],'document_number'));
                $values=[$row['date'],$row['document_number']?:'Zápis #'.$row['id'],$row['description'],$amounts['income'],$amounts['expense'],$amounts['nonincome'],$amounts['nonexpense'],$row['activity'],$row['counterparty'],$row['kind'],$row['reference'],$row['order_number'],implode(' | ',$docNames),implode(' | ',$paths)];
                // Amount columns are generated integer-cent decimals, never user formulas. Keep returns numeric.
                foreach($values as $index=>$value)if($index<3 || $index>6)$values[$index]=TaxEvidenceRepository::safeCsvCell((string)$value);
                fputcsv($stream,$values,';','"','');
            }
            $offset=$page['nextOffset'];
        } while($offset!==null);
    }
}
