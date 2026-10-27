<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Product\{ProductRemoval,PartnerRefreshQueue,PurchasePartnerRepository};
use SimpleStore\Media\{MediaPath,MediaCatalog,ProductMediaCleanup};
use SimpleStore\Supplier\{PartnerOfferCatalog,WorkerBusy};

$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);
$database='simple_store_removal_'.bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);
$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$other=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$root=sys_get_temp_dir().'/product-removal-'.bin2hex(random_bytes(5)); mkdir($root,0700);
$check=static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
$reject=static function(callable $call,string $message)use($check):void {
    try { $call(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
};
try {
    $schema=new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'); $schema->apply();
    $removal=new ProductRemoval($db,$root,['cs','en']); $cleanup=new ProductMediaCleanup($db,$root);
    $check($removal->installed(),'Additive migration did not install removal tables.');
    $key=str_repeat('a',32); $keep=str_repeat('b',32); $variant=str_repeat('c',32);
    $product=static function(string $key,string $language='cs',int $revision=1,bool $active=true,bool $published=true,string $image='')use($db):void {
        $slug='product-'.$key.'-'.$language.'-'.$revision;
        $db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$active?$key:null,
            'language'=>$language,'revision_number'=>$revision,'slug'=>$slug,'active_slug'=>$active?$slug:null,
            'name'=>'Výrobek '.$key,'price_czk'=>1000,'description'=>'','image_path'=>$image,
            'category'=>'batohy','stock_status'=>'in_stock','published'=>(int)$published]);
    };
    $photo=static function(string $key,string $label)use($root):string {
        $path=MediaPath::directory('product',$key).'/'.$label.'--'.bin2hex(random_bytes(12)).'.webp';
        if (!is_dir(dirname($root.'/'.$path))) mkdir(dirname($root.'/'.$path),0700,true);
        foreach ([$path,MediaPath::variant($path,'card'),MediaPath::variant($path,'thumb')] as $file) file_put_contents($root.'/'.$file,'fixture');
        return $path;
    };
    $photos=[]; foreach (['current','document','order','invoice','family','variant','historical','unused','retry','new-reference'] as $label) $photos[$label]=$photo($key,$label);
    $foreign=$photo($keep,'foreign');
    $product($key,'cs',1,false,true,$photos['unused']);
    $product($key,'cs',2,true,true,$photos['unused']); $product($key,'en',1,true,true,$foreign);
    $product($keep,'cs',1,false,true,$photos['historical']); $product($keep,'cs',2,true,true,$photos['current']);
    $db->insert('shop_product_inventory',['product_key'=>$key,'available_quantity'=>3]);
    $db->insert('shop_product_inventory',['product_key'=>$keep,'available_quantity'=>7]);
    $partner=(new PurchasePartnerRepository($db))->save(0,['name'=>'Partner','website_url'=>'https://partner.test']);
    $partnerKey=$db->queryFirstField('SELECT partner_key FROM shop_purchase_partners WHERE id=%i',$partner);
    $db->insert('shop_product_sales',['product_key'=>$key,'mode'=>'assisted','partner_id'=>$partner,'supplier_price_czk'=>700,'variant_prices_json'=>'{}']);
    $db->insert('shop_product_variants',['product_key'=>$key,'variant_key'=>$variant,'choices_json'=>'{"Barva":"Modrá"}','available_quantity'=>3,'image_path'=>$photos['unused']]);
    $db->insert('shop_product_variant_settings',['product_key'=>$key,'enabled'=>1]);
    $db->insert('shop_product_supplier_variant_modes',['product_key'=>$key,'enabled'=>1]);
    $db->insert('shop_product_variants',['product_key'=>$keep,'variant_key'=>$variant,'choices_json'=>'{}','image_path'=>$photos['variant']]);
    $db->insert('shop_product_supplier_links',['product_key'=>$key,'label'=>'Partner','url'=>'https://partner.test/p']);
    $db->insert('shop_product_variant_supplier_links',['link_key'=>$variant,'product_key'=>$key,'variant_key'=>$variant,'partner_key'=>$partnerKey,'label'=>'Partner','url'=>'https://partner.test/p?blue','url_hash'=>hash('sha256','blue'),'result_json'=>'{}']);
    $db->insert('shop_partner_stock_state',['product_key'=>$key,'partner_id'=>$partner,'observed_at'=>time()]);
    (new PartnerRefreshQueue($db))->request($key,0);
    (new PartnerOfferCatalog($db))->request($key);
    $db->insert('shop_supplier_price_jobs',['product_key'=>$key,'product_name'=>'Výrobek','created_at'=>time(),'data_json'=>'{}']);
    $db->insert('shop_supplier_offers',['offer_key'=>hash('sha256','offer'),'product_key'=>$key,'partner_key'=>$partnerKey,'source_url'=>'https://partner.test/p','fingerprint'=>hash('sha256','snapshot'),'status'=>'verified','checked_at'=>time(),'evidence_json'=>'{}']);
    $db->insert('shop_product_relations',['language'=>'cs','product_a'=>$key,'product_b'=>$keep]);
    $db->insert('shop_homepage_selections',['language'=>'cs','product_keys_json'=>json_encode([$key,$keep])]);
    $db->insert('shop_category_product_selections',['language'=>'cs','category_path'=>'batohy','product_keys_json'=>json_encode([$key])]);
    $db->insert('shop_product_redirects',['language'=>'cs','source_slug'=>'old-address','target_key'=>$key]);
    $db->insert('shop_product_redirects',['language'=>'cs','source_slug'=>'product-'.$key.'-cs-1','target_key'=>$keep]);
    $document=str_repeat('d',32);
    $db->insert('shop_content_revisions',['document_key'=>$document,'active_document_key'=>null,'language'=>'cs','revision_number'=>1,'type'=>'post','slug'=>'historic-article','title'=>'Článek','body'=>$photos['document'],'published'=>0]);
    $orderItems=json_encode([['product_key'=>$key,'image'=>$photos['order'],'name'=>'Historický název','quantity'=>1,'unit_price_czk'=>900]]);
    $db->insert('shop_orders',['id'=>1,'order_number'=>'REMOVAL-1','total_czk'=>900,'items_json'=>$orderItems,'shipping_json'=>'{}','status'=>'pending','payment_method'=>'bank_transfer','payment_status'=>'pending','variable_symbol'=>'1']);
    $db->insert('shop_invoices',['order_id'=>1,'order_number'=>'REMOVAL-1','document_number'=>'REMOVAL-1','issue_date'=>'2026-10-27','due_date'=>'2026-11-10','seller_json'=>'{}','buyer_json'=>'{}','items_json'=>json_encode([['product_key'=>$key,'image'=>$photos['invoice']]]),'subtotal_czk'=>900,'shipping_czk'=>0,'total_czk'=>900]);
    $db->insert('shop_sale_lines',['order_id'=>1,'line_no'=>1,'product_key'=>$key,'name'=>'Historický název','quantity'=>1,'unit_price_czk'=>900]);
    $db->insert('shop_stock_movements',['product_key'=>$key,'movement_date'=>'2026-10-27','quantity_change'=>-1,'description'=>'Prodej']);
    $db->insert('shop_order_stock_reservations',['order_id'=>1,'product_key'=>$key,'quantity'=>1,'state'=>'reserved']);
    $db->insert('shop_order_variant_reservations',['order_id'=>1,'product_key'=>$key,'variant_key'=>$variant,'quantity'=>1,'state'=>'reserved']);
    $db->insert('shop_product_family_merges',['target_key'=>$key,'before_json'=>json_encode(['image'=>$photos['family']]),'after_hash'=>hash('sha256','before')]);
    $historyTables=['shop_orders','shop_invoices','shop_sale_lines','shop_stock_movements','shop_order_stock_reservations','shop_order_variant_reservations','shop_product_family_merges'];
    $history=[]; foreach ($historyTables as $table) $history[$table]=$db->query('SELECT * FROM '.$table);
    (new MediaCatalog($root))->update(static function(array &$state)use($photos):void { foreach($photos as $path) $state['files'][$path]=['folder'=>'']; });

    $plan=$removal->preview($key,'cs',2);
    $check(count($plan['photos']['delete'])===3 && count($plan['photos']['retain'])===7 && $plan['orders']===1,'Preview did not separate owned/history photos.');
    $check(is_file($root.'/'.$photos['unused']) && count($plan['current'])===2,'Preview mutated the product or omitted translation.');
    $db->update('shop_product_revisions',['name'=>'Changed translation'],'product_key=%s AND language=%s',$key,'en');
    $reject(fn()=>$removal->delete($plan),'Concurrent translation edit accepted.');
    $plan=$removal->preview($key,'cs',2);
    $db->update('shop_product_variants',['sku'=>'changed'],'product_key=%s',$key);
    $reject(fn()=>$removal->delete($plan),'Concurrent variant edit accepted.');
    $plan=$removal->preview($key,'cs',2);
    $extra=$photo($key,'uploaded-after-preview');
    $reject(fn()=>$removal->delete($plan),'Concurrent upload accepted.');
    (new \SimpleStore\Media\MediaLibrary($root))->deleteManaged('product',$key,$extra);
    $plan=$removal->preview($key,'cs',2);
    $db->update('shop_supplier_price_jobs',['message'=>'Background progress'],'product_key=%s',$key);
    $check($removal->preview($key,'cs',2)['hash']===$plan['hash'],'Transient worker progress invalidates admin confirmation.');
    $lockName='supplier-prices:'.sha1($database);
    $other->queryFirstField('SELECT GET_LOCK(%s,0)',$lockName);
    try { $removal->delete($plan); throw new RuntimeException('Deletion raced a price worker.'); } catch (WorkerBusy $expected) {}
    $other->query('SELECT RELEASE_LOCK(%s)',$lockName);
    $db->query("CREATE TRIGGER fail_product_removal BEFORE DELETE ON shop_product_revisions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test rollback'");
    try { $removal->delete($plan); throw new RuntimeException('Failure trigger ignored.'); }
    catch (\MeekroDBException $expected) {}
    $db->query('DROP TRIGGER fail_product_removal');
    $check($removal->removed('cs','old-address')===null && $cleanup->state($key)===null && is_file($root.'/'.$photos['unused']),'Failed transaction leaked tombstone/cleanup/deleted files.');
    $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_supplier_offers WHERE product_key=%s',$key)===1,'Rollback lost partner evidence.');
    // A newly added use must be rechecked at confirmation, independently of preview photo counts.
    $db->update('shop_product_revisions',['summary'=>$photos['new-reference']],'product_key=%s AND active_product_key IS NOT NULL',$keep);
    // A filesystem problem happens after the product transaction, leaving a retryable manifest.
    $thumb=$root.'/'.MediaPath::variant($photos['retry'],'thumb'); unlink($thumb);
    $outside=$root.'/outside'; file_put_contents($outside,'do not touch'); symlink($outside,$thumb);
    $result=$removal->delete($plan);
    $check($result['counts']===['deleted'=>1,'retained'=>8,'pending'=>1],'Incorrect durable photo cleanup result.');
    $check(file_get_contents($outside)==='do not touch' && !file_exists($root.'/'.$photos['unused']),'Unsafe symlink handling or unused original left.');
    foreach (['card','thumb'] as $size) $check(!file_exists($root.'/'.MediaPath::variant($photos['unused'],$size)),'Generated size left behind.');
    foreach (array_merge($plan['photos']['retain'],[$photos['new-reference'],$foreign]) as $path) $check(is_file($root.'/'.$path),'Used/foreign photo deleted: '.$path);
    foreach ($historyTables as $table) $check($db->query('SELECT * FROM '.$table)===$history[$table],'Historical rows changed in '.$table);
    foreach (array_keys($plan['counts']) as $table) $check((int)$db->queryFirstField('SELECT COUNT(*) FROM '.$table.' WHERE product_key=%s',$key)===0,'Orphan in '.$table);
    $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$keep)===7,'Another product stock changed.');
    $check(json_decode($db->queryFirstField('SELECT product_keys_json FROM shop_homepage_selections WHERE language=%s','cs'),true)===[$keep],'Homepage references not removed.');
    $check($db->queryFirstField('SELECT product_keys_json FROM shop_category_product_selections WHERE language=%s','cs')==='[]','Explicit empty category selection not retained.');
    $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_product_relations')===0,'Related-product link retained.');
    foreach ([['cs','old-address'],['cs','product-'.$key.'-cs-1'],['cs','product-'.$key.'-cs-2'],['en','product-'.$key.'-en-1']] as [$language,$slug]) $check($removal->removed($language,$slug)!==null,'Published URL not remembered.');
    $check($db->queryFirstField('SELECT target_key FROM shop_product_redirects WHERE source_slug=%s','product-'.$key.'-cs-1')===$keep,'Outgoing canonical redirect removed.');
    (new PartnerRefreshQueue($db))->request($key,time()); (new PartnerOfferCatalog($db))->request($key);
    $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_partner_refresh_requests WHERE product_key=%s',$key)===0 && (int)$db->queryFirstField('SELECT COUNT(*) FROM shop_supplier_offer_scans WHERE product_key=%s',$key)===0,'Historical action recreated orphan queues.');
    $check(!isset((new PartnerOfferCatalog($db))->snapshots()[$key]),'Removed identity remained in supplier coverage.');
    unlink($thumb); $result=$cleanup->run($key);
    $check($result['counts']===['deleted'=>2,'retained'=>8,'pending'=>0] && $cleanup->run($key)['counts']===$result['counts'],'Cleanup retry not idempotent.');
    $state=(new MediaCatalog($root))->state();
    $check(!isset($state['files'][$photos['unused']]) && !isset($state['files'][$photos['retry']]),'Deleted media metadata remains.');
    $schema->apply();
    $check($cleanup->state($key)['counts']===$result['counts'],'Repeated schema apply damaged cleanup history.');

    $hidden=str_repeat('e',32); $product($hidden,'cs',1,true,false);
    $removal->delete($removal->preview($hidden,'cs',1));
    $check($removal->removed('cs','product-'.$hidden.'-cs-1')===null,'A private draft leaked to a public tombstone.');
    // Reimporting an old identity deliberately stops any historical file cleanup.
    $product($key,'cs',1,true,true,$photos['order']);
    $reject(fn()=>$cleanup->run($key),'Cleanup touched a reimported identity.');
    $check(is_file($root.'/'.$photos['order']),'Reimported photo lost.');
    echo "Product deletion: preview, multilingual concurrency, worker exclusion, rollback, queues, history, URLs, photos and retries passed.\n";
} finally {
    $db->disconnect(); $other->disconnect(); $admin->query('DROP DATABASE %b',$database); $admin->disconnect();
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($root);
}
