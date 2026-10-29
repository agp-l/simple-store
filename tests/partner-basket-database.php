<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Product\{ProductStockRepository,ProductRepository,ProductSalesRepository,ProductSupplierLinkRepository,PurchasePartnerRepository,PartnerDeliveryCosts};
use SimpleStore\Supplier\PartnerOfferCatalog;
use SimpleStore\Checkout\{CartPartnerSelection,CartService,CartSession};
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);$database='simple_store_basket_'.bin2hex(random_bytes(5));$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);
$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $run):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Changed offer accepted.');};
try {
 (new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
 $partners=new PurchasePartnerRepository($db);$ids=[];
 foreach(['a','b'] as $name)$ids[$name]=$partners->save(0,['name'=>$name,'ico'=>'12345678','address'=>'Praha','email'=>$name.'@example.cz','terms_url'=>'https://'.$name.'.example.cz/terms','website_url'=>'https://'.$name.'.example.cz','confirmed'=>'1']);
 $ps=array_column($partners->all(),null,'name');
 $make=static function(string $name,string $primary)use($db,$ids):string {
  $key=bin2hex(random_bytes(16));$db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$key,'language'=>'cs','slug'=>$name,'active_slug'=>$name,'name'=>$name,'price_czk'=>3000,'description'=>'','image_path'=>'images/test.webp','stock_status'=>'in_stock','published'=>1,'revision_number'=>1,'category'=>'batohy']);
  $db->insert('shop_product_inventory',['product_key'=>$key,'available_quantity'=>3]);$db->insert('shop_product_sales',['product_key'=>$key,'mode'=>'assisted','partner_id'=>$ids[$primary],'supplier_price_czk'=>1000,'variant_prices_json'=>'{}']);
  foreach(['a','b'] as $p)(new ProductSupplierLinkRepository($db))->save($key,0,$p,'https://'.$p.'.example.cz/'.$name);
  return $key;
 };
 $x=$make('one','a');$y=$make('two','b');
 $now=time();$catalog=new PartnerOfferCatalog($db,$now);
 $seed=static function()use($catalog,$db,$now):void {
  foreach($catalog->snapshots() as $key=>$snapshot)foreach(PartnerOfferCatalog::candidates($snapshot) as $candidate){
   $db->replace('shop_supplier_offers',['offer_key'=>PartnerOfferCatalog::offerKey($key,'',$candidate['partner_key'],$candidate['url']),
    'product_key'=>$key,'variant_key'=>'','partner_key'=>$candidate['partner_key'],'source_url'=>$candidate['url'],'resolved_url'=>$candidate['url'],
    'fingerprint'=>PartnerOfferCatalog::fingerprint($snapshot),'status'=>'verified','amount'=>'950.00','price_czk'=>1200,'availability'=>'in_stock',
    'reported_quantity'=>3,'observed_at'=>$now,'checked_at'=>$now,'evidence_json'=>'{}']);
  }
 };
 $seed();$check(count($catalog->report([$x])['products'])===1,'Scoped report reads unrelated products.');
 $sales=new ProductSalesRepository($db);$stock=new ProductStockRepository($db,$sales);
 $products=new ProductRepository($db,['cs'],null,50,$stock);
 $service=new CartService($products,['cs'],$sales);$cart=new CartSession('/');$cart->clear();
 $service->add($cart,$x,'cs',[],1);$service->add($cart,$y,'cs',[],1);
 $summary=$service->summary($cart);
 $check($summary['can_continue']&&$summary['partner_plan']['count']===1,'Actual cart did not consolidate sellers: '.json_encode($summary));
 $check($summary['subtotal_czk']===6000&&array_sum(array_column($summary['items'],'supplier_price_czk'))===1900,'Retail total or purchase amount incorrect.');
 $selection=new CartPartnerSelection($db,$now);$selection->validate($summary['items']);
 $db->startTransaction();$stock->reserve(987,$summary['items']);$db->commit();
 $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$x)===3,'Assisted order decremented own inventory.');
 $proof=$summary['items'][0]['procurement'];
 $db->update('shop_supplier_offers',['reported_quantity'=>1],'product_key=%s AND partner_key=%s',$summary['items'][0]['product_key'],$proof['partner_key']);
 $reject(fn()=>$selection->validate($summary['items']));$seed();
 $db->update('shop_supplier_offers',['observed_at'=>$now-86401],'partner_key=%s',$proof['partner_key']);
 $reject(fn()=>$selection->validate($summary['items']));$seed();
 $costs=new PartnerDeliveryCosts($db);$costs->save($ps['a']['partner_key'],['shipping_czk'=>100,'free_from_czk'=>null]);$costs->save($ps['b']['partner_key'],['shipping_czk'=>0,'free_from_czk'=>null]);
 $summary=$service->summary($cart);$check($summary['partner_plan']['priced']&&$summary['items'][0]['seller']['name']==='b','Configured shipping did not change seller.');
 $costs->save($ps['b']['partner_key'],['shipping_czk'=>50,'free_from_czk'=>null]);$reject(fn()=>$selection->validate($summary['items']));
 $summary=$service->summary($cart);$selection->validate($summary['items']);
 // Different-language lines cannot exceed one SKU's partner limit.
 $duplicate=$summary['items'];$duplicate[0]['quantity']=2;$duplicate[]=$duplicate[0];
 $reject(fn()=>$selection->validate($duplicate));
 // Deleted source invalidates evidence even if its row still exists in the evidence table.
 $selected=$summary['items'][0];$link=$db->queryFirstField('SELECT id FROM shop_product_supplier_links WHERE product_key=%s AND url=%s',$selected['product_key'],$selected['procurement']['source']['source_url']);
 (new ProductSupplierLinkRepository($db))->remove($selected['product_key'],(int)$link);
 $reject(fn()=>$selection->validate($summary['items']));
 $cart->clear();
 echo "Basket SQL integration: exact evidence, cart consolidation, freight, quantity, expiry, removed links, review revalidation and stock passed.\n";
} finally {$db->disconnect();$admin->query('DROP DATABASE %b',$database);$admin->disconnect();}
