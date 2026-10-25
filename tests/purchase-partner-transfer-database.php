<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory, SchemaUpdater};
use SimpleStore\Product\{PurchasePartnerRepository as Partners, PurchasePartnerTransfer as Transfer, ProductSalesRepository};
$db=ConnectionFactory::create(['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306]);
(new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $run):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe partner transfer accepted.');};
$partners=new Partners($db);$transfer=new Transfer($db);$sales=new ProductSalesRepository($db);
$initialIds=array_map('intval',array_column($partners->all(),'id'));$productKey=bin2hex(random_bytes(16));
$full=['name'=>'Transfer test','ico'=>'76543210','address'=>'Test 1, Praha','email'=>'seller@example.test','website_url'=>'https://example.test/','terms_url'=>'https://example.test/terms','confirmed'=>'1'];
try {
    $id=$partners->save(0,$full);$original=$partners->find($id);$identity=$original['partner_key'];
    $check(preg_match('/^[a-f0-9]{32}$/D',$identity)===1,'New seller has no portable identity.');
    $db->insert('shop_product_revisions',['product_key'=>$productKey,'active_product_key'=>$productKey,'language'=>'cs','revision_number'=>1,
        'slug'=>'partner-transfer-'.$productKey,'active_slug'=>'partner-transfer-'.$productKey,'name'=>'Partner transfer test','category'=>'batohy',
        'price_czk'=>2000,'description'=>'','image_path'=>'','stock_status'=>'on_order','published'=>1]);
    $sales->save($productKey,'cs',['mode'=>'assisted','version'=>'0','partner_id'=>(string)$id,'supplier_price_czk'=>'1000']);
    $orderSnapshot=$sales->snapshot($productKey,'',2000);
    $export=$transfer->snapshot();
    $entry=array_values(array_filter($export['partners'],static fn($p)=>$p['key']===$identity))[0];
    $check(!isset($entry['id'],$entry['version'],$entry['product_count']) && $entry['website_url']===$full['website_url'],'Export leaked local IDs or lost the website.');
    $entry['website_url']='https://new.example.test/';
    $new=array_replace($entry,['key'=>str_repeat('c',32),'ico'=>'76543211','name'=>'New imported seller','confirmed'=>false]);
    $data=Transfer::decode(Transfer::json(['format'=>Transfer::FORMAT,'version'=>1,'partners'=>[$entry,$new]]));
    $plan=$transfer->plan($data);$csrf='partner-test-csrf';
    $check($plan['counts']===['new'=>1,'changed'=>1,'same'=>0],'Preview classified changes incorrectly.');
    $check($partners->find($id)===$original,'Preview changed stored profile.');
    $transfer->apply($data,Transfer::token($data,$plan['revision'],$csrf),$csrf);
    $updated=$partners->find($id);
    $check($updated['partner_key']===$identity && $updated['website_url']==='https://new.example.test/','Import replaced existing identity.');
    $check((int)$sales->forProduct($productKey)['partner_id']===$id,'Import broke the product link.');
    $check($orderSnapshot['seller']['website_url']===$full['website_url'],'Import changed historical seller snapshot.');
    $rows=$partners->all();$repeat=$transfer->plan($data);
    $check($repeat['counts']===['new'=>0,'changed'=>0,'same'=>2],'Repeated import is not idempotent.');
    $transfer->apply($data,Transfer::token($data,$repeat['revision'],$csrf),$csrf);
    $check($partners->all()===$rows,'Repeat import changed versions or duplicated sellers.');
    $icoOnly=$data;unset($icoOnly['partners'][0]['key']);$icoOnly=Transfer::decode(Transfer::json($icoOnly));
    $check($transfer->plan($icoOnly)['changes'][0]['id']===$id,'New import without key did not reuse IČO.');
    $stale=$transfer->plan($data);$partners->save($id,array_replace($full,['version'=>(string)$updated['version'],'name'=>'Concurrent edit']));
    $reject(static fn()=>$transfer->apply($data,Transfer::token($data,$stale['revision'],$csrf),$csrf));
    $check($partners->find($id)['name']==='Concurrent edit','Stale preview overwrote a newer edit.');
    $tampered=$data;$tampered['partners'][1]['name']='Changed after preview';$fresh=$transfer->plan($data);
    $reject(static fn()=>$transfer->apply($tampered,Transfer::token($data,$fresh['revision'],$csrf),$csrf));
    $invalid=$data;$invalid['partners'][1]['website_url']='javascript:alert(1)';$before=$partners->all();
    $reject(static fn()=>$transfer->apply($invalid,'anything',$csrf));$check($partners->all()===$before,'Invalid batch wrote partial changes.');
    $draftId=$partners->save(0,['name'=>'Draft without IČO']);
    $backup=$transfer->snapshot();$roundtrip=$transfer->plan(Transfer::decode(Transfer::json($backup)));
    $check($roundtrip['counts']['new']===0 && $roundtrip['counts']['changed']===0,'Whole directory including drafts cannot round-trip.');
    $check((new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply()===false && $partners->find($id)['partner_key']===$identity,'Repeated migration changed the identity.');
    echo "Partner directory export/import, duplicate prevention, stale previews and product links passed.\n";
} finally {
    $db->query('DELETE FROM shop_product_sales WHERE product_key=%s',$productKey);
    $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s',$productKey);
    foreach($partners->all() as $row) if(!in_array((int)$row['id'],$initialIds,true)) $db->query('DELETE FROM shop_purchase_partners WHERE id=%i',(int)$row['id']);
}
