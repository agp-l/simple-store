<?php
declare(strict_types=1);
namespace SimpleStore\Accounting;
use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Deliberate direct correction, retaining both snapshots. Does not rewrite a bank movement. */
final class InvoiceEditor
{
    public function __construct(private MeekroDB $db) {}
    public function save(int $id,array $input,int $admin): void {
        $reason=TaxRegisterRepository::text($input['reason']??'',190);
        if ($admin<1 || $reason==='' || ($input['acknowledged']??'')!=='1') throw new InvalidArgumentException('Uveď důvod a potvrď přímou úpravu vystaveného dokladu.');
        $this->db->startTransaction();
        try {
            $old=$this->db->queryFirstRow('SELECT * FROM shop_invoices WHERE id=%i FOR UPDATE',$id);
            if ($old===null || (int)($input['version']??0)!==(int)$old['version']) throw new InvalidArgumentException('Faktura se mezitím změnila. Načti aktuální verzi.');
            $fields=['issue_date'=>TaxRegisterRepository::date($input['issue_date']??''),'due_date'=>TaxRegisterRepository::date($input['due_date']??''),'version'=>(int)$old['version']+1];
            foreach(['delivery_date','paid_date'] as $key) $fields[$key]=($input[$key]??'')===''?null:TaxRegisterRepository::date($input[$key]);
            foreach(['seller','buyer'] as $party) {
                $values=json_decode($old[$party.'_json'],true,512,JSON_THROW_ON_ERROR);
                foreach(['name','ico','street','city','postal_code','email','phone','bank_account'] as $key) if (isset($input[$party.'_'.$key])) $values[$key]=TaxRegisterRepository::text($input[$party.'_'.$key],190);
                if (($values['name']??'')==='') throw new InvalidArgumentException('Vyplň dodavatele a odběratele.');
                $fields[$party.'_json']=json_encode($values,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if ($party==='seller') $fields['bank_account']=$values['bank_account']??'';
            }
            $items=$input['items']??[];
            if (!is_array($items) || $items===[] || count($items)>500) throw new InvalidArgumentException('Zadej položky faktury.');
            $total=0; $normalized=[];
            $oldItems=json_decode($old['items_json'],true,512,JSON_THROW_ON_ERROR);
            $fields['activity']=TaxRegisterRepository::text($input['activity']??($old['activity']??''),120);
            foreach($items as $index=>$item) {
                if (!is_array($item)) throw new InvalidArgumentException('Neplatná položka.');
                $name=TaxRegisterRepository::text($item['name']??'',255);
                $qty=filter_var($item['quantity']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>99999]]);
                $price=filter_var($item['unit_price_czk']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>9999999]]);
                if ($name==='' || $qty===false || $price===false) throw new InvalidArgumentException('Položky: název, počet a cena v celých Kč.');
                $total+=$qty*$price;
                $normalized[]=array_replace($oldItems[$index]??[],['name'=>$name,'quantity'=>$qty,'unit_price_czk'=>$price]);
            }
            $shipping=filter_var($input['shipping_czk']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>9999999]]);
            if ($shipping===false || $total+$shipping>2147483647) throw new InvalidArgumentException('Částka je příliš vysoká.');
            $fields+=['items_json'=>json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'subtotal_czk'=>$total,'shipping_czk'=>$shipping,'total_czk'=>$total+$shipping,
                'variable_symbol'=>TaxRegisterRepository::text($input['variable_symbol']??'',10)];
            if ($fields['variable_symbol']!=='' && !ctype_digit($fields['variable_symbol'])) throw new InvalidArgumentException('Variabilní symbol obsahuje nejvýše 10 číslic.');
            $this->db->update('shop_invoices',$fields,'id=%i',$id);
            $this->db->insert('shop_evidence_events',['entity_kind'=>'invoice','entity_id'=>$id,'old_json'=>json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'new_json'=>json_encode(array_replace($old,$fields),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'reason'=>$reason,'admin_id'=>$admin]);
            $this->db->commit();
        } catch(Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
