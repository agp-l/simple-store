<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Product\{ProductPartnerAssignment as Assignment,PurchasePartnerRepository as Partners,PurchasePartnerTransfer as Transfer,ProductRepository,ProductSalesRepository,ProductSupplierLinkRepository};
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);$database='simple_store_partner_assignment_'.bin2hex(random_bytes(5));
$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);
$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $run):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid/stale operation accepted.');};
try {
    $schema=new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql');$schema->apply();
    $partners=new Partners($db);$links=new ProductSupplierLinkRepository($db);$assign=new Assignment($db);
    $sales=new ProductSalesRepository($db);$catalog=new ProductRepository($db,['cs','en']);$csrf='test-assignment-csrf';
    $a=$partners->save(0,['name'=>'Seller A','website_url'=>'https://www.a.test/','priority'=>'20']);
    $b=$partners->save(0,['name'=>'Seller B','website_url'=>'https://b.test/','priority'=>'10']);
    $c=$partners->save(0,['name'=>'Seller C','website_url'=>'https://c.test/','priority'=>'10']);
    $schema->apply();$check((int)$partners->find($a)['priority']===20,'Schema replay reset priorities.');
    $make=static function(string $name,?string $key=null,string $lang='cs',int $published=0)use($db):string{
        $key??=bin2hex(random_bytes(16));$db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$key,'language'=>$lang,
            'revision_number'=>1,'slug'=>'assign-'.$key,'active_slug'=>'assign-'.$key,'name'=>$name,'category'=>'batohy','subcategory'=>'lehke',
            'price_czk'=>2000,'description'=>'Keep text','image_path'=>'','stock_status'=>'on_order','published'=>$published]);
        $db->query('INSERT IGNORE INTO shop_product_inventory (product_key,available_quantity) VALUES (%s,7)',$key);return $key;
    };
    $matched=$make('Matched');$make('Matched EN',$matched,'en');
    $existing=$make('Existing',null,'cs',1);$noLinks=$make('No links');$unknown=$make('Unknown');$tie=$make('Tie');$orphan=$make('Orphan');
    $onlySeller=$make('Only seller');
    foreach([$matched,$existing,$orphan]as$key){$links->save($key,0,'A','https://a.test/p');$links->save($key,0,'B','https://www.b.test/p');}
    $links->save($unknown,0,'Unmatched','https://b.test.attacker.invalid/p');
    $links->save($tie,0,'B','https://b.test/p');$links->save($tie,0,'C','https://c.test/p');
    $sales->save($existing,'cs',['mode'=>'assisted','version'=>'0','partner_id'=>(string)$a,'supplier_price_czk'=>'1234']);
    $sales->save($onlySeller,'cs',['mode'=>'own','version'=>'0','partner_id'=>(string)$a]);
    $db->insert('shop_product_sales',['product_key'=>$orphan,'partner_id'=>999999,'mode'=>'own','variant_prices_json'=>'{}']);
    $db->insert('shop_orders',['id'=>1,'order_number'=>'ASSIGN-1','total_czk'=>2000,'items_json'=>'[{"seller":"old"}]','shipping_json'=>'{}',
        'status'=>'pending','payment_method'=>'bank_transfer','payment_status'=>'pending','variable_symbol'=>'1']);
    $db->insert('shop_order_stock_reservations',['order_id'=>1,'product_key'=>$existing,'quantity'=>2,'state'=>'reserved']);
    $keys=static fn(string $filter,int $offset=0,int $limit=48):array=>array_column($catalog->managementPage('cs',null,'','all',$offset,$limit,$filter)['items'],'product_key');
    $check(count($keys('no_partner'))===5 && count($keys('no_links'))===2 && count($keys('either'))===6 && $keys('both')===[$noLinks],'Missing filters or orphan detection incorrect.');
    $check(count($catalog->managementPage('cs','batohy/lehke','Matched','draft',0,48,'no_partner')['items'])===1,'Combined category/search/visibility filtering failed.');
    $paged=[];for($offset=0;$offset<6;$offset+=2)$paged=array_merge($paged,$keys('no_partner',$offset,2));
    $check($paged===$keys('no_partner'),'Missing filter applied after pagination.');
    $reject(static fn()=> $catalog->managementPage('cs',null,'','all',0,12,'bad'));
    foreach($catalog->publishedPage('cs')['items']as$row)$check(!array_key_exists('admin_partner_id',$row)&&!array_key_exists('admin_supplier_count',$row),'Private metadata leaked into public query.');
    $protected=[];foreach(['shop_product_revisions','shop_product_inventory','shop_orders','shop_order_stock_reservations','shop_product_supplier_links','shop_purchase_partners']as$table)$protected[$table]=$db->query('SELECT * FROM '.$table);
    $plans=$assign->previews();$plan=$plans['missing'];
    $check(count($plan['items'])===7 && $plan['counts']===['change'=>2,'kept'=>2,'same'=>0,'no_links'=>1,'no_match'=>1,'conflict'=>1,'incomplete'=>0],'Preview counts or translation deduplication failed.');
    $check($plans['all']['counts']['change']===3,'Reassign option ignored existing seller.');
    $token=Assignment::token($plan,$csrf);$reject(static fn()=> $assign->apply('all',$token,$csrf));$reject(static fn()=> $assign->apply('missing','bad',$csrf));
    $before=$db->query('SELECT * FROM shop_product_sales ORDER BY product_key');
    $db->update('shop_purchase_partners',['priority'=>9],'id=%i',$b);
    $reject(static fn()=> $assign->apply('missing',$token,$csrf));
    $db->update('shop_purchase_partners',['priority'=>10],'id=%i',$b);
    $db->update('shop_product_supplier_links',['url'=>'https://b.test/changed'],'product_key=%s AND label=%s',$matched,'B');
    $reject(static fn()=> $assign->apply('missing',$token,$csrf));
    $db->update('shop_product_supplier_links',['url'=>'https://www.b.test/p'],'product_key=%s AND label=%s',$matched,'B');
    $check($db->query('SELECT * FROM shop_product_sales ORDER BY product_key')===$before,'Rejected operation partially wrote products.');
    $result=$assign->apply('missing',$token,$csrf);$check($result['change']===2,'Missing assignment failed.');
    $check((int)$sales->forProduct($matched)['partner_id']===$b && $sales->forProduct($matched)['mode']==='own','Matched draft seller changed sale mode.');
    $check((int)$sales->forProduct($existing)['partner_id']===$a && $sales->forProduct($existing)['supplier_price_czk']===1234,'Default changed manual seller or cost.');
    $check(count($keys('no_partner'))===3,'Filter did not reflect assigned products.');
    $repeat=$assign->previews()['missing'];$before=$db->query('SELECT * FROM shop_product_sales ORDER BY product_key');
    $check($assign->apply('missing',Assignment::token($repeat,$csrf),$csrf)['change']===0 && $db->query('SELECT * FROM shop_product_sales ORDER BY product_key')===$before,'Repeat changed versions.');
    $all=$assign->previews()['all'];$assign->apply('all',Assignment::token($all,$csrf),$csrf);
    $check((int)$sales->forProduct($existing)['partner_id']===$b && $sales->forProduct($existing)['supplier_price_czk']===null && $sales->forProduct($existing)['variant_prices']===[] && $sales->forProduct($existing)['mode']==='assisted','New seller inherited previous purchase costs or changed sale mode.');
    foreach($protected as$table=>$rows)$check($db->query('SELECT * FROM '.$table)===$rows,'Assignment touched '.$table);
    $check($partners->find($b)['confirmed']==0,'Assignment falsely confirmed a seller.');
    $export=(new Transfer($db))->snapshot();$check($export['partners'][0]['priority']===20,'Priority missing from export.');
    // Precise variant sources, not the legacy model URLs, determine the usable seller.
    $variantKey=$make('Variant sources');
    $variantRepo=new \SimpleStore\Product\ProductVariantRepository($db);
    $variantLinks=new \SimpleStore\Product\ProductVariantSupplierRepository($db);
    $items=\SimpleStore\Product\ProductVariants::generate('Velikost: S | M');
    $variantRepo->save($variantKey,['enabled'=>true,'items'=>$items,'expected'=>$variantRepo->snapshot($variantKey)['expected']]);
    $items=$variantRepo->snapshot($variantKey)['items'];
    $links->save($variantKey,0,'Legacy B','https://b.test/model');
    $partnerA=$partners->find($a)['partner_key'];$partnerB=$partners->find($b)['partner_key'];
    $v1=$variantLinks->save($variantKey,$items[0]['id'],'',0,$partnerA,'A S','https://a.test/model?size=S');
    $findPlan=static function(string $key)use($assign):array{
        foreach($assign->previews()['all']['items']as$item)if($item['key']===$key)return$item;
        throw new RuntimeException('Missing product in assignment plan.');
    };
    $check($findPlan($variantKey)['state']==='incomplete','Partial sources resurrected a common model source.');
    $v2=$variantLinks->save($variantKey,$items[1]['id'],'',0,$partnerA,'A M','https://a.test/model?size=M');
    $variantLinks->save($variantKey,$items[0]['id'],'',0,$partnerB,'B S','https://b.test/model?size=S');
    $check($findPlan($variantKey)['partner']['id']==$a,'Higher-priority seller with incomplete coverage won.');
    $sales->save($variantKey,'cs',['mode'=>'assisted','version'=>'0','partner_id'=>(string)$b,
        'supplier_price_czk'=>'1234','variant_price'=>[$items[0]['id']=>'1300']]);
    $plan=$assign->previews()['all'];
    $variantLinks->save($variantKey,$items[1]['id'],$v2,1,$partnerA,'A M','https://a.test/model?size=M2');
    $reject(static fn()=> $assign->apply('all',Assignment::token($plan,$csrf),$csrf));
    $plan=$assign->previews()['all'];
    $db->update('shop_product_variants',['available_quantity'=>2],'product_key=%s',$variantKey);
    $assign->apply('all',Assignment::token($plan,$csrf),$csrf);
    $check((int)$sales->forProduct($variantKey)['partner_id']===$a && $sales->forProduct($variantKey)['supplier_price_czk']===null &&
        $sales->forProduct($variantKey)['variant_prices']===[], 'Variant partner change retained another seller costs.');
    $variantLinks->remove($variantKey,$items[0]['id'],$v1,1);
    $variantLinks->remove($variantKey,$items[1]['id'],$v2,2);
    $check($findPlan($variantKey)['state']==='incomplete','Removed variant links or common fallback were reused.');
    $db->update('shop_product_variants',['enabled'=>0],'product_key=%s AND variant_key=%s',$variantKey,$items[1]['id']);
    $check($findPlan($variantKey)['partner']['id']==$b,'Disabled variant blocked complete active coverage.');
    echo "Product partner assignment: MySQL matching, preview binding, idempotency, filters, pagination and protected data passed.\n";
} finally {$db->disconnect();$admin->query('DROP DATABASE %b',$database);}
