<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Media\{MediaLibrary,ProductMediaCleanup};

/** Deletes the catalog identity in every language, never order/accounting snapshots. */
final class ProductRemoval
{
    private const TABLES=[
        'shop_product_revisions','shop_product_sales','shop_product_inventory','shop_product_variants',
        'shop_product_variant_settings','shop_product_supplier_links','shop_product_variant_supplier_links',
        'shop_product_supplier_variant_modes','shop_partner_stock_state','shop_partner_refresh_requests',
        'shop_supplier_price_jobs','shop_supplier_offer_scans','shop_supplier_offers',
    ];
    private const VOLATILE=['shop_partner_stock_state','shop_partner_refresh_requests','shop_supplier_price_jobs','shop_supplier_offer_scans','shop_supplier_offers'];
    public function __construct(private MeekroDB $db, private string $root, private array $languages=['cs']) {}
    public function installed(): bool
    {
        return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN %ls',
            ['shop_removed_product_urls','shop_product_media_cleanup'])===2;
    }
    public function removed(string $language,string $slug): ?array
    {
        return $this->installed()?$this->db->queryFirstRow('SELECT name,removed_at FROM shop_removed_product_urls WHERE language=%s AND slug=%s',$language,$slug):null;
    }
    public function preview(string $key,string $language,int $revision,bool $lock=false): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$key) || !in_array($language,$this->languages,true) || $revision<1) throw new InvalidArgumentException('Neplatný produkt k odstranění.');
        if (!$this->installed()) throw new InvalidArgumentException('Nejdřív aktualizuj tabulky v sekci Databáze.');
        if ($lock) $this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE',$key);
        $state=[]; $counts=[]; $current=[]; $suffix=$lock?' FOR UPDATE':'';
        foreach (self::TABLES as $table) {
            $rows=$this->db->query('SELECT * FROM '.$table.' WHERE product_key=%s'.$suffix,$key);
            $counts[$table]=count($rows);
            if (!in_array($table,self::VOLATILE,true)) {
                usort($rows,static fn(array $a,array $b):int=>strcmp(json_encode($a),json_encode($b)));
                $state[$table]=$rows;
            }
            if ($table==='shop_product_revisions') foreach ($rows as $row) if ($row['active_product_key']!==null) $current[$row['language']]=$row;
        }
        if (!isset($current[$language])) throw new InvalidArgumentException('Produkt už neexistuje.');
        if ((int)$current[$language]['revision_number']!==$revision) throw new InvalidArgumentException('Produkt se mezitím změnil. Otevři znovu aktuální produkt.');
        $files=array_column((new MediaLibrary($this->root))->files('product',$key),'path'); sort($files,SORT_STRING);
        $protected=(new ProductMediaCleanup($this->db,$this->root))->protectedTokens($key,$lock);
        $photos=['delete'=>[],'retain'=>[]];
        foreach ($files as $path) $photos[isset($protected[ProductMediaCleanup::token($path)])?'retain':'delete'][]=$path;
        $orders=(int)$this->db->queryFirstField('SELECT COUNT(*) FROM shop_orders WHERE items_json LIKE %s','%'.$key.'%');
        return ['key'=>$key,'language'=>$language,'revision'=>$revision,'name'=>$current[$language]['name'],
            'current'=>$current,'counts'=>$counts,'photos'=>$photos,'files'=>$files,'orders'=>$orders,
            'hash'=>hash('sha256',json_encode([$state,$files],JSON_THROW_ON_ERROR))];
    }
    public function delete(array $preview): array
    {
        $key=$preview['key']??'';
        $this->db->startTransaction();
        try {
            $current=$this->preview($key,$preview['language']??'',(int)($preview['revision']??0),true);
            if (!is_string($preview['hash']??null) || !hash_equals($current['hash'],$preview['hash'])) throw new InvalidArgumentException('Produkt, varianty nebo fotografie se mezitím změnily. Připrav nový náhled smazání.');
            // Preserve all known historic addresses, including incoming merge redirects.
            $urls=$this->db->query('SELECT language,slug,name FROM shop_product_revisions WHERE product_key=%s AND published=1',$key);
            foreach ($this->db->query('SELECT language,source_slug FROM shop_product_redirects WHERE target_key=%s FOR UPDATE',$key) as $row) $urls[]=['language'=>$row['language'],'slug'=>$row['source_slug'],'name'=>$current['name']];
            foreach ($urls as $url) $this->db->replace('shop_removed_product_urls',['language'=>$url['language'],'slug'=>$url['slug'],'product_key'=>$key,'name'=>$url['name']]);
            $this->db->query('DELETE FROM shop_product_redirects WHERE target_key=%s',$key);
            $this->db->query('DELETE FROM shop_product_relations WHERE product_a=%s OR product_b=%s',$key,$key);
            foreach (['shop_homepage_selections'=>['language'],'shop_category_product_selections'=>['language','category_path']] as $table=>$columns) {
                foreach ($this->db->query('SELECT * FROM '.$table.' FOR UPDATE') as $selection) {
                    $list=json_decode($selection['product_keys_json'],true,32,JSON_THROW_ON_ERROR);
                    $next=array_values(array_filter($list,static fn(string $value):bool=>$value!==$key));
                    if ($next===$list) continue;
                    $where=implode(' AND ',array_map(static fn(string $c):string=>$c.'=%s',$columns));
                    $this->db->update($table,['product_keys_json'=>json_encode($next,JSON_THROW_ON_ERROR)],$where,...array_map(static fn(string $c):string=>$selection[$c],$columns));
                }
            }
            foreach (array_reverse(self::TABLES) as $table) $this->db->query('DELETE FROM '.$table.' WHERE product_key=%s',$key);
            $this->db->replace('shop_product_media_cleanup',['product_key'=>$key,'name'=>$current['name'],
                'files_json'=>json_encode($current['files'],JSON_THROW_ON_ERROR),'result_json'=>'{}']);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
        // Never claim database deletion failed after it committed. Pending file work is durable.
        $cleanup=new ProductMediaCleanup($this->db,$this->root);
        try { return $cleanup->run($key); }
        catch (\Throwable $e) { error_log('Product photo cleanup pending: '.$e->getMessage()); return $cleanup->state($key); }
    }
}
