<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use MeekroDB;

/** A marker of automatically verified partner limits, not a second physical warehouse. */
final class PartnerStockState
{
    public function __construct(private MeekroDB $db) {}
    public function installed(): bool
    {
        return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','shop_partner_stock_state')===1;
    }
    /** Inventory mutex must be held; called in the settings transaction. */
    public function changeSeller(string $key, ?array $old, string $mode, ?int $partner): void
    {
        if(!$this->installed())return;
        $changed=($old['mode']??'own')!==$mode || ($mode==='assisted'&&(int)($old['partner_id']??0)!==(int)$partner);
        if(!$changed)return;
        $managed=$this->db->queryFirstField('SELECT product_key FROM shop_partner_stock_state WHERE product_key=%s FOR UPDATE',$key)!==null;
        if($managed){
            $this->db->query('UPDATE shop_product_inventory SET available_quantity=0 WHERE product_key=%s',$key);
            $this->db->query('UPDATE shop_product_variants SET available_quantity=0,stock_status=%s WHERE product_key=%s','out_of_stock',$key);
            $this->db->query('UPDATE shop_product_variant_settings SET version=version+1 WHERE product_key=%s',$key);
            foreach($this->db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL FOR UPDATE',$key)as$row){
                $this->db->update('shop_product_revisions',['active_product_key'=>null,'active_slug'=>null],'id=%i',$row['id']);
                unset($row['id'],$row['saved_at']);$row['revision_number']=(int)$row['revision_number']+1;$row['stock_status']='out_of_stock';$this->db->insert('shop_product_revisions',$row);
            }
            $this->db->query('DELETE FROM shop_partner_stock_state WHERE product_key=%s',$key);
        }
        $queue=new PartnerRefreshQueue($this->db);
        if($mode==='assisted'&&$queue->installed())$queue->request($key,0);
    }
}
