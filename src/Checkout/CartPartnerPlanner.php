<?php
declare(strict_types=1);
namespace SimpleStore\Checkout;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Product\{ProductSalesRepository, PurchasePartnerRepository};
use SimpleStore\Supplier\{PartnerOfferCatalog, PartnerStockPolicy};

/** Read-only basket planning. No remote requests, global seller changes or stock writes. */
final class CartPartnerPlanner
{
    public function __construct(private MeekroDB $db, private ?int $now = null) {}

    public function plan(array $items, bool $lock = false): array
    {
        $keys=[];
        foreach ($items as $item) if (($item['sale_mode']??'own')==='assisted' && ($item['issue']??null)===null) $keys[$item['product_key']]=true;
        $catalog=new PartnerOfferCatalog($this->db,$this->now);
        if ($keys===[] || !$catalog->installed()) return $items;
        $products=array_column($catalog->report(array_keys($keys),$lock)['products'],null,'product_key');
        $partners=[];
        foreach ((new PurchasePartnerRepository($this->db))->all($lock) as $partner) {
            if (PurchasePartnerRepository::missing($partner)===[]) $partners[$partner['partner_key']]=$partner;
        }
        $sales=new ProductSalesRepository($this->db); $settings=[]; $rows=[];
        foreach ($items as $item) {
            $key=$item['product_key'];
            if (!isset($keys[$key]) || ($item['sale_mode']??'own')!=='assisted' || ($item['issue']??null)!==null) continue;
            $settings[$key]??=$sales->forProduct($key,$lock);
            $variant=$item['variant_key']??''; $rowKey=$key.':'.$variant;
            $sale=$settings[$key];
            $budget=$sale['variant_prices'][$variant]??$sale['supplier_price_czk']??null;
            if (!isset($rows[$rowKey])) $rows[$rowKey]=['quantity'=>0,'offers'=>[], 'budget'=>$budget,'price'=>$item['unit_price_czk'],'product_key'=>$key,'variant_key'=>$variant];
            $rows[$rowKey]['quantity']+=$item['quantity'];
            $rows[$rowKey]['price']=min($rows[$rowKey]['price'],$item['unit_price_czk']);
        }
        foreach ($rows as &$row) {
            $sale=$settings[$row['product_key']]; $budget=$row['budget'];
            if (($sale['mode']??'own')!=='assisted' || !is_int($budget) || $budget<1 || $budget>$row['price']) continue;
            $primary='';
            foreach ($partners as $key=>$partner) if ((int)$partner['id']===(int)$sale['partner_id']) $primary=$key;
            if ($primary==='') continue;
            // Preserve the existing published primary offer when independent evidence is
            // still pending. An alternative always requires fresh, exact stock and price.
            $row['offers'][$primary]=['partner_key'=>$primary,'cost'=>$budget,'primary'=>true,'source'=>null];
            // Keep a current source for fulfilment even when the main pricing engine
            // supplied the primary price before the independent scan completed.
            foreach ($products[$row['product_key']]['offers']??[] as $source) {
                if ($source['variant_key']===$row['variant_key'] && $source['partner_key']===$primary) {
                    $row['offers'][$primary]['source']=['partner_key'=>$primary,'source_url'=>$source['source_url'],'resolved_url'=>'','observed_at'=>0];
                    break;
                }
            }
            $observations=[];
            foreach ($products[$row['product_key']]['offers']??[] as $offer) {
                if ($offer['variant_key']!==$row['variant_key'] || $offer['status']!=='verified' || !isset($partners[$offer['partner_key']])) continue;
                $observations[$offer['partner_key']][]=$offer;
            }
            foreach ($observations as $partnerKey=>$offers) {
                // A newer contradictory price/stock result must also disable the fallback.
                unset($row['offers'][$partnerKey]);
                $signatures=array_unique(array_map(static fn(array $o):string=>json_encode([$o['amount'],$o['price_czk'],$o['availability'],$o['reported_quantity']]),$offers));
                if (count($signatures)!==1) continue;
                usort($offers,static fn(array $a,array $b):int=>$a['source_url']<=>$b['source_url']);
                $offer=$offers[0]; $cost=$offer['price_czk'];
                if (!is_int($cost) || $cost<1 || $cost>$budget || (PartnerStockPolicy::limit($offer)??0)<$row['quantity']) continue;
                $row['offers'][$partnerKey]=['partner_key'=>$partnerKey,'cost'=>$cost,'primary'=>$partnerKey===$primary,
                    'source'=>['partner_key'=>$partnerKey,'source_url'=>$offer['source_url'],
                        'resolved_url'=>$offer['resolved_url'],'observed_at'=>$offer['observed_at']]];
            }
        }
        unset($row);
        $eligible=array_filter($rows,static fn(array $r):bool=>$r['offers']!==[]);
        $chosen=PartnerAllocation::choose($eligible)['offers'];
        foreach ($items as &$item) {
            if (($item['sale_mode']??'own')!=='assisted' || ($item['issue']??null)!==null) continue;
            $rowKey=$item['product_key'].':'.($item['variant_key']??'');
            $offer=$chosen[$rowKey]??null;
            if ($offer===null) {
                $item['issue']='Partnerská nabídka se změnila. Produkt nyní není dostupný za uvedených podmínek.';
                $item['line_total_czk']=null;
                continue;
            }
            $seller=array_intersect_key($partners[$offer['partner_key']],PurchasePartnerRepository::FIELDS);
            $item=array_replace($item,AssistedPurchase::snapshot(['mode'=>'assisted','seller'=>$seller,
                'supplier_price_czk'=>$offer['cost'],'delivery_note'=>AssistedPurchase::DELIVERY_POLICY],$item['unit_price_czk']));
            $item['supplier_selection']=$offer['source']??['partner_key'=>$offer['partner_key'],'source_url'=>'','resolved_url'=>'','observed_at'=>0];
        }
        unset($item);
        return $items;
    }

    /** Inside the order transaction. Lock in the same order as catalog/stock writers. */
    public function assertCurrent(array $items): void
    {
        $keys=[];
        foreach ($items as $item) if (($item['sale_mode']??'own')==='assisted') $keys[$item['product_key']]=true;
        if ($keys===[] || !(new PartnerOfferCatalog($this->db))->installed()) return;
        $keys=array_keys($keys); sort($keys,SORT_STRING);
        $stockKeys=array_values(array_unique(array_column($items,'product_key'))); sort($stockKeys,SORT_STRING);
        foreach ($stockKeys as $key) $this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE',$key);
        $published=[];
        foreach ($this->db->query('SELECT product_key,language,published,price_czk,stock_status FROM shop_product_revisions WHERE product_key IN %ls AND active_product_key IS NOT NULL ORDER BY product_key,language FOR UPDATE',$keys) as $product) $published[$product['product_key'].':'.$product['language']]=$product;
        foreach ($items as $item) {
            if (($item['sale_mode']??'own')!=='assisted') continue;
            $product=$published[$item['product_key'].':'.$item['language']]??null;
            // Variant prices/options get their own locking validation in stock.reserve.
            if ($product===null || !(int)$product['published'] || (($item['variant_key']??'')==='' &&
                ((int)$product['price_czk']!==$item['unit_price_czk'] || $product['stock_status']==='out_of_stock'))) {
                throw new InvalidArgumentException('Produkt nebo jeho cena se mezitím změnily. Obnovte košík.');
            }
        }
        $this->db->query('SELECT product_key FROM shop_product_sales WHERE product_key IN %ls ORDER BY product_key FOR UPDATE',$keys);
        $this->db->query('SELECT id FROM shop_purchase_partners ORDER BY id FOR UPDATE');
        $this->db->query('SELECT offer_key FROM shop_supplier_offers WHERE product_key IN %ls ORDER BY offer_key FOR UPDATE',$keys);
        $planned=$this->plan($items,true);
        foreach ($items as $index=>$item) {
            if (($item['sale_mode']??'own')!=='assisted') continue;
            $current=$planned[$index];
            foreach (['seller','supplier_price_czk','assistance_fee_czk','supplier_selection','issue'] as $field) {
                if (($item[$field]??null)!==($current[$field]??null)) throw new InvalidArgumentException('Prodávající, cena nebo dostupnost se mezitím změnily. Zkontrolujte nový souhrn objednávky.');
            }
        }
    }
}
