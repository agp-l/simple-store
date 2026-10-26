<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Supplier\{PriceManager,PartnerStockPolicy};
use SimpleStore\Product\{ProductStockRepository,ProductRepository,ProductSupplierLinkRepository,PurchasePartnerRepository,PartnerRefreshQueue};
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);$database='simple_store_limits_'.bin2hex(random_bytes(5));$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);
$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
try{
 (new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
 $now=time();$calls=[];$responses=[];$clock=static function()use(&$now):int{return $now;};
 $transport=static function(string $url)use(&$calls,&$responses,&$now):array{$calls[]=[$url,$now];return $responses[$url]??['code'=>404,'headers'=>[],'body'=>''];};
 $manager=new PriceManager($db,$transport,$clock);$stock=new ProductStockRepository($db);$queue=new PartnerRefreshQueue($db);
 $partner=(new PurchasePartnerRepository($db))->save(0,['name'=>'Partner','website_url'=>'https://stock.test']);
 $make=static function(string $name,string $mode='assisted')use($db,$partner):string{
  $key=bin2hex(random_bytes(16));$db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$key,'language'=>'cs','slug'=>$name,'active_slug'=>$name,'name'=>$name,'price_czk'=>3000,'description'=>'','image_path'=>'images/test.webp','stock_status'=>'out_of_stock','published'=>1,'revision_number'=>1,'category'=>'batohy']);
  $db->insert('shop_product_inventory',['product_key'=>$key,'available_quantity'=>0]);$db->insert('shop_product_sales',['product_key'=>$key,'mode'=>$mode,'partner_id'=>$partner,'supplier_price_czk'=>1000,'variant_prices_json'=>'{}']);
  (new ProductSupplierLinkRepository($db))->save($key,0,'Partner','https://stock.test/'.$name);return $key;
 };
 $response=static function(string $name,string $state='InStock',?int $quantity=null,int $price=1000):array{
  $offer=['@type'=>'Offer','url'=>'https://stock.test/'.$name,'price'=>$price,'priceCurrency'=>'CZK','availability'=>'https://schema.org/'.$state];if($quantity!==null)$offer['inventoryLevel']=['@type'=>'QuantitativeValue','value'=>$quantity];
  return ['code'=>200,'headers'=>[],'body'=>'<script type="application/ld+json">'.json_encode(['@type'=>'Product','name'=>$name,'offers'=>$offer]).'</script>'];
 };
 $run=static function(int $steps=8)use($manager,&$now,&$calls,$check):void{for($i=0;$i<$steps;$i++){$before=count($calls);$manager->tick();$check(count($calls)-$before<=1,'Multiple HTTP calls per tick.');$now+=30;}};
 $quantity=static fn(string $key):int=>(int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$key);
 $keys=[];
 foreach([['plain','InStock',null,3],['two','InStock',2,2],['last','InStock',1,0],['none','OutOfStock',null,0],['limited','LimitedAvailability',null,0]]as[$name,$state,$count,$expected]){
  $key=$make($name);$keys[$name]=$key;$responses['https://stock.test/'.$name]=$response($name,$state,$count);$manager->enqueue('one','rapid',$key);$run();
  $check($quantity($key)===$expected,'Wrong partner limit for '.$name);
  $status=$db->queryFirstField('SELECT stock_status FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL',$key);
  $check($status===($expected>0?'in_stock':'out_of_stock'),'Quantity/status diverged for '.$name);
 }
 $jump=$make('jump');$responses['https://stock.test/jump']=$response('jump','InStock',2,2000);$manager->enqueue('one','rapid',$jump);$run();
 $check($quantity($jump)===2&&$db->queryFirstField('SELECT status FROM shop_supplier_price_jobs WHERE product_key=%s ORDER BY id DESC LIMIT 1',$jump)==='review','Price review blocked trusted availability.');
 $before=count($calls);$item=['product_key'=>$keys['plain'],'language'=>'cs','quantity'=>3,'sale_mode'=>'assisted'];
 $db->startTransaction();$stock->reserve(998,[$item]);$db->commit();$stock->reserve(999,[$item]);
 $check($quantity($keys['plain'])===3&&count($calls)===$before,'Assisted orders drained stock or made HTTP calls.');
 $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_partner_refresh_requests WHERE product_key=%s',$keys['plain'])===1,'Orders did not coalesce.');
 $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_order_stock_reservations WHERE order_id IN (998,999)')===0,'Assisted order created physical reservation.');
 $generation=$db->queryFirstField('SELECT generation FROM shop_partner_refresh_requests WHERE product_key=%s',$keys['plain']);
 $db->startTransaction();$stock->reserve(1000,[$item]);$db->rollback();
 $check($generation===$db->queryFirstField('SELECT generation FROM shop_partner_refresh_requests WHERE product_key=%s',$keys['plain']),'Rolled-back order leaked refresh.');
 // Request a newer observation; the cached URL cannot be re-fetched inside 15 minutes.
 $lastFetch=0;foreach($calls as[$url,$at])if($url==='https://stock.test/plain')$lastFetch=$at;
 $now=$lastFetch+100;$queue->request($keys['plain'],$now);$before=count($calls);$manager->tick();
 $check(count($calls)===$before,'Post-order refresh bypassed 15 minute URL cooldown.');
 $responses['https://stock.test/plain']=$response('plain','OutOfStock');$now=$lastFetch+901;$run(3);
 $check($quantity($keys['plain'])===0,'Post-order refresh did not discover sold-out partner.');
 $own=$make('own','own');$db->update('shop_product_inventory',['available_quantity'=>3],'product_key=%s',$own);$db->update('shop_product_revisions',['stock_status'=>'in_stock'],'product_key=%s',$own);
 $stock->reserve(1001,[['product_key'=>$own,'language'=>'cs','quantity'=>2]]);$check($quantity($own)===1,'Own stock stopped decrementing.');$stock->release(1001);$check($quantity($own)===3,'Own cancellation did not release stock.');$stock->reopen(1001);$check($quantity($own)===1,'Own reopening did not reserve stock.');
 $check($manager->enqueue('one','rapid',$own)['unconfigured']===1,'Own stock entered partner automation.');
 // Filter zero quantity even when a legacy availability flag says in_stock, before pagination.
 $db->update('shop_product_revisions',['stock_status'=>'in_stock'],'product_key=%s',$keys['none']);
 $catalog=new ProductRepository($db,['cs'],null,50,$stock);$found=[];$offset=0;
 do{$page=$catalog->managementPage('cs',null,'','all',$offset,1,'all',0,'out_of_stock');foreach($page['items']as$p){$check($p['availability_status']==='out_of_stock','Filter and storefront disagree.');$found[]=$p['product_key'];}$offset=$page['nextOffset'];}while($offset!==null);
 $check(in_array($keys['none'],$found,true)&&!in_array($own,$found,true)&&count($found)===count(array_unique($found)),'Availability pagination lost/misclassified products.');
 $check(PartnerStockPolicy::textQuantity('<b>Skladem 2 ks</b>')===2&&PartnerStockPolicy::textQuantity('Skladem > 5 ks')===null&&PartnerStockPolicy::textQuantity('Poslední kus')===1,'Unsafe scoped stock text parsing.');
 $sale=$db->queryFirstRow('SELECT * FROM shop_product_sales WHERE product_key=%s',$keys['two']);
 (new \SimpleStore\Product\ProductSalesRepository($db))->save($keys['two'],'cs',['mode'=>'own','version'=>(string)$sale['version'],'partner_id'=>(string)$partner,'supplier_price_czk'=>'1000']);
 $check($quantity($keys['two'])===0&&$db->queryFirstField('SELECT stock_status FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL',$keys['two'])==='out_of_stock','Partner limit became physical own stock on mode switch.');
 echo "Partner limits, price-review independence, checkout outbox, cooldown, own stock and catalog availability passed.\n";
}finally{$db->disconnect();$admin->query('DROP DATABASE %b',$database);$admin->disconnect();}
