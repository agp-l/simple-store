<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Product\{ProductFamilyMerge,ProductVariantRepository,PurchasePartnerRepository,ProductSupplierLinkRepository,ProductDetails};
use SimpleStore\Supplier\{PriceManager,PriceParser};
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);$database='simple_store_family_'.bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $run):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe family operation accepted.');};
try{
 (new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
 $family=new ProductFamilyMerge($db);$variants=new ProductVariantRepository($db);$links=new ProductSupplierLinkRepository($db);
 $seller=(new PurchasePartnerRepository($db))->save(0,['name'=>'Gramino','website_url'=>'https://gramino.cz/']);
 $manager=new PriceManager($db,static fn(string $url):array=>['code'=>404,'headers'=>[],'body'=>'']);
 $manager->configurePricing(['pricing_enabled'=>'1','pricing_small_limit'=>'1000','pricing_small_margin'=>'50','pricing_margin'=>'100','pricing_step'=>'100']);
 $keys=[];$ids=[];$url='https://gramino.cz/p/warmpeace-viking-300';$offers=[];
 foreach([170=>4690,195=>5190]as$size=>$cost){
  $key=bin2hex(random_bytes(16));$id=bin2hex(random_bytes(16));$keys[]=$key;$ids[]=$id;$slug='warmpeace-viking-300-'.$size.'-cm';
  $details=['options'=>[],'gallery'=>['images/size-'.$size.'.webp'],'specifications'=>[['name'=>'Hmotnost','value'=>($size===170?'500':'550').' g'],['name'=>'Materiál','value'=>'Nylon']],'sections'=>[]];
  $db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$key,'language'=>'cs','revision_number'=>1,'slug'=>$slug,'active_slug'=>$slug,'name'=>'Warmpeace Viking 300 – '.$size.' cm','brand'=>'Warmpeace','category'=>'spani','subcategory'=>'spacaky','price_czk'=>$cost+110,'summary'=>'Shared summary','description'=>'Shared description','image_path'=>'images/main-'.$size.'.webp','stock_status'=>'in_stock','published'=>1,'details_json'=>json_encode($details)]);
  $db->insert('shop_product_sales',['product_key'=>$key,'partner_id'=>$seller,'supplier_price_czk'=>$cost,'mode'=>'assisted','variant_prices_json'=>'{}']);
  $db->insert('shop_product_inventory',['product_key'=>$key,'available_quantity'=>0]);
  $db->insert('shop_product_variant_settings',['product_key'=>$key,'enabled'=>1,'version'=>1]);
  $db->insert('shop_product_variants',['product_key'=>$key,'variant_key'=>$id,'choices_json'=>json_encode(['Varianta'=>'Modrá / Levá strana']),'available_quantity'=>10,'price_czk'=>null,'enabled'=>1]);
  $links->save($key,0,'Gramino',$url);
  $offers[]=['@type'=>'Product','name'=>'Warmpeace Viking 300 – Blue, '.$size.' cm, Left','offers'=>['@type'=>'Offer','url'=>$url.'?v_id='.$size,'price'=>$cost,'priceCurrency'=>'CZK','availability'=>'https://schema.org/InStock']];
 }
 $group=['@type'=>'ProductGroup','name'=>'Warmpeace Viking 300','url'=>$url,'hasVariant'=>$offers];
 $cache=static function(array $g,int $expires=0)use($db,$url):void{$document=PriceParser::document('<script type="application/ld+json">'.json_encode($g).'</script>',$url);$db->replace('shop_supplier_price_cache',['url_hash'=>hash('sha256','product:'.$url),'expires_at'=>$expires?:time()+3600,'data_json'=>json_encode(['document'=>$document,'observed_at'=>time()])]);};
 $cache($group);$target=$keys[0];$source=$keys[1];
 // A previously repaired target link may have no cache of its own; use a proved family source.
 $db->update('shop_product_supplier_links',['url'=>$url.'?v_id=170'],'product_key=%s',$target);
 $check(count($family->candidates('cs'))===1,'Family not detected.');
 $plan=$family->preview($target,'cs');$check(count($plan['variants'])===2&&array_sum(array_column($plan['variants'],'quantity'))===20,'Invented or lost combinations.');
 $check(array_column($plan['variants'],'price_czk')===[4800,5300],'Per-size pricing wrong.');
 // Never overwrite changes made after the preview.
 $db->update('shop_product_variants',['available_quantity'=>9],'product_key=%s',$source);$reject(fn()=>$family->apply($target,'cs',$plan['expected']));
 $check(count($family->history())===0,'Stale preview performed writes.');$db->update('shop_product_variants',['available_quantity'=>10],'product_key=%s',$source);
 $db->insert('shop_order_variant_reservations',['order_id'=>123,'product_key'=>$source,'variant_key'=>$ids[1],'quantity'=>1,'state'=>'consumed']);
 $reject(fn()=>$family->preview($target,'cs'));$db->query('DELETE FROM shop_order_variant_reservations WHERE order_id=123');
 $db->insert('shop_orders',['order_number'=>'FAMILY-HISTORY','status'=>'completed','total_czk'=>100,'items_json'=>json_encode([['product_key'=>$source]]),'shipping_json'=>'{}']);
 $reject(fn()=>$family->preview($target,'cs'));$db->query('DELETE FROM shop_orders WHERE order_number=%s','FAMILY-HISTORY');
 $cache($group,time()-1);$reject(fn()=>$family->preview($target,'cs'));$bad=$group;array_pop($bad['hasVariant']);$cache($bad);$limited=$family->preview($target,'cs');$check(!$limited['managed_prices']&&array_column($limited['variants'],'price_czk')===[4800,5300],'Incomplete offer invented variant prices.');$cache($group);
 // A known model can be consolidated without guessing any missing variant cost.
 $db->update('shop_product_sales',['supplier_price_czk'=>null],'product_key=%s',$source);$cache($bad);
 $preserve=$family->preview($target,'cs');$check(!$preserve['managed_prices'],'Incomplete price proof enabled automation.');
 $family->apply($target,'cs',$preserve['expected']);
 $check($db->queryFirstField('SELECT supplier_price_czk FROM shop_product_sales WHERE product_key=%s',$target)===null,'Unknown cost inherited another size price.');
 $check((int)$db->queryFirstField('SELECT enabled FROM shop_product_supplier_variant_modes WHERE product_key=%s',$target)===0,'Unverified prices became managed.');
 $family->undo((int)$family->history()[0]['id']);$db->update('shop_product_sales',['supplier_price_czk'=>5190],'product_key=%s',$source);$cache($group);
 $plan=$family->preview($target,'cs');$family->apply($target,'cs',$plan['expected']);$history=$family->history();$mergeId=(int)$history[0]['id'];
 $current=$db->queryFirstRow('SELECT * FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL',$target);
 $check($current['slug']==='warmpeace-viking-300'&&$current['name']==='Warmpeace Viking 300'&&(int)$current['price_czk']===4800,'Parent identity/price wrong.');
 $check((int)$db->queryFirstField('SELECT published FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL',$source)===0,'Source not archived.');
 $check(count($db->query('SELECT * FROM shop_product_redirects WHERE target_key=%s',$target))===2,'Old URLs lost.');
 $active=array_values(array_filter($variants->snapshot($target)['items'],static fn(array $v):bool=>$v['enabled']));
 $check(count($active)===2&&array_sum(array_column($active,'quantity'))===20&&array_column($active,'id')===$ids,'Stock/variant identities not preserved.');
 $details=ProductDetails::decode($current['details_json']);$weight=array_column($details['specifications'],'value','name')['Hmotnost'];
 $check(str_contains($weight,'170 cm: 500 g')&&str_contains($weight,'195 cm: 550 g')&&count($details['gallery'])===3,'Size parameters or photos were lost.');
 $reject(fn()=>$family->apply($target,'cs',$plan['expected']));
 $db->update('shop_product_variants',['available_quantity'=>9],'product_key=%s AND variant_key=%s',$target,$ids[0]);$reject(fn()=>$family->undo($mergeId));$db->update('shop_product_variants',['available_quantity'=>10],'product_key=%s AND variant_key=%s',$target,$ids[0]);
 $family->undo($mergeId);$check((int)$family->history()[0]['undone']===1,'Undo not recorded.');
 foreach($keys as$key){$active=array_filter($variants->snapshot($key)['items'],static fn(array $v):bool=>$v['enabled']);$check(count($active)===1&&array_sum(array_column($active,'quantity'))===10,'Undo doubled/lost stock.');}
 $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_product_redirects')===0,'Undo left invalid redirects.');$reject(fn()=>$family->undo($mergeId));
 // Re-merge after undo, then verify future jobs price each known combination independently.
 $plan=$family->preview($target,'cs');$family->apply($target,'cs',$plan['expected']);
 $group['hasVariant'][0]['offers']['price']=4790;$group['hasVariant'][1]['offers']['price']=5290;$cache($group);
 $db->replace('shop_supplier_price_cache',['url_hash'=>hash('sha256','robots:https://gramino.cz/robots.txt'),'expires_at'=>time()+3600,'data_json'=>'{"body":"","observed_at":0}']);
 $manager->enqueue('one','rapid',$target);
 for($i=0;$i<6;$i++){$manager->tick();$db->update('shop_supplier_price_hosts',['next_at'=>0],'host=%s','gramino.cz');$db->update('shop_supplier_price_jobs',['next_at'=>0],'status=%s','queued');}
 $job=$db->queryFirstRow('SELECT * FROM shop_supplier_price_jobs WHERE product_key=%s ORDER BY id DESC LIMIT 1',$target);
 $check($job['status']==='applied','Merged price job did not apply: '.$job['message']);
 $active=array_values(array_filter($variants->snapshot($target)['items'],static fn(array $v):bool=>$v['enabled']));$check(array_column($active,'price_czk')===[4900,5400],'Later check did not update individual prices.');
 $costs=json_decode($db->queryFirstField('SELECT variant_prices_json FROM shop_product_sales WHERE product_key=%s',$target),true);$check($costs[$ids[0]]===4790&&$costs[$ids[1]]===5290,'Later check lost individual costs.');
 $check($manager->enqueue('one','normal',$source)['added']===0,'Archived source still queued.');
 $snapshot=$variants->snapshot($target);$snapshot['items'][0]['quantity']=8;$variants->save($target,$snapshot);
 $check((int)$db->queryFirstField('SELECT enabled FROM shop_product_supplier_variant_modes WHERE product_key=%s',$target)===1,'Stock edit disabled pricing.');
 $snapshot=$variants->snapshot($target);$snapshot['items'][0]['price_czk']=6000;$variants->save($target,$snapshot);
 $check((int)$db->queryFirstField('SELECT enabled FROM shop_product_supplier_variant_modes WHERE product_key=%s',$target)===0,'Manual price edit did not protect own prices.');
 echo "Product families: exact variants, stale previews, history, per-size parameters/prices, undo and manual overrides passed.\n";
}finally{$db->disconnect();$admin->query('DROP DATABASE %b',$database);$admin->disconnect();}
