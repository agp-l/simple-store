<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Supplier\PriceManager;
use SimpleStore\Product\{PurchasePartnerRepository,ProductSupplierLinkRepository};
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);$database='simple_store_supplier_'.bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $run):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe operation accepted.');};
try{
 (new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
 $now=1800000000;$requests=[];$responses=[];
 $transport=static function(string $url)use(&$requests,&$responses,&$now):array{$requests[]=[$url,$now];return $responses[$url]??['code'=>404,'body'=>'','headers'=>[]];};
 $manager=new PriceManager($db,$transport,static function()use(&$now):int{return $now;});
 $partners=new PurchasePartnerRepository($db);$links=new ProductSupplierLinkRepository($db);
 $a=$partners->save(0,['name'=>'A','website_url'=>'https://a.test','priority'=>'50']);
 $b=$partners->save(0,['name'=>'B','ico'=>'24295825','address'=>'Test','email'=>'test@b.test','terms_url'=>'https://b.test/terms','website_url'=>'https://b.test','priority'=>'10','confirmed'=>'1']);
 $make=static function(string $path,int $price=1000)use($db,$links,$a):string{
  $key=bin2hex(random_bytes(16));$db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$key,'language'=>'cs','revision_number'=>1,'slug'=>$path,'active_slug'=>$path,'name'=>'Product '.$path,'category'=>'batohy','subcategory'=>'lehke','price_czk'=>3000,'description'=>'Keep','image_path'=>'','stock_status'=>'on_order','published'=>0]);
  $db->insert('shop_product_sales',['product_key'=>$key,'partner_id'=>$a,'supplier_price_czk'=>$price,'mode'=>'assisted','variant_prices_json'=>'{}']);
  $db->insert('shop_product_inventory',['product_key'=>$key,'available_quantity'=>7]);$links->save($key,0,'A','https://a.test/'.$path);return $key;
 };
 $offer=static function(string $url,int $price):array{return ['code'=>200,'headers'=>[],'body'=>'<script type="application/ld+json">'.json_encode(['@type'=>'Product','offers'=>['@type'=>'Offer','url'=>$url,'price'=>$price,'priceCurrency'=>'CZK','availability'=>'https://schema.org/InStock']]).'</script>'];};
 $sale=static fn(string $key):array=>$db->queryFirstRow('SELECT * FROM shop_product_sales WHERE product_key=%s',$key);
 $job=static fn(string $key):array=>$db->queryFirstRow('SELECT * FROM shop_supplier_price_jobs WHERE product_key=%s ORDER BY id DESC LIMIT 1',$key);
 $run=static function(int $n=8,int $advance=65)use($manager,&$now,&$requests,$check):void{for($i=0;$i<$n;$i++){$before=count($requests);$manager->tick();$check(count($requests)-$before<=1,'More than one HTTP request per tick.');$now+=$advance;}};
 $key=$make('first');$responses['https://a.test/first']=$offer('https://a.test/first',1100);
 $check($manager->installed(),'Schema not ready.');
 $check($manager->enqueue('one','normal',$key)['added']===1,'Could not enqueue.');
 $check($manager->enqueue('one','fast',$key)['daily']===1,'Pending duplicated.');
 $manager->tick();$check(count($requests)===1&&str_ends_with($requests[0][0],'/robots.txt'),'First request must be robots.');
 $manager->tick();$check(count($requests)===1,'Host cooldown not enforced.');$now+=90;$manager->tick();
 $check((int)$sale($key)['supplier_price_czk']===1100&&$job($key)['status']==='applied','Selected seller price not applied.');
 $check($manager->enqueue('one','fast',$key)['daily']===1,'Daily limit bypassed.');
 $db->update('shop_supplier_price_jobs',['created_at'=>$now-90000],'product_key=%s',$key);
 $check($manager->enqueue('one','fast',$key)['daily']===1,'Delayed completion bypassed rolling 24h limit.');
 $same=$make('same');$db->update('shop_product_supplier_links',['url'=>'https://a.test/first'],'product_key=%s',$same);$manager->enqueue('one','fast',$same);$before=count($requests);$manager->tick();
 $check(count($requests)===$before&&(int)$sale($same)['supplier_price_czk']===1100,'Shared URL cache failed.');
 $fallback=$make('missing');$links->save($fallback,0,'B','https://b.test/fallback');$responses['https://b.test/fallback']=$offer('https://b.test/fallback',1200);
 $manager->enqueue('one','normal',$fallback);$run();$check($job($fallback)['status']==='review'&&(int)$sale($fallback)['partner_id']===$a,'Fallback silently changed seller.');
 $manager->apply((int)$job($fallback)['id'],false);$check((int)$sale($fallback)['partner_id']===$b&&(int)$sale($fallback)['supplier_price_czk']===1200,'Atomic fallback apply failed.');
 $changed=$make('changed');$responses['https://a.test/changed']=$offer('https://a.test/changed',2000);$manager->enqueue('one','normal',$changed);$run();
 $check($job($changed)['status']==='review','Large jump not reviewed.');$db->update('shop_product_sales',['version'=>2,'supplier_price_czk'=>900],'product_key=%s',$changed);
 $reject(fn()=>$manager->apply((int)$job($changed)['id'],false));$check((int)$sale($changed)['supplier_price_czk']===900,'Stale result overwrote manual change.');
 $over=$make('too-expensive');$responses['https://a.test/too-expensive']=$offer('https://a.test/too-expensive',4000);$manager->enqueue('one','fast',$over);$run();$reject(fn()=>$manager->apply((int)$job($over)['id'],false));
 $variant=$make('variants');$db->insert('shop_product_variant_settings',['product_key'=>$variant,'enabled'=>1]);$responses['https://a.test/variants']=$offer('https://a.test/variants',1100);$manager->enqueue('one','fast',$variant);$run();
 $check($job($variant)['status']==='review','Variant cost overwritten automatically.');$reject(fn()=>$manager->apply((int)$job($variant)['id'],false));$manager->apply((int)$job($variant)['id'],true);
 // The real Pod 7 kilo model/variant URL mismatch is safe only with matching selected-variant evidence.
 $f=json_decode(file_get_contents(__DIR__.'/fixtures/pod7kilo-variant.json'),true,32,JSON_THROW_ON_ERROR);
 $pod=$partners->save(0,['name'=>'Pod 7 kilo','website_url'=>'https://www.pod7kilo.cz/','priority'=>'100']);
 $podKey=$make('pod7-variant');$db->update('shop_product_sales',['partner_id'=>$pod],'product_key=%s',$podKey);
 $db->update('shop_product_supplier_links',['url'=>$f['url']],'product_key=%s',$podKey);
 $responses[$f['url']]=['code'=>200,'headers'=>[],'body'=>'<html><head><link rel="canonical" href="'.$f['url'].'">'.
  '<script>var data = '.json_encode($f['data']).';</script><script type="application/ld+json">'.json_encode($f['product']).'</script></head></html>'];
 $manager->enqueue('one','fast',$podKey);$run(4,24);
 $check($job($podKey)['status']==='applied'&&(int)$sale($podKey)['supplier_price_czk']===1099,'Verified Pod 7 kilo XL did not update automatically.');
 $saved=json_decode($job($podKey)['result_json'],true);$check($saved['identity_evidence']['ean']==='8051411206200','Variant identity evidence not retained.');
 $blocked=$make('private/item');$responses['https://a.test/robots.txt']=['code'=>200,'body'=>"User-agent: *\nDisallow: /private\nCrawl-delay: 120\n",'headers'=>[]];
 $now+=86401;$manager->enqueue('one','fast',$blocked);$run();$check($job($blocked)['status']==='failed','Robots ignored.');
 $check(!in_array('https://a.test/private/item',array_column($requests,0),true),'Disallowed page fetched.');
 $rate=$make('rate');$responses['https://a.test/rate']=['code'=>429,'body'=>'','headers'=>['retry-after'=>'7200']];$manager->enqueue('one','fast',$rate);$run();
 $check((int)$db->queryFirstField('SELECT next_at FROM shop_supplier_price_hosts WHERE host=%s','a.test')>$now+6000,'Retry-After backoff ignored.');
 $check($job($rate)['status']==='queued'&&(int)$job($rate)['next_at']>$now+80000,'Temporary failure was not deferred for a single automatic retry.');
 $check($manager->enqueue('one','normal',$rate)['daily']===1,'Failure bypassed daily limit.');
 $pause=$make('pause');$manager->enqueue('one','normal',$pause);$manager->configure(false,true);$before=count($requests);$check($manager->tick()['state']==='paused'&&count($requests)===$before,'Pause still fetched.');
 $manager->configure(false,false);
 $other=ConnectionFactory::create(array_replace($config,['database'=>$database]));$lock='supplier-prices:'.sha1($database);$other->query('SELECT GET_LOCK(%s,0)',$lock);$reject(fn()=>$manager->tick());$other->query('SELECT RELEASE_LOCK(%s)',$lock);$other->disconnect();
 $token=$manager->rotateKey();$check(strlen($token)===64&&$manager->settings()['cron_hash']===hash('sha256',$token),'Cron key not hashed.');
 $manager->configure(true,false);$manager->tick();$next=(int)$manager->settings()['next_weekly'];$check($next===$now+604800,'Weekly enqueue not scheduled.');$manager->tick();$check((int)$manager->settings()['next_weekly']===$next,'Weekly cadence moved on each tick.');
 $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$key)===3,'Partner in-stock limit was not synchronized.');
 $check((int)$db->queryFirstField('SELECT price_czk FROM shop_product_revisions WHERE product_key=%s',$key)===3000,'Retail price changed.');
 $check($manager->dashboard('failed','Product private/item',1)['total']===1,'History search/filter failed.');

 // Atomic retail repricing creates a revision, preserves language content and existing stock.
 $manager->configure(false,false);
 // The prior weekly scheduling test queued the whole fixture catalog; isolate the next scenarios.
 $db->update('shop_supplier_price_jobs',['status'=>'dismissed'],'status=%s','queued');$now+=86401;
 $manager->configurePricing(['pricing_enabled'=>'1','pricing_small_limit'=>'1000','pricing_small_margin'=>'50','pricing_margin'=>'100','pricing_step'=>'100']);
 $rounded=$make('rounded',1210);$responses['https://a.test/rounded']=$offer('https://a.test/rounded',1210);
 $manager->enqueue('one','rapid',$rounded);$run(4,10000);
 $current=$db->queryFirstRow('SELECT * FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL',$rounded);
 $check((int)$current['price_czk']===1400&&(int)$current['revision_number']===3&&$current['description']==='Keep','Common retail price/revision was not updated atomically.');
 $check((int)$db->queryFirstField('SELECT price_czk FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NULL',$rounded)===3000,'Previous price missing from revision history.');
 $check($job($rounded)['status']==='applied'&&(int)$sale($rounded)['supplier_price_czk']===1210,'Same cost did not trigger retail pricing.');
 $audit=json_decode($job($rounded)['result_json'],true);$check($audit['retail_changes'][0]['new']===1400,'Retail audit missing.');
 // Fresh retry is explicit, respects a minimum cooldown, and is limited to one extra per day.
 $manual=$make('manual-retry',1000);$responses['https://a.test/manual-retry']=$offer('https://a.test/manual-retry',2000);
 $manager->enqueue('one','rapid',$manual);$run(4,200);
 $check($job($manual)['status']==='review','Retry fixture should require review.');
 $before=count($requests);$oldId=(int)$job($manual)['id'];
 $check($manager->retry('one','rapid',false,$oldId)['added']===1,'Cache re-evaluation not queued.');$run(2,10);
 $check(count($requests)===$before&&$job($manual)['status']==='review','Local re-evaluation made a network request or applied a risky price.');
 $check($manager->retry('one','rapid',true,(int)$job($manual)['id'])['added']===1,'Manual retry not queued.');
 $check((int)$job($manual)['next_at']>$now,'Manual retry ignored minimum cooldown.');
 $responses['https://a.test/manual-retry']=$offer('https://a.test/manual-retry',2100);$now+=901;$run(2,10);
 $check($job($manual)['status']==='review'&&json_decode($job($manual)['result_json'],true)['price']===2100,'Fresh retry did not replace cached result.');
 $check($manager->retry('one','rapid',true,(int)$job($manual)['id'])['daily']===1,'Unlimited extra daily requests allowed.');
 // Verified uniform groups may populate common variant cost automatically; own variant prices remain.
 $uniform=$make('uniform',1000);$db->insert('shop_product_variant_settings',['product_key'=>$uniform,'enabled'=>1]);
 $node=['@type'=>'ProductGroup','url'=>'https://a.test/uniform','hasVariant'=>[]];
 foreach(['blue','red']as$color)$node['hasVariant'][]=['@type'=>'Product','offers'=>['@type'=>'Offer','url'=>'https://a.test/uniform?v_id='.$color,'price'=>1100,'priceCurrency'=>'CZK','availability'=>'https://schema.org/InStock']];
 $responses['https://a.test/uniform']=['code'=>200,'headers'=>[],'body'=>'<script type="application/ld+json">'.json_encode($node).'</script>'];
 $manager->enqueue('one','rapid',$uniform);$run(3,100);
 $check($job($uniform)['status']==='applied'&&(int)$sale($uniform)['supplier_price_czk']===1100,'Uniform variants did not apply automatically.');

 $protected=$make('protected-variant',1000);$v=bin2hex(random_bytes(16));
 $db->insert('shop_product_variant_settings',['product_key'=>$protected,'enabled'=>1]);
 $db->insert('shop_product_variants',['product_key'=>$protected,'variant_key'=>$v,'choices_json'=>'{}','price_czk'=>1000,'available_quantity'=>7]);
 $responses['https://a.test/protected-variant']=$offer('https://a.test/protected-variant',1100);
 $manager->enqueue('one','rapid',$protected);$run(4,200);$reject(fn()=>$manager->apply((int)$job($protected)['id'],true));
 $check((int)$sale($protected)['supplier_price_czk']===1000&&(int)$db->queryFirstField('SELECT price_czk FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL',$protected)===3000,'Rejected variant repricing partially changed the product.');
 $check((int)$db->queryFirstField('SELECT price_czk FROM shop_product_variants WHERE product_key=%s',$protected)===1000,'Explicit variant retail price overwritten.');
 // A lower explicit supplier cost belongs to its own variant and must not block common-cost pricing.
 $ownCost=$make('own-variant-cost',1000);$v=bin2hex(random_bytes(16));
 $db->insert('shop_product_variant_settings',['product_key'=>$ownCost,'enabled'=>1]);
 $db->insert('shop_product_variants',['product_key'=>$ownCost,'variant_key'=>$v,'choices_json'=>'{}','price_czk'=>1000,'available_quantity'=>7]);
 $db->update('shop_product_sales',['variant_prices_json'=>json_encode([$v=>900])],'product_key=%s',$ownCost);
 $responses['https://a.test/own-variant-cost']=$offer('https://a.test/own-variant-cost',1100);
 $manager->enqueue('one','rapid',$ownCost);$run(4,200);$manager->apply((int)$job($ownCost)['id'],true);
 $check((int)$sale($ownCost)['supplier_price_czk']===1100&&json_decode($sale($ownCost)['variant_prices_json'],true)[$v]===900,'Explicit variant supplier cost lost.');
 $check((int)$db->queryFirstField('SELECT price_czk FROM shop_product_variants WHERE product_key=%s',$ownCost)===1000,'Explicit variant retail price lost.');

 $moved=$make('old-link',1000);
 $responses['https://a.test/old-link']=['code'=>301,'headers'=>['location'=>'/new-link'],'body'=>''];
 $node=['@type'=>'Product','name'=>'Product old-link','offers'=>['@type'=>'Offer','url'=>'https://a.test/new-link','price'=>1100,'priceCurrency'=>'CZK','availability'=>'https://schema.org/InStock']];
 $responses['https://a.test/new-link']=['code'=>200,'headers'=>[],'body'=>'<script type="application/ld+json">'.json_encode($node).'</script>'];
 $manager->enqueue('one','rapid',$moved);$run(4,200);
 $check($job($moved)['status']==='applied'&&$db->queryFirstField('SELECT url FROM shop_product_supplier_links WHERE product_key=%s',$moved)==='https://a.test/new-link','Verified permanent redirect was not repaired.');
 $cachedMove=$make('cached-old-link',1000);$db->update('shop_product_supplier_links',['url'=>'https://a.test/old-link'],'product_key=%s',$cachedMove);
 $manager->enqueue('one','rapid',$cachedMove);$before=count($requests);$manager->tick();
 $check($job($cachedMove)['status']==='applied'&&count($requests)===$before,'Cached redirect and offer were not resolved in one local tick.');
 $report=$manager->diagnostics();$check($report['format']==='dobrodruzi.supplier-price-diagnostics'&&!str_contains(json_encode($report),$token),'Diagnostics leaked cron token.');
 $entry=array_values(array_filter($report['items'],static fn(array $item):bool=>$item['product_key']===$cachedMove))[0];
 $check($entry['sources'][0]['resolved_url']==='https://a.test/new-link'&&isset($entry['sources'][0]['cache']['document']),'Diagnostics omitted the offer behind a cached redirect.');
 $gramino=$partners->save(0,['name'=>'Gramino','website_url'=>'https://gramino.cz/','priority'=>'100']);
 $scoped=$make('patizon-d890-s',11990);$modelUrl='https://gramino.cz/p/patizon-d-890';
 $db->update('shop_product_sales',['partner_id'=>$gramino],'product_key=%s',$scoped);
 $db->update('shop_product_revisions',['name'=>'Patizon D890 – S','price_czk'=>12100],'product_key=%s',$scoped);
 $db->update('shop_product_supplier_links',['url'=>$modelUrl],'product_key=%s',$scoped);
 $db->insert('shop_product_variant_settings',['product_key'=>$scoped,'enabled'=>1]);
 foreach(['Červená','Modrá']as$color)$db->insert('shop_product_variants',['product_key'=>$scoped,'variant_key'=>bin2hex(random_bytes(16)),'choices_json'=>json_encode(['Barva'=>$color]),'available_quantity'=>7]);
 $node=['@type'=>'ProductGroup','name'=>'Patizon D 890','url'=>$modelUrl,'hasVariant'=>[]];
 foreach([['Red','S',11990],['Blue','S',11990],['Red','L',12490]]as[$color,$size,$price])$node['hasVariant'][]=['@type'=>'Product','name'=>'Patizon D 890 – '.$color.', '.$size,'offers'=>['@type'=>'Offer','url'=>$modelUrl.'?v_id='.$color.$size,'price'=>$price,'priceCurrency'=>'CZK','availability'=>'https://schema.org/InStock']];
 $responses[\SimpleStore\Supplier\PriceParser::requestUrl($modelUrl)]=['code'=>200,'headers'=>[],'body'=>'<script type="application/ld+json">'.json_encode($node).'</script>'];
 $manager->enqueue('one','rapid',$scoped);$run(4,100);
 $check($job($scoped)['status']==='checked'&&(int)$sale($scoped)['supplier_price_czk']===11990,'Scoped local variants did not auto-verify their shared price.');
 $audit=json_decode($job($scoped)['result_json'],true);$check($audit['identity_evidence']['method']==='exact_local_variant_scope','Local variant proof missing.');
 $check((int)$db->queryFirstField('SELECT SUM(available_quantity) FROM shop_product_variants WHERE product_key=%s',$scoped)===6,'Exact variant partner limits were not synchronized.');
 // Current counters must ignore obsolete runs and removed cards, while history remains available.
 $oldCount=$manager->dashboard()['counts'];
 $db->insert('shop_supplier_price_jobs',['product_key'=>str_repeat('f',32),'product_name'=>'Removed product','mode'=>'normal','created_at'=>$now,'next_at'=>$now,'status'=>'failed','message'=>'Gone','data_json'=>'{"snapshot":{"candidates":[]}}']);
 $check($manager->dashboard()['counts']===$oldCount,'Deleted product inflated current counters.');
 $check($manager->dashboard('failed','Removed product')['total']===0&&$manager->dashboard('failed','Removed product',1,true)['total']===1,'Removed card cannot be isolated in history.');
 $db->insert('shop_supplier_price_jobs',['product_key'=>$scoped,'product_name'=>'Patizon D890 – S','mode'=>'normal','created_at'=>$now,'next_at'=>$now,'status'=>'failed','message'=>'Newer result','data_json'=>$job($scoped)['data_json']]);
 $check($manager->dashboard('checked','Patizon D890')['total']===0,'Superseded success counted as current.');
 $check($manager->dashboard('failed','Patizon D890')['total']===1,'Newest failure missing.');
 $db->update('shop_supplier_price_jobs',['status'=>'dismissed'],'status=%s','queued');
 $options=json_decode($manager->settings()['options_json'],true);$options['rechecked_version']=3;
 // Isolate the cache-only evaluator migration from the new daily availability scheduler.
 $options['availability_initialized']=true;$options['availability_next_scan']=$now+86400;
 $db->update('shop_supplier_price_settings',['options_json'=>json_encode($options)],'id=1');
 $before=count($requests);$manager->tick('cron');
 $check(count($requests)===$before,'Evaluator upgrade fetched a supplier instead of replaying cache.');
 $check((int)$manager->settings()['last_cron_tick']===$now,'Real cron heartbeat missing.');
 $manager->configurePricing(['pricing_enabled'=>'1','pricing_small_limit'=>'1000','pricing_small_margin'=>'50','pricing_margin'=>'100','pricing_step'=>'100']);
 $check((int)$manager->settings()['last_cron_tick']===$now&&(int)$manager->settings()['rechecked_version']===PriceManager::EVALUATOR_VERSION,'Pricing settings erased scheduler metadata.');
 $report=$manager->diagnostics();$removed=array_values(array_filter($report['items'],static fn(array $item):bool=>$item['product_key']===str_repeat('f',32)))[0];
 $check(!$removed['active']&&$removed['snapshot']===null&&$report['version']===3,'Diagnostic active/history boundary wrong.');
 $check(!str_contains(json_encode($report),'cron_hash')&&!str_contains(json_encode($report),$token),'Diagnostic credential leak.');
 // Obsolete parsed documents get one maintenance refresh after the actual request cooldown.
 $db->update('shop_supplier_price_jobs',['status'=>'dismissed'],'status=%s','queued');
 $legacy=$make('legacy-attributes',1000);$legacyUrl='https://gramino.cz/p/legacy-attributes';
 $db->update('shop_product_sales',['partner_id'=>$gramino],'product_key=%s',$legacy);
 $db->update('shop_product_supplier_links',['url'=>$legacyUrl],'product_key=%s',$legacy);
 $db->insert('shop_product_variant_settings',['product_key'=>$legacy,'enabled'=>1]);
 $db->insert('shop_product_variants',['product_key'=>$legacy,'variant_key'=>bin2hex(random_bytes(16)),'choices_json'=>'{"Barva":"Zelená"}','available_quantity'=>3]);
 $responses[\SimpleStore\Supplier\PriceParser::requestUrl($legacyUrl)]=$offer($legacyUrl,1100);
 $manager->enqueue('one','rapid',$legacy);$run(4,100);
 $check($job($legacy)['status']==='review','Legacy attributes fixture did not require review.');
 $last=$job($legacy);$db->update('shop_supplier_price_jobs',['retry_of'=>1,'checked_at'=>$now],'id=%i',$last['id']);
 $cache=$db->queryFirstRow('SELECT * FROM shop_supplier_price_cache WHERE url_hash=%s',hash('sha256','product:'.$legacyUrl));
 $data=json_decode($cache['data_json'],true);$data['document']['parser_version']=3;
 $db->update('shop_supplier_price_cache',['data_json'=>json_encode($data)],'url_hash=%s',$cache['url_hash']);
 $manager->retry('one','rapid',false,(int)$last['id']);
 // A changed supplier URL can reuse a cached model even in ordinary (not cache-only) mode.
 $queued=$job($legacy);$queuedData=json_decode($queued['data_json'],true);$queuedData['cache_only']=false;
 $db->update('shop_supplier_price_jobs',['data_json'=>json_encode($queuedData)],'id=%i',$queued['id']);
 $before=count($requests);$manager->tick();
 $check(count($requests)===$before&&$job($legacy)['status']==='queued'&&(int)$job($legacy)['next_at']>=(int)$data['fetched_at']+900,'Parser maintenance ignored the actual request cooldown.');
 $check($manager->retry('one','rapid',true,(int)$job($legacy)['id'])['added']===0,'Scheduled refresh was duplicated.');
 $body=json_decode(substr($responses[\SimpleStore\Supplier\PriceParser::requestUrl($legacyUrl)]['body'],strlen('<script type="application/ld+json">'),-strlen('</script>')),true);
 $body['color']='Green';
 $responses[\SimpleStore\Supplier\PriceParser::requestUrl($legacyUrl)]['body']='<script type="application/ld+json">'.json_encode($body).'</script>';
 $now+=901;$run(4,100);
 $check($job($legacy)['status']==='applied','Scheduled legacy refresh did not apply the exact new attributes.');
 $afterMaintenance=count($requests);$run(3,1);$check(count($requests)===$afterMaintenance,'Parser maintenance repeated a completed fetch.');
 // With global pricing on, blank inherited variant prices can adopt individually proven costs.
 $inherited=$make('inherited-prices',1000);$inheritedUrl='https://gramino.cz/p/inherited-prices';
 $db->update('shop_product_sales',['partner_id'=>$gramino],'product_key=%s',$inherited);
 $db->update('shop_product_supplier_links',['url'=>$inheritedUrl],'product_key=%s',$inherited);
 $db->insert('shop_product_variant_settings',['product_key'=>$inherited,'enabled'=>1]);
 $node=['@type'=>'ProductGroup','name'=>'Inherited model','url'=>$inheritedUrl,'hasVariant'=>[]];$expected=[];
 foreach(['Red'=>1100,'Blue'=>1200]as$color=>$cost){
  $vk=bin2hex(random_bytes(16));$expected[$vk]=$cost;
  $db->insert('shop_product_variants',['product_key'=>$inherited,'variant_key'=>$vk,'choices_json'=>json_encode(['Barva'=>$color]),'available_quantity'=>4]);
  $node['hasVariant'][]=['@type'=>'Product','name'=>'Inherited model '.$color,'color'=>$color,'offers'=>['@type'=>'Offer','url'=>$inheritedUrl.'?v_id='.$color,'price'=>$cost,'priceCurrency'=>'CZK']];
 }
 $responses[\SimpleStore\Supplier\PriceParser::requestUrl($inheritedUrl)]=['code'=>200,'headers'=>[],'body'=>'<script type="application/ld+json">'.json_encode($node).'</script>'];
 $manager->enqueue('one','rapid',$inherited);$run(4,100);
 $check($job($inherited)['status']==='applied'&&json_decode($sale($inherited)['variant_prices_json'],true)==$expected,'Distinct inherited costs were not applied atomically.');
 $check((int)$db->queryFirstField('SELECT enabled FROM shop_product_supplier_variant_modes WHERE product_key=%s',$inherited)===1,'Automatic variant pricing preference not recorded.');
 $check((int)$db->queryFirstField('SELECT SUM(available_quantity) FROM shop_product_variants WHERE product_key=%s',$inherited)===8,'Enabling inherited variant prices changed stock.');
 // Partner automation must not touch own-stock products, including work queued before a mode switch.
 $db->update('shop_supplier_price_jobs',['status'=>'dismissed'],'status=%s','queued');
 $own=$make('own-stock');$db->update('shop_product_sales',['mode'=>'own'],'product_key=%s',$own);
 $check($manager->enqueue('one','rapid',$own)['unconfigured']===1,'Own stock entered the partner price queue.');
 $switched=$make('mode-switched');$manager->enqueue('one','rapid',$switched);
 $db->update('shop_product_sales',['mode'=>'own'],'product_key=%s',$switched);
 $oldSale=$sale($switched);$oldProduct=$db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s',$switched);
 $before=count($requests);$manager->tick();
 $check($job($switched)['status']==='dismissed'&&count($requests)===$before,'Mode switch did not stop queued network work.');
 $check($sale($switched)===$oldSale&&$db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s',$switched)===$oldProduct,'Own prices changed after switching mode.');
 // An old installation could have produced an own-mode review with a matching snapshot.
 $ownJob=$job($switched);$ownData=json_decode($ownJob['data_json'],true);$ownData['snapshot']['sale']['mode']='own';
 $ownData['fingerprint']=hash('sha256',json_encode($ownData['snapshot'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 $db->update('shop_supplier_price_jobs',['status'=>'review','data_json'=>json_encode($ownData),'result_json'=>'{}'],'id=%i',$ownJob['id']);
 $reject(fn()=>$manager->apply((int)$ownJob['id'],false));
 $check($manager->retry('one','rapid',false,(int)$ownJob['id'])['unconfigured']===1,'Own review was requeued.');
 $check($sale($switched)===$oldSale,'Manual apply changed own-stock pricing.');
 // Changing sale mode preserves links and physical inventory; order snapshots use their original mode.
 $inventory=$db->query('SELECT * FROM shop_product_inventory WHERE product_key=%s',$switched);$sourceLinks=$links->forProduct($switched);
 $sales=new \SimpleStore\Product\ProductSalesRepository($db);
 foreach(['assisted','own'] as $saleMode){$current=$sale($switched);$sales->save($switched,'cs',['mode'=>$saleMode,'version'=>(string)$current['version'],'supplier_price_czk'=>(string)$current['supplier_price_czk'],'partner_id'=>(string)$current['partner_id']]);}
 $check($db->query('SELECT * FROM shop_product_inventory WHERE product_key=%s',$switched)===$inventory&&$links->forProduct($switched)===$sourceLinks,'Mode switch rewrote inventory or source links.');
 // Exact variant URL edits must invalidate price approvals; routine verification must not.
 $variantLinks=new \SimpleStore\Product\ProductVariantSupplierRepository($db);
 foreach(['proof','url','remove','partner'] as $change){
  $guarded=$make('link-guard-'.$change);$vk=bin2hex(random_bytes(16));
  $db->insert('shop_product_variants',['product_key'=>$guarded,'variant_key'=>$vk,'choices_json'=>'{"Barva":"Blue"}','available_quantity'=>4]);
  $variantLinks->inherit($guarded);$link=$variantLinks->forProduct($guarded)[0];
  $responses['https://a.test/link-guard-'.$change]=$offer('https://a.test/link-guard-'.$change,2000);
  $manager->enqueue('one','rapid',$guarded);$run(6,100);
  $check($job($guarded)['status']==='review','Link guard fixture must require approval.');
  $data=json_decode($job($guarded)['data_json'],true);
  $check(count($data['snapshot']['variantLinks'])===1&&$data['snapshot']['variantLinks'][0]['link_key']===$link['link_key'],'Price snapshot omitted variant link identity.');
  if($change==='proof'){
   $db->update('shop_product_variant_supplier_links',['checked_at'=>$now,'verification'=>'verified','result_json'=>'{"status":"exact"}','version'=>99,'label'=>'Updated display label'],'link_key=%s',$link['link_key']);
   $manager->apply((int)$job($guarded)['id'],false);
   $check((int)$sale($guarded)['supplier_price_czk']===2000,'Refreshing evidence or label invalidated an unchanged source.');
  }else{
   if($change==='remove')$variantLinks->remove($guarded,$vk,$link['link_key'],$link['version']);
   else $variantLinks->save($guarded,$vk,$link['link_key'],$link['version'],$change==='partner'?'':$link['partner_key'],$link['label'],$link['url'].($change==='url'?'?size=L':''));
   $beforeSale=$sale($guarded);$reject(fn()=>$manager->apply((int)$job($guarded)['id'],false));
   $check($sale($guarded)===$beforeSale,'Changed variant offer allowed a stale price write.');
   $before=count($requests);$check($manager->retry('one','rapid',false,(int)$job($guarded)['id'])['added']===1,'Updated offer could not be reevaluated from cache.');
   $manager->tick();$check(count($requests)===$before,'Reevaluation bypassed the cached-only boundary.');
  }
 }

 // A simple product changes sellers: retry and probe must use the new colour-specific source.
 $opinel=$make('old-opinel',650);$manager->enqueue('one','rapid',$opinel);$run(6,100);
 $oldJob=$job($opinel);$check($oldJob['status']==='failed','Old seller fixture did not fail.');
 $camping=$partners->save(0,['name'=>'4camping','website_url'=>'https://www.4camping.cz/','priority'=>'20']);
 $f4=json_decode(file_get_contents(__DIR__.'/fixtures/4camping-opinel.json'),true,32,JSON_THROW_ON_ERROR);
 $orange=$f4['url'].'#oranzova';
 $db->update('shop_product_sales',['partner_id'=>$camping,'version'=>2],'product_key=%s',$opinel);
 $db->update('shop_product_supplier_links',['url'=>$orange,'label'=>'4camping'],'product_key=%s',$opinel);
 $responses[$f4['url']]=['code'=>200,'headers'=>[],'body'=>'<script>var data = '.json_encode($f4['data']).';</script><script type="application/ld+json">'.json_encode($f4['product']).'</script>'];
 $card=$manager->dashboard('failed','Product old-opinel')['rows'][0];
 $check($card['sources_changed']&&$card['stale_result']&&$card['current_sources'][0]['url']===$orange,'History hid current source or offered stale approval.');
 $before=count($requests);
 $check($manager->retry('one','rapid',false,(int)$oldJob['id'])['added']===1,'Changed seller cannot be retried without a forced download.');
 $check($manager->retry('one','rapid',false,(int)$job($opinel)['id'])['daily']===1,'Changed seller duplicated pending job.');
 $run(8,100);
 $check($job($opinel)['status']==='applied'&&(int)$sale($opinel)['supplier_price_czk']===689,'New simple-product partner did not update its exact colour price.');
 $newRequests=array_column(array_slice($requests,$before),0);
 $check(in_array($f4['url'],$newRequests,true)&&!in_array('https://a.test/old-opinel',$newRequests,true)&&!in_array($orange,$newRequests,true),'Retry fetched old seller or sent URL fragment.');
 $beforeSale=$sale($opinel);$beforeJob=$job($opinel);$before=count($requests);
 $probe=$manager->probe((int)$oldJob['id']);
 $check($probe['ok']===true&&$probe['price']===689&&$probe['url']===$orange,'Historical diagnostic did not read current exact source.');
 $check($sale($opinel)===$beforeSale&&$job($opinel)===$beforeJob&&count($requests)===$before,'Probe mutated data or bypassed cache.');
 $db->update('shop_product_supplier_links',['url'=>$f4['url'].'#modra'],'product_key=%s',$opinel);
 $check($manager->retry('one','rapid',false,(int)$beforeJob['id'])['added']===1,'Changing source after a successful check could not be checked.');
 $run(2,5);$check(count($requests)===$before,'Another colour bypassed shared URL cache.');
 $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$opinel)===3,'Simple-product partner limit not synchronized.');
 $reject(fn()=>$manager->retry('one','rapid',false,(int)$oldJob['id']));
 echo "Supplier prices: MySQL durable queue, selected/fallback sellers, daily and URL limits, pauses, locks, stale data, variants, current counters and cron passed.\n";
}finally{$db->disconnect();$admin->query('DROP DATABASE %b',$database);}
