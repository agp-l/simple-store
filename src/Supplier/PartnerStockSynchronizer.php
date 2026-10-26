<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use MeekroDB;

/** Called in the price worker's transaction after exact identity and current seller checks. */
final class PartnerStockSynchronizer
{
    public function __construct(private MeekroDB $db) {}

    public function apply(string $key, array $snapshot, array $result): array
    {
        $changes=[];$tracked=false;
        if(!(new \SimpleStore\Product\PartnerStockState($this->db))->installed())return [];
        $last=(int)$this->db->queryFirstField('SELECT observed_at FROM shop_partner_stock_state WHERE product_key=%s FOR UPDATE',$key);
        if($last>(int)$result['observed_at'])return [];
        if(($snapshot['sale']['mode']??'own')!=='assisted'||(int)($snapshot['sale']['partner_id']??0)!==(int)$result['partner_id']||!empty($result['fallback'])||!empty($result['uncertain']))return [];
        if(!empty($snapshot['variantSettings']['enabled'])){
            // Uniform prices do NOT prove uniform stock. Every active SKU needs its own offer.
            foreach($snapshot['variants']as$v){
                if(!(int)$v['enabled'])continue;
                $offer=$result['canonical_variant_offers'][$v['variant_key']]??null;
                if(!$offer||empty($offer['stock_evidence_version']))continue;
                $quantity=PartnerStockPolicy::limit($offer);
                if($quantity===null)continue;
                $tracked=true;
                $row=$this->db->queryFirstRow('SELECT available_quantity,stock_status FROM shop_product_variants WHERE product_key=%s AND variant_key=%s FOR UPDATE',$key,$v['variant_key']);
                $status=$quantity>0?'in_stock':'out_of_stock';
                if((int)$row['available_quantity']===$quantity&&$row['stock_status']===$status)continue;
                $this->db->update('shop_product_variants',['available_quantity'=>$quantity,'stock_status'=>$status],'product_key=%s AND variant_key=%s',$key,$v['variant_key']);
                $changes[]=['variant_key'=>$v['variant_key'],'old_quantity'=>(int)$row['available_quantity'],'quantity'=>$quantity,'status'=>$status];
            }
            if($changes!==[])$this->db->query('UPDATE shop_product_variant_settings SET version=version+1 WHERE product_key=%s',$key);
        }else{
            if(empty($result['stock_evidence_version']))return [];
            $quantity=PartnerStockPolicy::limit($result);
            if($quantity===null)return [];
            $tracked=true;
            $old=(int)$this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE',$key);
            $status=$quantity>0?'in_stock':'out_of_stock';
            $changed=$old!==$quantity;
            $this->db->query('UPDATE shop_product_inventory SET available_quantity=%i WHERE product_key=%s',$quantity,$key);
            foreach($snapshot['products']as$product){
                $row=$this->db->queryFirstRow('SELECT * FROM shop_product_revisions WHERE id=%i FOR UPDATE',$product['id']);
                if($row['stock_status']===$status)continue;
                $changed=true;
                $this->db->update('shop_product_revisions',['active_product_key'=>null,'active_slug'=>null],'id=%i',$row['id']);
                unset($row['id'],$row['saved_at']);$row['revision_number']=(int)$row['revision_number']+1;$row['stock_status']=$status;
                $this->db->insert('shop_product_revisions',$row);
            }
            if($changed)$changes[]=['variant_key'=>'','old_quantity'=>$old,'quantity'=>$quantity,'status'=>$status];
        }
        if($tracked)$this->db->replace('shop_partner_stock_state',['product_key'=>$key,'partner_id'=>(int)$result['partner_id'],'observed_at'=>(int)$result['observed_at']]);
        return $changes;
    }
}
