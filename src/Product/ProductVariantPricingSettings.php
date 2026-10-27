<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;

/** Explicit admin preference; callers hold the product inventory mutex and transaction. */
final class ProductVariantPricingSettings
{
    public function __construct(private MeekroDB $db) {}
    public function installed():bool
    {
        return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','shop_product_supplier_variant_modes')===1;
    }
    public function mode(string $key):string
    {
        $row=$this->db->queryFirstRow('SELECT enabled FROM shop_product_supplier_variant_modes WHERE product_key=%s',$key);
        return $row===null?'inherit':((int)$row['enabled']?'automatic':'manual');
    }
    public function save(string $key,array $input,array $inventory,string $saleMode,?int $partner):void
    {
        // Old clients must not implicitly disable or enable automatic prices.
        if(!array_key_exists('variant_pricing',$input))return;
        $mode=$input['variant_pricing'];$expected=$input['variant_pricing_expected']??null;
        if(!is_string($mode)||!in_array($mode,['inherit','automatic','manual'],true)||!is_string($expected)||!$this->installed())
            throw new InvalidArgumentException('Obnov nastavení cen variant.');
        $current=$this->mode($key);
        if($expected!==$current)throw new InvalidArgumentException('Režim cen variant se mezitím změnil. Obnov formulář a porovnej nastavení.');
        if($mode===$current)return;
        if($mode==='automatic'&&($saleMode!=='assisted'||!$partner||empty($inventory['enabled'])||!array_filter($inventory['items'],static fn(array $v):bool=>$v['enabled'])))
            throw new InvalidArgumentException('Automatické ceny variant vyžadují asistovaný nákup, vybraného partnera a aktivní variantu.');
        if($mode==='inherit')$this->db->query('DELETE FROM shop_product_supplier_variant_modes WHERE product_key=%s',$key);
        else $this->db->replace('shop_product_supplier_variant_modes',['product_key'=>$key,'enabled'=>$mode==='automatic'?1:0]);
        $queue=new PartnerRefreshQueue($this->db);
        if($mode==='automatic'&&$queue->installed())$queue->request($key,0);
    }
}
