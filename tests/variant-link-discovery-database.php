<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Supplier\{VariantLinkDiscovery,PriceParser,PriceManager};
use SimpleStore\Product\{PurchasePartnerRepository,ProductSupplierLinkRepository,ProductVariantSupplierRepository};
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);$database='simple_store_variant_links_'.bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
try {
 (new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
 $now=1800000000;$url='https://gramino.cz/p/patizon-d-590';$target=$url.'?v_id=blue-m';
 $common=new ProductSupplierLinkRepository($db);$links=new ProductVariantSupplierRepository($db);
 $partnerId=(new PurchasePartnerRepository($db))->save(0,['name'=>'Gramino','website_url'=>'https://gramino.cz','priority'=>'50']);
 $partnerKey=$db->queryFirstField('SELECT partner_key FROM shop_purchase_partners WHERE id=%i',$partnerId);
 $group=['@type'=>'ProductGroup','name'=>'Patizon D 590','url'=>$url,'hasVariant'=>[]];
 foreach(['blue-m'=>'Blue, M','red-m'=>'Red, M','blue-l'=>'Blue, L'] as $id=>$name) $group['hasVariant'][]=['@type'=>'Product','name'=>'Patizon D 590 – '.$name,
  'offers'=>['@type'=>'Offer','price'=>'10000','priceCurrency'=>'CZK','url'=>$url.'?v_id='.$id,'availability'=>'https://schema.org/InStock']];
 $document=static fn(array $g):array=>PriceParser::document('<script type="application/ld+json">'.json_encode($g).'</script>',$url);
 $cache=static function(string $source,array $doc)use($db,&$now):void{$db->replace('shop_supplier_price_cache',['url_hash'=>hash('sha256','product:'.$source),'expires_at'=>$now+86400,
  'data_json'=>json_encode(['document'=>$doc,'observed_at'=>$now])]);};
 $cache($url,$document($group));
 $make=static function(string $mode='assisted',string $choices='{"Barva":"Modrá","Velikost":"M"}',bool $enabled=true)use($db,$common,$links,$url,$partnerId):string{
  $key=bin2hex(random_bytes(16));$db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$key,'language'=>'cs','revision_number'=>1,'slug'=>$key,'active_slug'=>$key,'name'=>'Patizon D590','category'=>'spacaky','subcategory'=>'lehke','price_czk'=>12000,'description'=>'Keep','image_path'=>'','stock_status'=>'on_order','published'=>0]);
  $db->insert('shop_product_sales',['product_key'=>$key,'partner_id'=>$partnerId,'supplier_price_czk'=>9000,'mode'=>$mode,'variant_prices_json'=>'{}']);
  $db->insert('shop_product_inventory',['product_key'=>$key,'available_quantity'=>7]);
  $db->insert('shop_product_variants',['product_key'=>$key,'variant_key'=>bin2hex(random_bytes(16)),'choices_json'=>$choices,'available_quantity'=>11,'price_czk'=>14000,'enabled'=>(int)$enabled]);
  $common->save($key,0,'Gramino',$url);$links->inherit($key);return $key;
 };
 $catalog=static function()use($db):array{$all=[];foreach(['shop_product_revisions','shop_product_variants','shop_product_inventory','shop_product_sales','shop_order_variant_reservations'] as $table)$all[$table]=$db->query('SELECT * FROM %b',$table);return $all;};
 $key=$make();$before=$catalog();$initial=$links->forProduct($key)[0];
 $plan=(new VariantLinkDiscovery($db,$now))->report($key);
 $check($plan['summary']===['exact'=>1]&&$plan['items'][0]['links'][0]['url']===$target,'Read-only plan missed exact match.');
 $check($links->forProduct($key)[0]===$initial,'Preview wrote to the database.');
 $check((new VariantLinkDiscovery($db,$now))->process($key)['changed']===1,'Exact link not saved.');
 $saved=$links->forProduct($key)[0];
 $check($saved['url']===$target&&$saved['verification']==='verified'&&$saved['origin']==='discovered'&&$saved['checked_at']===$now,'Wrong URL, evidence state or observation time.');
 $check($saved['version']===$initial['version']+1&&$saved['inherited_from_hash']===$initial['inherited_from_hash']&&$saved['result']['original_url']===$url,'Lost stable identity or source audit.');
 $check($catalog()===$before,'Discovery changed product, price, stock or reservations.');
 $check((new VariantLinkDiscovery($db,$now))->process($key)['changed']===0&&$links->inherit($key)===0,'Repeat discovery or inheritance is not idempotent.');
 // Reject stale editor version, while a subsequent manual edit remains authoritative.
 try{$links->save($key,$saved['variant_key'],$saved['link_key'],$initial['version'],$partnerKey,'Stale',$url);throw new RuntimeException('Stale edit accepted.');}catch(InvalidArgumentException $expected){}
 $links->save($key,$saved['variant_key'],$saved['link_key'],$saved['version'],$partnerKey,'Manual',$url.'?v_id=red-m');
 (new VariantLinkDiscovery($db,$now))->process($key);$manual=$links->forProduct($key)[0];
 $check($manual['url']===$url.'?v_id=red-m'&&$manual['result']['status']==='manual_review'&&$manual['result']['url']===$target,'Manual edit overwritten or suggestion missing.');
 $check((new VariantLinkDiscovery($db,$now))->process($key)['changed']===0,'Repeated manual suggestion changes versions.');
 // Existing exact target and deletion tombstones must never merge or resurrect links.
 foreach([false,true] as $removed){$k=$make();$original=$links->forProduct($k)[0];
  $id=$links->save($k,$original['variant_key'],'',0,$partnerKey,'Exact',$target);
  if($removed)$links->remove($k,$original['variant_key'],$id,1);
  (new VariantLinkDiscovery($db,$now))->process($k);
  $rows=array_column($links->forProduct($k),null,'link_key');
  $check($rows[$original['link_key']]['url']===$url&&$rows[$original['link_key']]['result']['status']===($removed?'removed_target':'duplicate'),'Target duplicated or deleted link resurrected.');
  $check(count($rows)===($removed?1:2),'Link identity count changed.');
 }
 foreach([['own',true],['assisted',false]] as [$mode,$enabled]){$k=$make($mode,'{"Barva":"Modrá","Velikost":"M"}',$enabled);$old=$links->forProduct($k);
  $check((new VariantLinkDiscovery($db,$now))->process($k)['changed']===0&&$links->forProduct($k)===$old,'Own stock or retired variant changed.');}
 $ambiguous=$make('assisted','{"Velikost":"M"}');(new VariantLinkDiscovery($db,$now))->process($ambiguous);
 $check($links->forProduct($ambiguous)[0]['result']['status']==='ambiguous','Uniform price hid identity ambiguity.');
 $missing=$make('assisted','{"Velikost":"S","Barva":"Modrá"}');(new VariantLinkDiscovery($db,$now))->process($missing);
 $check($links->forProduct($missing)[0]['url']===$url&&$links->forProduct($missing)[0]['verification']==='unverified','Missing size fabricated.');
 $conflict=$make();$alternate=$url.'?v_id=alternate';$different=$group;$different['hasVariant'][0]['offers']['url']=$url.'?v_id=different-blue-m';
 $cache($alternate,$document($different));$common->save($conflict,0,'Other evidence',$alternate);$links->inherit($conflict);
 $check((new VariantLinkDiscovery($db,$now))->report($conflict)['summary']['conflict']===2,'Contradictory exact source URLs accepted.');
 $fresh=$make();(new VariantLinkDiscovery($db,$now))->process($fresh);$now+=86401;
 (new VariantLinkDiscovery($db,$now))->process($fresh);$expired=$links->forProduct($fresh)[0];
 $check($expired['verification']==='unverified'&&$expired['result']['status']==='expired'&&$expired['result']['last_verified']['original_url']===$url,'Expired evidence remained verified or lost audit.');
 $check((new VariantLinkDiscovery($db,$now))->process($fresh)['changed']===0,'Expired evidence update is not idempotent.');
 $cache($url,$document($group));(new VariantLinkDiscovery($db,$now))->process($fresh);$renewed=$links->forProduct($fresh)[0];
 $check($renewed['verification']==='verified'&&$renewed['result']['original_url']===$url&&$renewed['checked_at']===$now,'Renewal lost original source or observation time.');
 // Configuration is opt-in, preserves pricing, respects pause, and never consumes HTTP budget.
 $manager=new PriceManager($db,static function():array{throw new RuntimeException('Discovery issued an HTTP request.');},static fn():int=>$now);
 $manager->settings();$db->update('shop_supplier_price_settings',['options_json'=>'{"pricing_margin":123,"rechecked_version":6}'],'id=1');
 $check(empty($manager->settings()['variant_links_enabled']),'Discovery unexpectedly enabled by default.');
 $manager->configureVariantLinks(true);$check($manager->settings()['pricing_margin']===123,'Discovery toggle changed pricing settings.');
 $manager->configure(false,true);$check($manager->tick()['state']==='paused'&&empty($manager->settings()['variant_links_last_tick']),'Paused discovery ran.');
 $manager->configure(false,false);$before=$catalog();$check($manager->tick()['state']==='working','Bounded discovery did not request another tick.');
 $check(strlen($manager->settings()['variant_links_cursor'])===32&&$catalog()===$before,'Worker cursor or catalog preservation failed.');
 for($i=0;$i<20;$i++){if($manager->tick()['state']!=='working')break;}
 $check(!empty($manager->settings()['variant_links_last_scan'])&&empty($manager->settings()['variant_links_error']),'Bounded discovery did not finish.');
 $manager->configureVariantLinks(false);$check(empty($manager->settings()['variant_links_enabled']),'Discovery cannot be disabled.');
 echo "Variant discovery: preview, exact URLs, manual edits, duplicates, deletions, freshness, isolation and bounded worker passed.\n";
} finally { $admin->query('DROP DATABASE %b',$database); }
