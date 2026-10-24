<?php
declare(strict_types=1);
namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Originals live in the database, never in a publicly served upload directory. */
final class EvidenceDocumentRepository
{
    public const KINDS=['invoice'=>'Samostatná faktura neplátce','proforma'=>'Výzva k platbě / záloha','receipt'=>'Příjmový / výdajový pokladní doklad','expense'=>'Přijatá faktura / účtenka','bank_statement'=>'Bankovní výpis','supplier'=>'Doklad partnera zákazníkovi','contract'=>'Smlouva / pověření','other'=>'Jiný podklad','correction'=>'Oprava navazujícího dokladu'];
    public function __construct(private MeekroDB $db) {}
    public function installed(): bool { return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN %ls',['shop_evidence_documents','shop_evidence_links','shop_evidence_files','shop_evidence_events','shop_document_numbers'])===5 && (new DocumentNumbering($this->db))->installed(); }
    public function byId(int $id): ?array { return $this->db->queryFirstRow('SELECT * FROM shop_evidence_documents WHERE id=%i',$id); }
    public function files(int $id): array { return $this->db->query('SELECT id,filename,mime_type,file_size,sha256,created_at FROM shop_evidence_files WHERE document_id=%i ORDER BY id',$id); }
    public function file(int $id): ?array { return $this->db->queryFirstRow('SELECT * FROM shop_evidence_files WHERE id=%i',$id); }
    public function history(string $kind,int $id): array { return $this->db->query('SELECT * FROM shop_evidence_events WHERE entity_kind=%s AND entity_id=%i ORDER BY id DESC',$kind,$id); }
    public function linkedEntries(int $id): array { return array_map('intval',$this->db->queryFirstColumn('SELECT entry_id FROM shop_evidence_links WHERE document_id=%i ORDER BY entry_id',$id)); }
    public function recentEntries(): array { return $this->db->query('SELECT id,entry_date,description,amount_czk,document_number FROM shop_tax_entries ORDER BY entry_date DESC,id DESC LIMIT 200'); }
    public static function upload(?array $file): ?array {
        if ($file===null || ($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return null;
        if (($file['error']??null)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Soubor se nepodařilo nahrát. Zkontroluj limit hostingu a vyber jej znovu.');
        $data=file_get_contents($file['tmp_name']);
        if ($data===false || strlen($data)===0 || strlen($data)>10*1024*1024) throw new InvalidArgumentException('Soubor musí mít 1 B až 10 MB.');
        return self::fileData((string)($file['name']??''),$data);
    }
    public static function fileData(string $name,string $data): array {
        $name=basename(str_replace('\\','/',$name));
        if ($name==='' || strlen($name)>190 || preg_match('/[\x00-\x1f\x7f]/',$name) || preg_match('//u',$name)!==1 || strlen($data)>10*1024*1024 || $data==='') throw new InvalidArgumentException('Neplatný název nebo velikost souboru.');
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if (!class_exists(\finfo::class)) throw new \RuntimeException('Hosting potřebuje rozšíření PHP fileinfo pro ověření nahrávaných souborů.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        $allowed=['pdf'=>['application/pdf'],'png'=>['image/png'],'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],
            'csv'=>['text/plain','text/csv','application/csv'],'txt'=>['text/plain'],
            'json'=>['application/json','text/plain'],'xml'=>['application/xml','text/xml','text/plain']];
        if (!isset($allowed[$ext]) || !in_array($mime,$allowed[$ext],true)) throw new InvalidArgumentException('Nahraj PDF, JPG, PNG, CSV, TXT, JSON nebo XML odpovídající svému obsahu.');
        return ['filename'=>$name,'mime_type'=>$mime,'file_size'=>strlen($data),'sha256'=>hash('sha256',$data),'file_data'=>$data];
    }
    public function save(int $id,array $input,array $seller,int $admin,?array $upload=null): int {
        if ($admin<1) throw new InvalidArgumentException('Chybí správce.');
        $kind=$input['kind']??'';
        if (!is_string($kind) || !isset(self::KINDS[$kind])) throw new InvalidArgumentException('Vyber druh dokladu.');
        $date=TaxRegisterRepository::date($input['issue_date']??'');
        $fields=['kind'=>$kind,'issue_date'=>$date,'description'=>TaxRegisterRepository::text($input['description']??'',255),
            'external_number'=>TaxRegisterRepository::text($input['external_number']??'',100),
            'activity'=>TaxRegisterRepository::text($input['activity']??'',120),
            'counterparty'=>TaxRegisterRepository::text($input['counterparty']??'',500),
            'amount_czk'=>TaxMoney::decimal(TaxMoney::cents($input['amount_czk']??'0',true)),
            'notes'=>TaxRegisterRepository::text($input['notes']??'',10000)];
        if ($fields['description']==='') throw new InvalidArgumentException('Doplň popis zboží, služby nebo podkladu.');
        foreach(['delivery_date','due_date','paid_date'] as $key) $fields[$key]=($input[$key]??'')===''?null:TaxRegisterRepository::date($input[$key]);
        foreach(['order_id','invoice_id','related_id'] as $key) {
            $raw=$input[$key]??''; $fields[$key]=$raw==='' || $raw==='0' || $raw===null?null:filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($fields[$key]===false) throw new InvalidArgumentException('Neplatná vazba na objednávku nebo doklad.');
        }
        $entryIds=$input['entry_ids']??[];
        if (!is_array($entryIds) || count($entryIds)>1000) throw new InvalidArgumentException('Neplatný seznam propojených plateb.');
        foreach($entryIds as $key=>$value) { $entryIds[$key]=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if (!$entryIds[$key]) throw new InvalidArgumentException('Neplatná platba.'); }
        $entryIds=array_values(array_unique($entryIds));
        $reason=TaxRegisterRepository::text($input['reason']??'',190);
        if ($id>0 && $reason==='') throw new InvalidArgumentException('U opravy napiš důvod změny.');
        $this->db->startTransaction();
        try {
            if ($id===0) TaxWriteGuard::claim($this->db,$input);
            $old=$id>0?$this->db->queryFirstRow('SELECT * FROM shop_evidence_documents WHERE id=%i FOR UPDATE',$id):null;
            if ($id>0 && ($old===null || (int)$old['version']!==(int)($input['version']??0))) throw new InvalidArgumentException('Doklad se mezitím změnil. Otevři aktuální verzi; rozepsané údaje zůstávají ve formuláři.');
            foreach(['order_id'=>'shop_orders','invoice_id'=>'shop_invoices','related_id'=>'shop_evidence_documents'] as $key=>$table) {
                if ($fields[$key]!==null && (!$old || $old[$key]!=$fields[$key]) && !$this->db->queryFirstField('SELECT id FROM '.$table.' WHERE id=%i',$fields[$key])) throw new InvalidArgumentException('Propojovaný záznam neexistuje.');
            }
            if ($id>0 && $fields['related_id']===$id) throw new InvalidArgumentException('Doklad nemůže opravovat sám sebe.');
            if ($entryIds!==[] && (int)$this->db->queryFirstField('SELECT COUNT(*) FROM shop_tax_entries WHERE id IN %li',$entryIds)!==count($entryIds)) throw new InvalidArgumentException('Některá vybraná platba již neexistuje.');
            $numbers=new DocumentNumbering($this->db);
            $number=TaxRegisterRepository::text($input['document_number']??'',40);
            if ($old===null && $number==='') {
                $allocated=$numbers->allocate($kind==='invoice'?'invoice':'document',(int)substr($date,0,4),(int)($input['series_id']??0));
                $number=$allocated['document_number']; if ($fields['activity']==='') $fields['activity']=$allocated['activity'];
            } elseif ($number!==($old['document_number']??null)) $numbers->reserve($number,(int)substr($date,0,4));
            $fields['document_number']=$number;
            $fields['seller_json']=$old['seller_json']??json_encode($seller,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if (isset($input['seller_name'])) {
                $party=$old===null?$seller:json_decode($old['seller_json'],true,512,JSON_THROW_ON_ERROR);
                foreach(['name','ico','street','city','postal_code','email','phone','bank_account'] as $key) $party[$key]=TaxRegisterRepository::text($input['seller_'.$key]??($party[$key]??''),190);
                $fields['seller_json']=json_encode($party,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            }
            $fields['version']=$old===null?1:(int)$old['version']+1;
            $fields['voided_at']=($input['voided']??'')==='1'?($old['voided_at']??gmdate('Y-m-d H:i:s')):null;
            if ($old===null) { $this->db->insert('shop_evidence_documents',$fields); $id=(int)$this->db->insertId(); }
            else $this->db->update('shop_evidence_documents',$fields,'id=%i',$id);
            $oldLinks=$this->linkedEntries($id);
            $this->db->query('DELETE FROM shop_evidence_links WHERE document_id=%i',$id);
            foreach($entryIds as $entry) $this->db->insert('shop_evidence_links',['document_id'=>$id,'entry_id'=>$entry]);
            if ($upload!==null) $this->db->insert('shop_evidence_files',$upload+['document_id'=>$id]);
            $this->db->insert('shop_evidence_events',['entity_kind'=>'document','entity_id'=>$id,
                'old_json'=>json_encode(['document'=>$old,'entries'=>$oldLinks],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'new_json'=>json_encode(['document'=>$fields,'entries'=>$entryIds,'files'=>$this->files($id)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'reason'=>$reason?:'Založení dokladu','admin_id'=>$admin]);
            $this->db->commit(); return $id;
        } catch(Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
