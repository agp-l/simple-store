<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never { throw new ErrorException($message,0,$severity,$file,$line); },E_WARNING|E_NOTICE);
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Product\{ProductRepository,ProductSalesRepository,ProductStockRepository,PurchasePartnerRepository};
use SimpleStore\Checkout\{CartPartnerPlanner,CartService,CartSession,CheckoutReview,AssistedPurchase,OrderRepository,BankTransferPayment};
use SimpleStore\Supplier\PartnerOfferCatalog;
use SimpleStore\Accounting\{OrderLegalDocuments,MailSettingsRepository};
use SimpleStore\Content\ContentRepository;
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config); $database='simple_store_cart_partners_'.bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);
$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $why):void { if (!$ok) throw new RuntimeException($why); };
$reject=static function(callable $run):void { try { $run(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Stale allocation accepted.'); };
try {
    (new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
    $partners=new PurchasePartnerRepository($db); $ids=[]; $partnerKeys=[];
    foreach (['a','b','c'] as $name) {
        $ids[$name]=$partners->save(0,['name'=>'Partner '.$name,'ico'=>'12345678','address'=>'Street 1','email'=>$name.'@example.test',
            'website_url'=>'https://'.$name.'.test','terms_url'=>'https://'.$name.'.test/terms','confirmed'=>'1']);
        $partnerKeys[$name]=$partners->find($ids[$name])['partner_key'];
    }
    $keys=[];
    foreach (['a','b','own'] as $name) {
        $key=bin2hex(random_bytes(16)); $keys[$name]=$key;
        $db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$key,'language'=>'cs','revision_number'=>1,
            'slug'=>'allocation-'.$name,'active_slug'=>'allocation-'.$name,'name'=>'Test '.$name,'category'=>'batohy',
            'price_czk'=>100,'description'=>'','image_path'=>'','stock_status'=>'in_stock','published'=>1]);
        $db->insert('shop_product_inventory',['product_key'=>$key,'available_quantity'=>10]);
        if ($name==='own') continue;
        $db->insert('shop_product_sales',['product_key'=>$key,'mode'=>'assisted','partner_id'=>$ids[$name],'supplier_price_czk'=>60,'variant_prices_json'=>'{}']);
        foreach ([$name,'c'] as $source) $db->insert('shop_product_supplier_links',['product_key'=>$key,'label'=>$source,'url'=>'https://'.$source.'.test/p/'.$name]);
    }
    $now=time(); $catalog=new PartnerOfferCatalog($db,$now);
    $write=static function(string $name,array $changes=[]) use ($db,$catalog,$keys,$partnerKeys,$now):string {
        $key=$keys[$name]; $url='https://c.test/p/'.$name;
        $offerKey=PartnerOfferCatalog::offerKey($key,'',$partnerKeys['c'],$url);
        $snapshot=$catalog->snapshots([$key])[$key];
        $db->replace('shop_supplier_offers',array_replace(['offer_key'=>$offerKey,'product_key'=>$key,'variant_key'=>'','partner_key'=>$partnerKeys['c'],
            'source_url'=>$url,'resolved_url'=>$url,'fingerprint'=>PartnerOfferCatalog::fingerprint($snapshot),'status'=>'verified',
            'price_czk'=>$name==='a'?50:55,'amount'=>$name==='a'?50:55,'availability'=>'in_stock','reported_quantity'=>null,
            'observed_at'=>$now,'checked_at'=>$now,'evidence_json'=>'{}','message'=>''],$changes));
        return $offerKey;
    };
    $offerA=$write('a'); $write('b');
    $sales=new ProductSalesRepository($db); $stock=new ProductStockRepository($db,$sales);
    $planner=new CartPartnerPlanner($db,$now);
    $service=new CartService(new ProductRepository($db,['cs'],null,50,$stock),['cs'],$sales,$planner);
    $cart=new CartSession('/cart-partners-test/'); $cart->clear();
    foreach ($keys as $key) $service->add($cart,$key,'cs',[]);
    $summary=$service->summary($cart); $original=$summary;
    $check($summary['can_continue'] && $summary['subtotal_czk']===300,'Mixed cart totals changed.');
    $check(array_column(array_slice($summary['items'],0,2),'supplier_price_czk')===[50,55],'Verified alternative costs were not used.');
    foreach (array_slice($summary['items'],0,2) as $item) $check($item['seller']['name']==='Partner c','Basket did not consolidate.');
    $check(!isset($summary['items'][2]['seller'],$summary['items'][2]['supplier_selection']),'Own stock entered partner planning.');
    $check(count($catalog->report([$keys['a']])['products'])===1 && $catalog->snapshots([])===[],'Targeted catalog query scans unrelated products.');
    $before=$db->query('SELECT * FROM shop_product_sales ORDER BY product_key');
    foreach ([['observed_at'=>$now-86400],['observed_at'=>$now+1],['price_czk'=>61,'amount'=>61],['reported_quantity'=>1],
        ['availability'=>'out_of_stock'],['availability'=>'unknown'],['status'=>'error'],['fingerprint'=>str_repeat('0',64)],['variant_key'=>str_repeat('f',32)]] as $bad) {
        $write('a',$bad); $planned=$service->summary($cart);
        $check($planned['items'][0]['seller']['name']==='Partner a','Unsafe alternative accepted: '.json_encode($bad));
    }
    $write('a');
    $duplicate=$summary['items'][0]; $duplicate['quantity']=3; $duplicate['language']='en';
    $combined=$planner->plan(array_merge($summary['items'],[$duplicate]));
    $check($combined[0]['seller']['name']==='Partner a' && $combined[3]['seller']['name']==='Partner a','Translated lines bypassed the shared quantity limit.');
    $db->update('shop_purchase_partners',['confirmed'=>0],'id=%i',$ids['c']);
    $check($service->summary($cart)['items'][0]['seller']['name']==='Partner a','Unconfirmed partner was selected.');
    $db->update('shop_purchase_partners',['confirmed'=>1],'id=%i',$ids['c']);
    $check($before===$db->query('SELECT * FROM shop_product_sales ORDER BY product_key'),'Planning rewrote global product sales.');
    $token=CheckoutReview::token($original,[],0,'bank_transfer','legal','secret');
    $write('a',['price_czk'=>49,'amount'=>49]);
    $check($token!==CheckoutReview::token($service->summary($cart),[],0,'bank_transfer','legal','secret'),'Changed allocation escaped review signature.');
    $db->startTransaction(); $reject(fn()=>$planner->assertCurrent($original['items'])); $db->rollback();
    $write('a');
    // A checkout transaction may already have a REPEATABLE READ snapshot. Locking
    // validation must see a concurrent committed update, not that older read view.
    $other=ConnectionFactory::create(array_replace($config,['database'=>$database]));
    $db->startTransaction(); $db->queryFirstField('SELECT price_czk FROM shop_supplier_offers WHERE offer_key=%s',$offerA);
    $other->update('shop_supplier_offers',['price_czk'=>48,'amount'=>48],'offer_key=%s',$offerA);
    $reject(fn()=>$planner->assertCurrent($original['items'])); $db->rollback();
    $write('a');
    $db->startTransaction(); $db->queryFirstField('SELECT price_czk FROM shop_product_revisions WHERE product_key=%s',$keys['a']);
    $other->update('shop_product_revisions',['price_czk'=>101],'product_key=%s',$keys['a']);
    $reject(fn()=>$planner->assertCurrent($original['items'])); $db->rollback();
    $other->update('shop_product_revisions',['price_czk'=>100],'product_key=%s',$keys['a']); $other->disconnect();
    $write('a');
    // Conflicting active sources must not manufacture a usable price.
    $db->insert('shop_product_supplier_links',['product_key'=>$keys['a'],'label'=>'Duplicate','url'=>'https://c.test/second']);
    $extraLink=(int)$db->insertId(); $write('a');
    $extra=$db->queryFirstRow('SELECT * FROM shop_supplier_offers WHERE offer_key=%s',$offerA);
    $extra['offer_key']=PartnerOfferCatalog::offerKey($keys['a'],'',$partnerKeys['c'],'https://c.test/second');
    $extra['source_url']='https://c.test/second'; $extra['price_czk']=49; $extra['amount']=49;
    $db->insert('shop_supplier_offers',$extra);
    $check($service->summary($cart)['items'][0]['seller']['name']==='Partner a','Contradictory duplicate sources were used.');
    $db->query('DELETE FROM shop_product_supplier_links WHERE id=%i',$extraLink); $write('a');
    // Freeze the exact selected seller, cost and URL atomically with the real order.
    $contents=new ContentRepository($db);
    foreach ([AssistedPurchase::TERMS_SLUG,OrderLegalDocuments::TERMS_SLUG] as $slug) $contents->saveRevision(['type'=>'page','language'=>'cs','title'=>$slug,'slug'=>$slug,'body'=>'Test terms','published'=>true]);
    $templates=[]; foreach (MailSettingsRepository::EVENTS as $event=>$value) $templates[$event]=['enabled'=>'1','subject'=>$value['subject'],'message'=>$value['message']];
    (new MailSettingsRepository($db))->save(['from_email'=>'shop@example.test','from_name'=>'Test','reply_to'=>'','public_base_url'=>'https://shop.example.test','automatic_enabled'=>'1','templates'=>$templates]);
    $summary=$service->summary($cart); $bundle=(new OrderLegalDocuments($db))->checkoutBundle($summary['items']);
    $shipping=['method'=>'gls_home','label'=>'GLS','name'=>'Customer','assisted_purchase'=>['early_start'=>false,'early_request_text'=>AssistedPurchase::EARLY_REQUEST,'terms_hash'=>$bundle['hash']]];
    $orders=new OrderRepository($db,new BankTransferPayment('','1004823033/3030','Test'),7,$stock,$planner);
    $write('a',['reported_quantity'=>1]);
    $reject(fn()=>$orders->create(null,'buyer@example.test',$summary['items'],$shipping,79,bin2hex(random_bytes(32))));
    $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_orders')===0,'Rejected allocation left a partial order.');
    $write('a'); $idempotency=bin2hex(random_bytes(32));
    $order=$orders->create(null,'buyer@example.test',$summary['items'],$shipping,79,$idempotency);
    $check($order['items'][0]['supplier_selection']['source_url']==='https://c.test/p/a' && $order['items'][0]['seller']['name']==='Partner c','Order lost the selected supplier URL/identity.');
    $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$keys['a'])===10,'Assisted allocation consumed physical stock.');
    $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$keys['own'])===9,'Own stock was not reserved.');
    $write('a',['availability'=>'out_of_stock']);
    $retry=$orders->create(null,'buyer@example.test',$summary['items'],$shipping,79,$idempotency);
    $check($retry['id']===$order['id'] && $retry['items']===$order['items'],'Retry reallocated an existing contract.');
    // A real enabled blue variant can use its exact source; red cannot borrow it.
    $blue=bin2hex(random_bytes(16)); $red=bin2hex(random_bytes(16));
    $db->insert('shop_product_variant_settings',['product_key'=>$keys['a'],'enabled'=>1]);
    foreach ([$blue=>'Blue',$red=>'Red'] as $variant=>$color) $db->insert('shop_product_variants',['product_key'=>$keys['a'],'variant_key'=>$variant,'choices_json'=>json_encode(['Barva'=>$color]),'available_quantity'=>10,'enabled'=>1]);
    $url='https://c.test/p/a?color=blue';
    $db->insert('shop_product_variant_supplier_links',['link_key'=>bin2hex(random_bytes(16)),'product_key'=>$keys['a'],'variant_key'=>$blue,
        'partner_key'=>$partnerKeys['c'],'label'=>'Blue','url'=>$url,'url_hash'=>hash('sha256',$url),'result_json'=>'{}']);
    $exact=$db->queryFirstRow('SELECT * FROM shop_supplier_offers WHERE offer_key=%s',$offerA);
    $exact=array_replace($exact,['offer_key'=>PartnerOfferCatalog::offerKey($keys['a'],$blue,$partnerKeys['c'],$url),'variant_key'=>$blue,
        'source_url'=>$url,'resolved_url'=>$url,'availability'=>'in_stock','fingerprint'=>PartnerOfferCatalog::fingerprint($catalog->snapshots([$keys['a']])[$keys['a']])]);
    $db->insert('shop_supplier_offers',$exact); $cart->clear();
    $service->add($cart,$keys['a'],'cs',[],1,$blue); $service->add($cart,$keys['a'],'cs',[],1,$red); $service->add($cart,$keys['b'],'cs',[]);
    $variants=$service->summary($cart);
    $check($variants['can_continue'] && $variants['items'][0]['seller']['name']==='Partner c' && $variants['items'][1]['seller']['name']==='Partner a','Exact blue/red alternatives were mixed.');
    $check($variants['items'][0]['supplier_selection']['source_url']===$url,'Variant source URL lost.');
    echo "Cart partners: exact fresh offers, quantity, cost guard, review, atomic order snapshot and mixed stock passed.\n";
} finally { $db->disconnect(); $admin->query('DROP DATABASE %b',$database); }
