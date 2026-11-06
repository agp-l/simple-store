<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Notification\{NtfySettings as S,NtfyQueue as Q};
use SimpleStore\Admin\SettingsArchive as A;
use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$db=ConnectionFactory::create($config);$other=ConnectionFactory::create($config);
(new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
$check=static function(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); };
$reject=static function(callable $f):void { try{$f();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid input accepted'); };
$original=$db->query('SELECT * FROM shop_ntfy_settings');$ids=[];
$keyFile=dirname(__DIR__).'/config/.smtp-key.php';$keyExisted=is_file($keyFile);
$store=new S($db);$calls=0;$mode='sent';$reentrant=null;$order=null;
$queue=new Q($db,static function(array $payload,string $token)use(&$calls,&$mode,&$reentrant,&$order,$other,$check):array{
    ++$calls;
    $check($payload['topic']==='fixture-private' && str_contains($payload['message'],'Fixture buyer'),'Wrong notification');
    if($reentrant!==null) { $callback=$reentrant;$reentrant=null;$callback(); }
    if($mode==='throw')throw new RuntimeException('secret must not appear in log');
    return ['state'=>$mode,'error'=>$mode==='sent'?'':'Fixture rejection'];
});
$save=static function(bool $enabled=true,string $topic='fixture-private',string $token='')use($store):void{
    $store->save(['enabled'=>$enabled?'1':'0','address'=>'https://ntfy.sh/'.$topic,'token'=>$token,'priority'=>'3']);
};
$row=static fn(int $id)=>$db->queryFirstRow('SELECT * FROM shop_ntfy_outbox WHERE order_id=%i',$id);
$newOrder=static function()use($db,&$ids):array{
    $number='NTFY-'.bin2hex(random_bytes(8));
    $db->insert('shop_orders',['order_number'=>$number,'status'=>'new','total_czk'=>1100,'subtotal_czk'=>1000,'shipping_czk'=>100,'customer_email'=>'fixture@example.test','items_json'=>'[{"name":"Test item","quantity":1,"unit_price_czk":1000,"options":{"Size":"M"}}]','shipping_json'=>'{"recipient":"Fixture buyer","label":"Courier"}']);
    $id=(int)$db->insertId();$ids[]=$id;
    return (new OrderRepository($db))->findById($id);
};
try {
    $save(false);$order=$newOrder();
    $check(!$queue->enqueue($order) && $row((int)$order['id'])===null,'Disabled notification was queued');
    $save(true,'fixture-private','fixture-token');$cipher=$store->load()['token_encrypted'];
    $check($cipher!=='' && $cipher!=='fixture-token','Token stored in cleartext');
    $save();$check($store->load()['token_encrypted']===$cipher,'Blank token erased or rewrote saved credential');
    $reject(fn()=>$store->save(['enabled'=>'1','address'=>'https://evil.test/topic','priority'=>'3']));
    $check($store->load()['token_encrypted']===$cipher,'Invalid settings altered saved token');
    $archive=new A($db);$before=$archive->snapshot();
    $check($before['ntfy'][1]['settings_json']['token']==='fixture-token','Backup omitted ntfy token');
    $check($archive->apply(['ntfy'=>[1=>['settings_json'=>['token'=>'']]]],true,A::digest($before))===0,'Empty import erased token');
    $check($archive->apply($before,true,A::digest($before))===0,'Backup roundtrip rewrote settings');
    $reject(fn()=>$archive->plan($before,['ntfy'=>[1=>['settings_json'=>['topic'=>'other']]]],true));
    $check($queue->enqueue($order) && !$queue->enqueue($order),'Order deduplication failed');
    $reentrant=static function()use($other,$order,$check):void {
        $duplicate=new Q($other,static function():array{throw new RuntimeException('Must never send');});
        $check($duplicate->dispatch((int)$order['id'],true)==='skipped','Concurrent worker claimed sending message');
    };
    $check($queue->dispatch((int)$order['id'])==='sent' && $calls===1,'Delivery failed');
    $check($queue->dispatch((int)$order['id'],true)==='skipped' && $calls===1,'Sent message resent');
    $order=$newOrder();$queue->enqueue($order);$mode='failed';
    $check($queue->dispatch((int)$order['id'])==='failed','Rejection not tracked');
    $previousCalls=$calls;$queue->dispatchDue();$check($calls===$previousCalls,'Retry ignored backoff');
    $db->query('UPDATE shop_ntfy_outbox SET next_attempt_at=NULL WHERE order_id=%i',$order['id']);
    $mode='sent';$queue->dispatchDue();$check($row((int)$order['id'])['state']==='sent','Due retry failed');
    $order=$newOrder();$queue->enqueue($order);$mode='throw';
    $check($queue->dispatch((int)$order['id'])==='uncertain','Exception should be isolated');
    $previousCalls=$calls;$queue->dispatchDue();$check($calls===$previousCalls,'Uncertain delivery automatically retried');
    $check(!str_contains($row((int)$order['id'])['last_error'],'secret'),'Exception details leaked');
    $mode='sent';$check($queue->dispatch((int)$order['id'],true)==='sent','Confirmed manual retry failed');
    $order=$newOrder();$queue->enqueue($order);$save(false);
    $check($queue->dispatch((int)$order['id'],true)==='disabled','Disabled module still sent');
    $save(true,'different-topic');$previousCalls=$calls;
    $check($queue->dispatch((int)$order['id'])==='skipped' && $calls===$previousCalls,'Old order leaked to new topic');
    $save();$order=$newOrder();$queue->enqueue($order);
    $db->query("UPDATE shop_ntfy_outbox SET state='sending',attempted_at=DATE_SUB(NOW(),INTERVAL 10 MINUTE) WHERE order_id=%i",$order['id']);
    $queue->dispatchDue();$check($row((int)$order['id'])['state']==='uncertain','Interrupted send automatically retried');
    $order=$newOrder();$queue->enqueue($order);
    $db->query('UPDATE shop_ntfy_outbox SET order_number=%s WHERE order_id=%i','old-order-number',$order['id']);
    $check($queue->dispatch((int)$order['id'])==='skipped','Reused order ID was not rejected');
    $order=$newOrder();$queue->enqueue($order);
    $db->query('DELETE FROM shop_orders WHERE id=%i',$order['id']);
    $check($queue->dispatch((int)$order['id'])==='skipped','Deleted order was sent');
    // Corrupt notification configuration must not undo a real checkout commit.
    $db->query('UPDATE shop_ntfy_settings SET settings_json=%s WHERE id=1','{"enabled":true,"token_encrypted":"invalid"}');
    $items=[['product_key'=>bin2hex(random_bytes(16)),'language'=>'cs','slug'=>'ntfy-fixture','name'=>'Test checkout','quantity'=>1,'unit_price_czk'=>1000]];
    $shipping=['method'=>'courier','label'=>'Courier','recipient'=>'Fixture buyer'];$key=bin2hex(random_bytes(32));
    $repo=new OrderRepository($db);$saved=$repo->create(null,'fixture@example.test',$items,$shipping,100,$key,'comgate');$ids[]=(int)$saved['id'];
    $check($repo->findById((int)$saved['id'])!==null && $repo->create(null,'fixture@example.test',$items,$shipping,100,$key,'comgate')['id']===$saved['id'],'Notification failure broke saved/idempotent checkout');
    $check($row((int)$saved['id'])===null,'Bad configuration queued a message');
    echo "ntfy database: settings preservation, encrypted backup, deduplication, competing claims, backoff, failure isolation and checkout passed.\n";
} finally {
    foreach($ids as $id){$db->query('DELETE FROM shop_ntfy_outbox WHERE order_id=%i',$id);$db->query('DELETE FROM shop_order_legal_snapshots WHERE order_id=%i',$id);$db->query('DELETE FROM shop_orders WHERE id=%i',$id);}
    $db->query('DELETE FROM shop_ntfy_settings');foreach($original as $entry)$db->insert('shop_ntfy_settings',$entry);
    if(!$keyExisted && is_file($keyFile))unlink($keyFile);
}
