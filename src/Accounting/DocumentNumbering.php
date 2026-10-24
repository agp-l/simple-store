<?php
declare(strict_types=1);
namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Allocation participates in the caller transaction, including the permanent number reservation. */
final class DocumentNumbering
{
    public const KINDS = ['invoice'=>'Faktury','entry'=>'Peněžní zápisy','document'=>'Ostatní doklady'];
    public function __construct(private MeekroDB $db) {}
    public function installed(): bool {
        return (int) $this->db->queryFirstField("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s", 'shop_document_numbers') === 1 && (int) $this->db->queryFirstField("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='shop_tax_entries' AND COLUMN_NAME IN ('document_number','activity')) OR (TABLE_NAME='shop_invoices' AND COLUMN_NAME IN ('delivery_date','paid_date','version','activity')))") === 6;
    }
    public static function format(string $pattern, int $year, int $sequence): string {
        TaxEvidenceRepository::year($year);
        if ($sequence < 1 || $sequence > 999999999 || strlen($pattern)>80 ||
            preg_match('/^[A-Z0-9{}:\/-]+$/D',$pattern)!==1 ||
            preg_match_all('/\{SEQ:([1-9])\}/',$pattern,$matches)!==1) throw new InvalidArgumentException('Formát musí obsahovat právě jednou {SEQ:4} (1–9 číslic). Povolené jsou A–Z, čísla, /, -, {YYYY} a {YY}.');
        $result=str_replace(['{YYYY}','{YY}'],[(string)$year,substr((string)$year,-2)],$pattern);
        $result=preg_replace('/\{SEQ:([1-9])\}/',str_pad((string)$sequence,(int)$matches[1][0],'0',STR_PAD_LEFT),$result);
        if (!is_string($result) || preg_match('/^[A-Z0-9][A-Z0-9\/-]{0,39}$/D',$result)!==1) throw new InvalidArgumentException('Výsledné číslo musí mít nejvýše 40 znaků; zkontroluj zástupné značky.');
        return $result;
    }
    public function all(): array { return $this->db->query('SELECT * FROM shop_document_series ORDER BY kind,id'); }
    public function save(int $id,array $input,int $admin): int {
        $name=TaxRegisterRepository::text($input['name']??'',120);
        $kind=$input['kind']??''; $pattern=TaxRegisterRepository::text($input['pattern']??'',80);
        if ($name==='' || !is_string($kind) || !isset(self::KINDS[$kind])) throw new InvalidArgumentException('Vyplň název a druh číselné řady.');
        self::format($pattern,2026,1);
        $next=null;$counterYear=0;
        if (($input['next_number']??'')!=='') {
            $next=filter_var($input['next_number'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>999999999]]);
            $year=filter_var($input['counter_year']??'',FILTER_VALIDATE_INT);
            if ($next===false || $year===false) throw new InvalidArgumentException('Zadej platné další číslo a rok.');
            TaxEvidenceRepository::year($year); self::format($pattern,$year,$next);
            $counterYear=str_contains($pattern,'{YY')?$year:0;
        }
        // A yearless series has a lifetime counter, so January cannot reuse numbers.
        $fields=['name'=>$name,'kind'=>$kind,'pattern'=>$pattern,'activity'=>TaxRegisterRepository::text($input['activity']??'',120),'is_default'=>($input['is_default']??'')==='1'?1:0];
        $this->db->startTransaction();
        try {
            $old=$id>0?$this->db->queryFirstRow('SELECT * FROM shop_document_series WHERE id=%i FOR UPDATE',$id):null;
            if ($id>0 && ($old===null || (int)($input['version']??0)!==(int)$old['version'])) throw new InvalidArgumentException('Řada se mezitím změnila. Obnov její formulář.');
            if ($old!==null && $old['kind']!==$kind) throw new InvalidArgumentException('Pro jiný druh dokladů založ novou řadu.');
            if ($fields['is_default']) $this->db->query('UPDATE shop_document_series SET is_default=0,version=version+1 WHERE kind=%s AND is_default=1 AND id<>%i',$kind,$id);
            if ($old===null) { $this->db->insert('shop_document_series',$fields); $id=(int)$this->db->insertId(); }
            else $this->db->update('shop_document_series',$fields+['version'=>(int)$old['version']+1],'id=%i',$id);
            $oldCounter=$next!==null?$this->db->queryFirstField('SELECT next_number FROM shop_document_counters WHERE series_id=%i AND calendar_year=%i FOR UPDATE',$id,$counterYear):null;
            if($next!==null)$this->db->query('INSERT INTO shop_document_counters (series_id,calendar_year,next_number) VALUES (%i,%i,%i) ON DUPLICATE KEY UPDATE next_number=VALUES(next_number)',$id,$counterYear,$next);
            if($next!==null){$old=['series'=>$old,'counter_year'=>$counterYear,'next_number'=>$oldCounter];$fields+=['counter_year'=>$counterYear,'next_number'=>$next];}
            $this->db->insert('shop_evidence_events',['entity_kind'=>'series','entity_id'=>$id,'old_json'=>json_encode($old,JSON_THROW_ON_ERROR),'new_json'=>json_encode($fields,JSON_THROW_ON_ERROR),'reason'=>'Nastavení číselné řady','admin_id'=>$admin]);
            $this->db->commit(); return $id;
        } catch(Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function reserve(string $number,int $year): void {
        if (preg_match('/^[A-Z0-9][A-Z0-9\/-]{0,39}$/D',$number)!==1) throw new InvalidArgumentException('Číslo: nejvýše 40 znaků A–Z, číslice, / a -.');
        $this->db->query('INSERT IGNORE INTO shop_document_numbers (document_number,calendar_year) VALUES (%s,%i)',$number,$year);
        if ($this->db->affectedRows()!==1) throw new InvalidArgumentException('Toto číslo již bylo použito. Zvol jiné; historická čísla se znovu nepřidělují.');
    }
    public function allocate(string $kind,int $year,int $seriesId=0): array {
        TaxEvidenceRepository::year($year);
        if (!isset(self::KINDS[$kind])) throw new InvalidArgumentException('Neplatný druh řady.');
        $series=$seriesId>0?$this->db->queryFirstRow('SELECT * FROM shop_document_series WHERE id=%i AND kind=%s FOR UPDATE',$seriesId,$kind):$this->db->queryFirstRow('SELECT * FROM shop_document_series WHERE kind=%s AND is_default=1 ORDER BY id LIMIT 1 FOR UPDATE',$kind);
        if ($seriesId>0 && $series===null) throw new InvalidArgumentException('Vybraná číselná řada neexistuje.');
        if ($series===null) {
            // Lock a permanent per-kind sentinel before creating the first default.
            $sentinel=['invoice'=>0,'entry'=>1,'document'=>2][$kind];
            $this->db->query('INSERT IGNORE INTO shop_document_counters (series_id,calendar_year,next_number) VALUES (0,%i,1)',$sentinel);
            $this->db->queryFirstRow('SELECT * FROM shop_document_counters WHERE series_id=0 AND calendar_year=%i FOR UPDATE',$sentinel);
            $series=$this->db->queryFirstRow('SELECT * FROM shop_document_series WHERE kind=%s AND is_default=1 ORDER BY id LIMIT 1 FOR UPDATE',$kind);
            if ($series===null) {
                $this->db->insert('shop_document_series',['name'=>self::KINDS[$kind],'kind'=>$kind,'pattern'=>['invoice'=>'F{YYYY}-{SEQ:6}','entry'=>'PD{YYYY}-{SEQ:6}','document'=>'D{YYYY}-{SEQ:6}'][$kind],'activity'=>'','is_default'=>1]);
                $series=$this->db->queryFirstRow('SELECT * FROM shop_document_series WHERE id=%i',(int)$this->db->insertId());
            }
        }
        $counterYear=str_contains($series['pattern'],'{YY')?$year:0;
        $initial=1;
        if($kind==='invoice' && $series['pattern']==='F{YYYY}-{SEQ:6}')$initial=max(1,(int)$this->db->queryFirstField('SELECT next_number FROM shop_invoice_sequence WHERE calendar_year=%i',$year));
        $this->db->query('INSERT IGNORE INTO shop_document_counters (series_id,calendar_year,next_number) VALUES (%i,%i,%i)',$series['id'],$counterYear,$initial);
        $next=(int)$this->db->queryFirstField('SELECT next_number FROM shop_document_counters WHERE series_id=%i AND calendar_year=%i FOR UPDATE',$series['id'],$counterYear);
        $attempts=0;
        do {
            if(++$attempts>10000) throw new InvalidArgumentException('Řada překrývá příliš mnoho použitých čísel. Nastav vyšší další číslo nebo odlišný prefix.');
            $number=self::format($series['pattern'],$year,$next++);
            $this->db->query('INSERT IGNORE INTO shop_document_numbers (document_number,series_id,calendar_year) VALUES (%s,%i,%i)',$number,$series['id'],$year);
            $allocated=$this->db->affectedRows()===1;
        } while (!$allocated);
        $this->db->query('UPDATE shop_document_counters SET next_number=%i WHERE series_id=%i AND calendar_year=%i',$next,$series['id'],$counterYear);
        return ['document_number'=>$number,'activity'=>$series['activity']];
    }
}
