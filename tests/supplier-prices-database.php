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
 $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$key)===7,'Supplier availability changed inventory.');
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
 $check((int)$current['price_czk']===1400&&(int)$current['revision_number']===2&&$current['description']==='Keep','Common retail price/revision was not updated atomically.');
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
 $report=$manager->diagnostics();$check($report['format']==='dobrodruzi.supplier-price-diagnostics'&&!str_contains(json_encode($report),$token),'Diagnostics leaked cron token.');
 echo "Supplier prices: MySQL durable queue, selected/fallback sellers, daily and URL limits, pauses, locks, stale data, variants and cron passed.\n";
}finally{$db->disconnect();$admin->query('DROP DATABASE %b',$database);}
