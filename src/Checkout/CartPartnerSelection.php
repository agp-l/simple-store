<?php
declare(strict_types=1);
namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Product\{ProductSalesRepository,PurchasePartnerRepository,PartnerDeliveryCosts};
use SimpleStore\Supplier\{PartnerOfferCatalog,PartnerStockPolicy};

/** Local evidence only: opening a cart never sends HTTP requests to merchants. */
final class CartPartnerSelection
{
    public function __construct(private MeekroDB $db, private ?int $now=null) {}

    /** Multiple languages/lines of the same SKU share one quantity limit and seller. */
    public function select(array $items): array
    {
        $keys=[];
        foreach ($items as $item) if (($item['sale_mode']??'own')==='assisted' && ($item['issue']??null)===null) $keys[]=$item['product_key'];
        if ($keys===[]) return ['items'=>$items,'plan'=>null];
        $catalog=new PartnerOfferCatalog($this->db,$this->now);
        if (!$catalog->installed()) return ['items'=>$items,'plan'=>null];
        $products=array_column($catalog->report(array_values(array_unique($keys)))['products'],null,'product_key');
        $partners=array_column((new PurchasePartnerRepository($this->db))->all(),null,'id');
        $byKey=array_column($partners,null,'partner_key');
        $sales=new ProductSalesRepository($this->db); $settings=[]; $groups=[];
        $rates=(new PartnerDeliveryCosts($this->db))->all();
        foreach ($items as $index=>$item) {
            if (($item['sale_mode']??'own')!=='assisted' || ($item['issue']??null)!==null) continue;
            $key=$item['product_key']; $variant=$item['variant_key']??''; $groupKey=$key.':'.$variant;
            $settings[$key]??=$sales->forProduct($key);
            $primary=$partners[$settings[$key]['partner_id']??0]['partner_key']??'';
            if (!isset($groups[$groupKey])) $groups[$groupKey]=['quantity'=>0,'primary'=>$primary,'indices'=>[],
                'price'=>$item['unit_price_czk'],'ceiling'=>$item['supplier_price_czk'],'product'=>$key,'variant'=>$variant,'offers'=>[]];
            $group=&$groups[$groupKey];
            $group['quantity']+=$item['quantity']; $group['indices'][]=$index;
            $group['price']=min($group['price'],$item['unit_price_czk']);
            $group['ceiling']=min($group['ceiling'],$item['supplier_price_czk']);
            unset($group);
        }
        foreach ($groups as &$group) {
            $group['offers']=self::offers($products[$group['product']]['offers']??[],$byKey,$group['variant'],$group['quantity'],$group['price']);
            $primary=$group['primary'];
            // Preserve manually maintained primary settings only when no current verified observation contradicts them.
            $observed=false;
            foreach ($products[$group['product']]['offers']??[] as $offer) if ($offer['variant_key']===$group['variant'] && $offer['partner_key']===$primary && $offer['status']==='verified') $observed=true;
            if (!$observed && isset($byKey[$primary]) && PurchasePartnerRepository::missing($byKey[$primary])===[]) {
                $group['offers'][$primary]=['cost'=>$group['ceiling'],'priority'=>(int)$byKey[$primary]['priority'],'source'=>null];
            }
            if ($group['offers']===[]) throw new InvalidArgumentException('Pro některou položku není dostupná ověřená partnerská nabídka v požadovaném množství. Uprav košík nebo nás kontaktuj.');
        }
        unset($group);
        $priced=true;
        foreach ($groups as $group) foreach ($group['offers'] as $partner=>$offer) if (!isset($rates[$partner])) $priced=false;
        if (!$priced) foreach ($groups as &$group) {
            $group['offers']=array_filter($group['offers'],static fn(array $offer):bool=>$offer['cost']<=$group['ceiling']);
            if ($group['offers']===[]) throw new InvalidArgumentException('Nákupní cena se změnila. Partnerskou nabídku je potřeba aktualizovat.');
        }
        unset($group);
        $result=PartnerBasketOptimizer::choose($groups,$rates);
        $selected=[]; $purchaseTotals=[]; $retail=0;
        foreach ($result['choices'] as $key=>$partner) {
            $group=$groups[$key];
            $purchaseTotals[$partner]=($purchaseTotals[$partner]??0)+($group['offers'][$partner]['cost_minor']??$group['offers'][$partner]['cost']*100)*$group['quantity'];
            $retail+=$group['price']*100*$group['quantity'];
        }
        if ($result['priced']) {
            $total=array_sum($purchaseTotals);
            foreach ($purchaseTotals as $partner=>$amount) if ($rates[$partner]['free_from']===null || $amount<$rates[$partner]['free_from']*100) $total+=$rates[$partner]['shipping']*100;
            if ($total>$retail) throw new InvalidArgumentException('Pro tento košík nyní nedokážeme zajistit partnerský nákup za uvedenou cenu. Uprav košík nebo nás kontaktuj.');
        }
        foreach ($result['choices'] as $key=>$partner) {
            $group=$groups[$key]; $offer=$group['offers'][$partner];
            $seller=array_intersect_key($byKey[$partner],PurchasePartnerRepository::FIELDS);
            foreach ($group['indices'] as $index) {
                $items[$index]=array_replace($items[$index],AssistedPurchase::snapshot(['mode'=>'assisted','supplier_price_czk'=>$offer['cost'],'seller'=>$seller],$items[$index]['unit_price_czk']));
                $items[$index]['procurement']=['partner_key'=>$partner,'source'=>$offer['source'],'delivery_cost'=>$rates[$partner]??null];
            }
            $selected[$partner]=$seller['name'];
        }
        return ['items'=>$items,'plan'=>['partners'=>array_values($selected),'count'=>count($selected),'priced'=>$result['priced'],'complete'=>$result['complete']]];
    }

    /** Current, exact, consistent observations only. Rounded retail price is never the purchase price. */
    public static function offers(array $offers,array $partners,string $variant,int $quantity,int $price): array
    {
        $grouped=[]; $result=[];
        foreach ($offers as $offer) if ($offer['variant_key']===$variant && $offer['status']==='verified') $grouped[$offer['partner_key']][]=$offer;
        ksort($grouped);
        foreach ($grouped as $partner=>$rows) {
            if (!isset($partners[$partner]) || PurchasePartnerRepository::missing($partners[$partner])!==[]) continue;
            $signatures=array_unique(array_map(static fn(array $o):string=>json_encode([(string)$o['amount'],$o['availability'],$o['reported_quantity']]),$rows));
            if (count($signatures)!==1 || (PartnerStockPolicy::limit($rows[0])??0)<$quantity) continue;
            $amount=$rows[0]['amount'];
            if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount<=0 || (float)$amount>$price) continue;
            $cost=(int)ceil((float)$amount);
            usort($rows,static fn(array $a,array $b):int=>[$a['source_url'],$a['resolved_url']]<=>[$b['source_url'],$b['resolved_url']]);
            $row=$rows[0];
            $result[$partner]=['cost'=>$cost,'cost_minor'=>(int)round((float)$amount*100),'priority'=>(int)($partners[$partner]['priority']??100),
                'source'=>['url'=>$row['resolved_url']?:$row['source_url'],'source_url'=>$row['source_url'],'observed_at'=>$row['observed_at'],'amount_minor'=>(int)round((float)$amount*100)]];
        }
        return $result;
    }

    /** Called under the order's product inventory lock; never silently change a reviewed seller. */
    public function validate(array $items): void
    {
        $guarded=array_values(array_filter($items,static fn(array $i):bool=>isset($i['procurement'])));
        if ($guarded===[]) return;
        $catalog=new PartnerOfferCatalog($this->db,$this->now);
        $products=array_column($catalog->report(array_values(array_unique(array_column($guarded,'product_key'))))['products'],null,'product_key');
        $partners=array_column((new PurchasePartnerRepository($this->db))->all(),null,'partner_key');
        $rates=(new PartnerDeliveryCosts($this->db))->all();
        $quantities=[];
        foreach ($guarded as $item) {
            $key=$item['product_key'].':'.($item['variant_key']??'');
            $quantities[$key]=($quantities[$key]??0)+$item['quantity'];
        }
        foreach ($guarded as $item) {
            $key=$item['product_key']; $variant=$item['variant_key']??'';
            $proof=$item['procurement']; $partner=$partners[$proof['partner_key']]??null;
            $valid=$partner!==null && PurchasePartnerRepository::missing($partner)===[] &&
                array_intersect_key($partner,PurchasePartnerRepository::FIELDS)===$item['seller'] &&
                ($rates[$proof['partner_key']]??null)===$proof['delivery_cost'];
            if ($proof['source']!==null) {
                $offers=self::offers($products[$key]['offers']??[],$partners,$variant,$quantities[$key.':'.$variant],$item['unit_price_czk']);
                $offer=$offers[$proof['partner_key']]??null;
                $valid=$valid && $offer!==null && $offer['source']===$proof['source'] && $offer['cost']===$item['supplier_price_czk'];
            } else {
                $sales=new ProductSalesRepository($this->db);
                $sale=$sales->forProduct($key);
                $snapshot=$sales->snapshot($key,$variant,$item['unit_price_czk']);
                $valid=$valid && (int)($sale['partner_id']??0)===(int)($partner['id']??0) &&
                    ($snapshot['supplier_price_czk']??null)===$item['supplier_price_czk'];
                foreach ($products[$key]['offers']??[] as $offer) if ($offer['variant_key']===$variant && $offer['partner_key']===$proof['partner_key'] && $offer['status']==='verified') $valid=false;
            }
            if (!$valid) throw new InvalidArgumentException('Partnerská nabídka se mezitím změnila. Zkontroluj znovu souhrn objednávky.');
        }
    }
}
