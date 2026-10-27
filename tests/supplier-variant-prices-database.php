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
 $check((int)$db->queryFirstField('SELECT MIN(available_quantity) FROM shop_product_variants WHERE product_key=%s',$key)===3,'Exact variant partner limits were not synchronized.');
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
 // Diagnostics use the shared response but cannot modify products or queue decisions.
 $beforeSale=$sale($ak);$beforeJob=$job($ak);$before=count($requests);
 $probe=$manager->probe((int)$beforeJob['id']);
 $check($probe['ok']===false&&str_contains($probe['message'],'HTTP 403'),'Diagnostic hid the actual product denial.');
 $check(count($requests)===$before&&$sale($ak)===$beforeSale&&$job($ak)===$beforeJob,'Diagnostic repeated a denied request or changed business data.');
 $beforeSale=$sale($key);$beforeJob=$job($key);$before=count($requests);
 $probe=$manager->probe((int)$beforeJob['id']);
 $check($probe['ok']===true&&count($requests)===$before,'Diagnostic did not share the successful URL cache.');
 $check($sale($key)===$beforeSale&&$job($key)===$beforeJob,'Successful diagnostic changed prices or history.');
 $reject(fn()=>$manager->probe((int)$job($own)['id']));
 $manager->configure(false,true);$reject(fn()=>$manager->probe((int)$beforeJob['id']));$manager->configure(false,false);

 // Explicit opt-in repairs imported/manual variant pricing without weakening identity checks.
 [$managed,$managedUrl,$managedVariants]=$make('explicit-pricing');
 $responses[PriceParser::requestUrl($managedUrl.'?v_id=blue')]=$response($managedUrl,['blue'=>1000,'red'=>1200]);
 $db->update('shop_product_variants',['price_czk'=>2500],'product_key=%s',$managed);
 $db->insert('shop_product_supplier_variant_modes',['product_key'=>$managed,'enabled'=>0]);
 $sales=new \SimpleStore\Product\ProductSalesRepository($db);
 $settings=static function(string $key)use($sales):array{
  $s=$sales->forProduct($key);
  return ['mode'=>$s['mode'],'version'=>(string)$s['version'],'partner_id'=>(string)$s['partner_id'],
   'supplier_price_czk'=>(string)$s['supplier_price_czk'],'variant_price'=>$s['variant_prices'],
   'variant_pricing'=>$s['variant_pricing'],'variant_pricing_expected'=>$s['variant_pricing']];
 };
 $optIn=array_replace($settings($managed),['variant_pricing'=>'automatic']);
 $reject(fn()=>$sales->save($managed,'cs',array_replace($optIn,['mode'=>'own'])));
 $check($sales->forProduct($managed)['mode']==='assisted','Invalid preference partially saved sale mode.');
 $sales->save($managed,'cs',$optIn);
 $check($sales->forProduct($managed)['variant_pricing']==='automatic','Explicit automation preference not saved.');
 $check((int)$db->queryFirstField('SELECT MIN(price_czk) FROM shop_product_variants WHERE product_key=%s',$managed)===2500,'Preference changed prices before verification.');
 $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_partner_refresh_requests WHERE product_key=%s',$managed)===1,'Opt-in did not schedule verification.');
 $beforeSale=$sale($managed);
 $reject(fn()=>$sales->save($managed,'cs',array_replace($settings($managed),['variant_pricing'=>'manual','variant_pricing_expected'=>'manual'])));
 $check($sale($managed)===$beforeSale,'Stale pricing preference partially changed sale settings.');
 $run();
 $check($job($managed)['status']==='applied','Opt-in did not complete automatic exact-variant pricing: '.$job($managed)['message']);
 $check((int)$db->queryFirstField('SELECT price_czk FROM shop_product_variants WHERE variant_key=%s',$managedVariants['Red'])===1300,'Opt-in lost per-variant retail policy.');
 $legacy=$settings($managed);unset($legacy['variant_pricing'],$legacy['variant_pricing_expected']);
 $sales->save($managed,'cs',$legacy);
 $check($sales->forProduct($managed)['variant_pricing']==='automatic','Legacy save implicitly disabled automation.');
 $sales->save($managed,'cs',array_replace($settings($managed),['variant_pricing'=>'manual']));
 $check($sales->forProduct($managed)['variant_pricing']==='manual','Explicit opt-out was ignored.');
 $sales->save($managed,'cs',array_replace($settings($managed),['variant_pricing'=>'inherit']));
 $check($sales->forProduct($managed)['variant_pricing']==='inherit','Default policy was not restored.');

 // Explicit approval may switch a whole exact-variant set, never individual prices
 // or an incomplete set; the background worker still requires this approval.
 $backupId=$partners->save(0,['name'=>'Backup','ico'=>'24295825','address'=>'Test','email'=>'test@backup.test','terms_url'=>'https://www.pod7kilo.cz/terms','website_url'=>'https://www.pod7kilo.cz','priority'=>'20','confirmed'=>'1']);
 $backup=$partners->find($backupId);
 $fallbackCase=static function(string $path,?int $mode)use($make,$db,$links,$backup,&$responses):array{
  [$k,$unused,$vs]=$make($path);$bu='https://www.pod7kilo.cz/p/'.$path;
  if($mode!==null)$db->insert('shop_product_supplier_variant_modes',['product_key'=>$k,'enabled'=>$mode]);
  $offers=[];
  foreach(['Blue'=>1000,'Red'=>1200]as$color=>$price){
   $u=$bu.'?variant='.strtolower($color);
   $links->save($k,$vs[$color],'',0,$backup['partner_key'],'Backup',$u);
   $offers[]=['@type'=>'Product','name'=>'Patizon D590','color'=>$color,'size'=>'M','offers'=>['@type'=>'Offer','price'=>$price,'priceCurrency'=>'CZK','url'=>$u,'availability'=>'https://schema.org/InStock']];
  }
  $body='<script type="application/ld+json">'.json_encode(['@type'=>'ProductGroup','name'=>'Patizon D590','url'=>$bu,'hasVariant'=>$offers]).'</script>';
  foreach(['blue','red']as$c)$responses[$bu.'?variant='.$c]=['code'=>200,'body'=>$body,'headers'=>[]];
  return [$k,$vs];
 };
 [$switched,$sv]=$fallbackCase('approved-fallback',1);
 $inactive=bin2hex(random_bytes(16));
 $db->insert('shop_product_variants',['product_key'=>$switched,'variant_key'=>$inactive,'choices_json'=>json_encode(['Barva'=>'Green','Velikost'=>'M']),'enabled'=>0]);
 $db->update('shop_product_sales',['variant_prices_json'=>json_encode([$sv['Blue']=>900,$sv['Red']=>1000,$inactive=>800])],'product_key=%s',$switched);
 $db->insert('shop_partner_stock_state',['product_key'=>$switched,'partner_id'=>$partnerId,'observed_at'=>$now]);
 $beforeSale=$sale($switched);$manager->enqueue('one','rapid',$switched);$run(20);
 $check($job($switched)['status']==='review'&&$sale($switched)===$beforeSale,'Fallback did not wait for approval: '.$job($switched)['status'].' / '.$job($switched)['message']);
 $manager->apply((int)$job($switched)['id'],false);
 $switchedCosts=json_decode($sale($switched)['variant_prices_json'],true);
 $check((int)$sale($switched)['partner_id']===$backupId&&count($switchedCosts)===2&&$switchedCosts[$sv['Blue']]===1000&&$switchedCosts[$sv['Red']]===1200,'Approved fallback did not replace exact costs atomically.');
 $check((int)$db->queryFirstField('SELECT price_czk FROM shop_product_variants WHERE variant_key=%s',$sv['Red'])===1300,'Fallback lost per-variant retail price.');
 $check((int)$db->queryFirstField('SELECT MAX(available_quantity) FROM shop_product_variants WHERE product_key=%s',$switched)===0,'Previous partner stock survived seller switch.');
 $run(20);$check(in_array($job($switched)['status'],['applied','checked'],true),'Approved partner did not continue automatically.');
 $check((int)$db->queryFirstField('SELECT MIN(available_quantity) FROM shop_product_variants WHERE product_key=%s AND enabled=1',$switched)===3,'New partner availability was not refreshed.');
 [$manualFallback]=$fallbackCase('manual-fallback',0);$manager->enqueue('one','rapid',$manualFallback);$run(20);$beforeSale=$sale($manualFallback);
 $reject(fn()=>$manager->apply((int)$job($manualFallback)['id'],false));
 $check($sale($manualFallback)===$beforeSale,'Fallback silently enabled manual variant pricing.');
 [$defaultFallback]=$fallbackCase('default-fallback',null);$manager->enqueue('one','rapid',$defaultFallback);$run(20);
 $j=$job($defaultFallback);$originalData=$j['data_json'];$broken=json_decode($originalData,true);array_pop($broken['variant_observations']);
 $db->update('shop_supplier_price_jobs',['data_json'=>json_encode($broken)],'id=%i',$j['id']);$beforeSale=$sale($defaultFallback);
 $reject(fn()=>$manager->apply((int)$j['id'],false));$check($sale($defaultFallback)===$beforeSale,'Incomplete fallback proof changed seller.');
 $db->update('shop_supplier_price_jobs',['data_json'=>$originalData],'id=%i',$j['id']);$manager->apply((int)$j['id'],false);
 $check((int)$sale($defaultFallback)['partner_id']===$backupId&&(int)$db->queryFirstField('SELECT enabled FROM shop_product_supplier_variant_modes WHERE product_key=%s',$defaultFallback)===1,'Eligible default pricing did not follow explicit fallback approval.');
 $report=$manager->diagnostics();$check($report['version']===3,'Canonical diagnostic version missing.');
 $replay=PriceEvaluation::replay($report);$check(count($replay['items'])>0,'Read-only replay lost unresolved canonical offers.');
 echo "Exact supplier prices: durable variant collection, atomic apply, cache proof, stale/manual URL guards, own mode and robots/product denial passed.\n";
}finally{$db->disconnect();$admin->query('DROP DATABASE %b',$database);}
