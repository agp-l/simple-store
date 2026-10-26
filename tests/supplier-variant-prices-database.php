<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Supplier\{PriceManager,PriceParser,PriceEvaluation};
use SimpleStore\Product\{PurchasePartnerRepository,ProductVariantSupplierRepository};
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);$database='simple_store_exact_'.bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $run):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe apply accepted.');};
try{
 (new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
 $now=1800000000;$requests=[];$responses=[];
 $transport=static function(string $url)use(&$requests,&$responses):array{$requests[]=$url;return $responses[$url]??['code'=>404,'body'=>'','headers'=>[]];};
 $clock=static function()use(&$now):int{return $now;};
 $manager=new PriceManager($db,$transport,$clock);
 $manager->configurePricing(['pricing_enabled'=>'1','pricing_small_limit'=>'3000','pricing_small_margin'=>'100','pricing_margin'=>'100','pricing_step'=>'100']);
 $partners=new PurchasePartnerRepository($db);$links=new ProductVariantSupplierRepository($db);
 $partnerId=$partners->save(0,['name'=>'Gramino','website_url'=>'https://gramino.cz','priority'=>'10']);
 $partner=$db->queryFirstRow('SELECT * FROM shop_purchase_partners WHERE id=%i',$partnerId);
 $make=static function(string $path)use($db,$links,$partner):array{
  $key=bin2hex(random_bytes(16));$url='https://gramino.cz/p/'.$path;$variants=[];
  $db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$key,'language'=>'cs','revision_number'=>1,'slug'=>$path,'active_slug'=>$path,'name'=>'Patizon D590','category'=>'spacaky','price_czk'=>3000,'description'=>'Keep','image_path'=>'','stock_status'=>'on_order','published'=>0]);
  $db->insert('shop_product_sales',['product_key'=>$key,'partner_id'=>$partner['id'],'supplier_price_czk'=>1000,'mode'=>'assisted','variant_prices_json'=>'{}']);
  $db->insert('shop_product_variant_settings',['product_key'=>$key,'enabled'=>1]);
  foreach(['Blue','Red'] as $color){
   $vk=bin2hex(random_bytes(16));$variants[$color]=$vk;
   $db->insert('shop_product_variants',['product_key'=>$key,'variant_key'=>$vk,'choices_json'=>json_encode(['Barva'=>$color,'Velikost'=>'M']),'enabled'=>1,'available_quantity'=>7]);
   $links->save($key,$vk,'',0,$partner['partner_key'],'Gramino',$url.'?v_id='.strtolower($color));
  }
  return [$key,$url,$variants];
 };
 $response=static function(string $url,array $prices):array{
  $variants=[];foreach($prices as $color=>$price)$variants[]=['@type'=>'Product','name'=>'Patizon D 590 – '.ucfirst($color).', M','offers'=>['@type'=>'Offer','price'=>$price,'priceCurrency'=>'CZK','url'=>$url.'?v_id='.$color,'availability'=>'https://schema.org/InStock']];
  return ['code'=>200,'headers'=>[],'body'=>'<script type="application/ld+json">'.json_encode(['@type'=>'ProductGroup','name'=>'Patizon D 590','url'=>$url,'hasVariant'=>$variants]).'</script>'];
 };
 $sale=static fn(string $key):array=>$db->queryFirstRow('SELECT * FROM shop_product_sales WHERE product_key=%s',$key);
 $job=static fn(string $key):array=>$db->queryFirstRow('SELECT * FROM shop_supplier_price_jobs WHERE product_key=%s ORDER BY id DESC LIMIT 1',$key);
 // A new manager per tick proves all partial work survives worker restarts.
 $run=static function(int $n=12)use($db,$transport,$clock,&$now,&$requests,$check):void{
  for($i=0;$i<$n;$i++){$before=count($requests);(new PriceManager($db,$transport,$clock))->tick();$check(count($requests)-$before<=1,'More than one request in a tick.');$now+=4;}
 };
 [$key,$url,$v]=$make('exact');
 $responses[PriceParser::requestUrl($url.'?v_id=blue')]=$response($url,['blue'=>1000]);
 $responses[PriceParser::requestUrl($url.'?v_id=red')]=$response($url,['red'=>1200]);
 $check($manager->enqueue('one','rapid',$key)['added']===1,'Variant links without common links did not enqueue.');
 $manager->tick();$now+=4;$manager->tick();
 $check($sale($key)['variant_prices_json']==='{}','Partial set changed product cost.');
 $run();$j=$job($key);$result=json_decode($j['result_json'],true);
 $check($j['status']==='applied','Complete exact offers not applied: '.$j['message']);
 $costs=json_decode($sale($key)['variant_prices_json'],true);
 $check($costs[$v['Blue']]===1000&&$costs[$v['Red']]===1200,'Different costs collapsed or mapped to wrong sizes.');
 $check(count($result['canonical_variant_offers'])===2&&$result['identity_evidence']['method']==='variant_partner_links','Per-link evidence absent.');
 $check((int)$db->queryFirstField('SELECT MIN(available_quantity) FROM shop_product_variants WHERE product_key=%s',$key)===7,'Remote availability rewrote physical quantity.');
 // Missing evidence is never manually approvable, nor filled from another variant.
 [$missing,$mu]=$make('missing');$responses[PriceParser::requestUrl($mu.'?v_id=blue')]=$response($mu,['blue'=>1100]);
 $before=$sale($missing);$manager->enqueue('one','rapid',$missing);$run();
 $check($job($missing)['status']==='failed'&&$sale($missing)===$before,'Incomplete set changed prices.');$reject(fn()=>$manager->apply((int)$job($missing)['id'],true));
 // A cached model may prove exact deep links without new remote requests.
 [$cached,$cu]=$make('cached');$doc=PriceParser::document($response($cu,['blue'=>1000,'red'=>1200])['body'],$cu);
 $db->insert('shop_product_supplier_links',['product_key'=>$cached,'label'=>'Model','url'=>$cu]);
 $db->insert('shop_supplier_price_cache',['url_hash'=>hash('sha256','product:'.$cu),'expires_at'=>$now+86400,'data_json'=>json_encode(['document'=>$doc,'observed_at'=>$now,'http_code'=>200])]);
 $before=count($requests);$manager->enqueue('one','rapid',$cached);$run();
 $check(count($requests)===$before&&$job($cached)['status']==='applied','Fresh shared model evidence not reused.');
 // Explicit mismatching URL cannot borrow the right variant from a model page.
 [$wrong,$wu,$wv]=$make('wrong');$db->update('shop_product_variant_supplier_links',['url'=>$wu.'?v_id=red'],'product_key=%s AND variant_key=%s',$wrong,$wv['Blue']);
 $responses[PriceParser::requestUrl($wu.'?v_id=red')]=$response($wu,['blue'=>1000,'red'=>1200]);
 $manager->enqueue('one','rapid',$wrong);$run();$check($job($wrong)['status']==='failed','Wrong manually selected URL accepted.');
 // Removed variant sources must not resurrect the old common source.
 [$removed,$ru]=$make('removed');$db->update('shop_product_variant_supplier_links',['removed'=>1],'product_key=%s',$removed);
 $db->insert('shop_product_supplier_links',['product_key'=>$removed,'label'=>'Legacy','url'=>$ru]);
 $check($manager->enqueue('one','rapid',$removed)['unconfigured']===1,'Removed links fell back to common product URL.');
 // One large variant change must not be hidden behind the minimum/base price.
 [$jump,$ju]=$make('jump');$responses[PriceParser::requestUrl($ju.'?v_id=blue')]=$response($ju,['blue'=>1000,'red'=>2000]);
 $manager->enqueue('one','rapid',$jump);$run();
 $check($job($jump)['status']==='review'&&$sale($jump)['variant_prices_json']==='{}','Large non-minimum variant jump silently applied.');
 // Changes to a precise URL invalidate even a complete, manually reviewed result.
 $link=$links->forProduct($jump)[0];$links->save($jump,$link['variant_key'],$link['link_key'],$link['version'],$link['partner_key'],$link['label'],$link['url'].'&changed=1');
 $reject(fn()=>$manager->apply((int)$job($jump)['id'],false));
 // Own mode stops a queued exact-variant check without any price mutation.
 [$own]=$make('own');$manager->enqueue('one','rapid',$own);$db->update('shop_product_sales',['mode'=>'own'],'product_key=%s',$own);$before=count($requests);$run(1);
 $check($job($own)['status']==='dismissed'&&count($requests)===$before,'Own mode performed partner fetch.');
 // RFC 9309 unavailable robots: allow a normal public page attempt; a product 403 remains denied.
 $alza=$partners->save(0,['name'=>'Alza','website_url'=>'https://www.alza.cz','priority'=>'50']);
 foreach(['public'=>200,'denied'=>403] as $path=>$code){
  [$ak,$unused,$av]=$make('alza-'.$path);$db->update('shop_product_variant_settings',['enabled'=>0],'product_key=%s',$ak);
  $db->update('shop_product_sales',['partner_id'=>$alza],'product_key=%s',$ak);$au='https://www.alza.cz/'.$path.'.htm';
  $db->insert('shop_product_supplier_links',['product_key'=>$ak,'label'=>'Alza','url'=>$au]);
  $responses['https://www.alza.cz/robots.txt']=['code'=>403,'body'=>'','headers'=>[]];
  $responses[$au]=['code'=>$code,'headers'=>[],'body'=>'<script type="application/ld+json">'.json_encode(['@type'=>'Product','offers'=>['@type'=>'Offer','price'=>1100,'priceCurrency'=>'CZK','url'=>$au]]).'</script>'];
  $manager->enqueue('one','rapid',$ak);$run();
  $check(in_array($au,$requests,true),'Robots 403 suppressed the public product check.');
  $check($job($ak)['status']===($code===200?'applied':'failed'),'Product denial was not distinguished from robots unavailability.');
 }
 $check((int)$db->queryFirstField('SELECT next_at FROM shop_supplier_price_hosts WHERE host=%s','alza.cz')>$now+3000,'Actual product 403 did not back off.');
 $report=$manager->diagnostics();$check($report['version']===3,'Canonical diagnostic version missing.');
 $replay=PriceEvaluation::replay($report);$check(count($replay['items'])>0,'Read-only replay lost unresolved canonical offers.');
 echo "Exact supplier prices: durable variant collection, atomic apply, cache proof, stale/manual URL guards, own mode and robots/product denial passed.\n";
}finally{$db->disconnect();$admin->query('DROP DATABASE %b',$database);}
