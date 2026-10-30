<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Admin\SettingsArchive as A;
use SimpleStore\Accounting\MailCredential;
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
$db=ConnectionFactory::create(['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306]);
(new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $f):void{try{$f();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid import accepted');};
$original=[];
foreach(A::TABLES as [$table]) $original[$table]=$db->query('SELECT * FROM '.$table);
$keyFile=dirname(__DIR__).'/config/.smtp-key.php';$keyExisted=is_file($keyFile);
$trigger='archive_fail_'.bin2hex(random_bytes(4));
$orders=(int)$db->queryFirstField('SELECT COUNT(*) FROM shop_orders');
try {
    $db->query('INSERT INTO shop_google_login_settings (id,settings_json) VALUES (1,%s) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)',A::json(['enabled'=>true,'client_id'=>'fixture.apps.googleusercontent.com','client_secret_encrypted'=>MailCredential::encrypt('fixture-secret'),'redirect_uri'=>'https://example.test/account/?google=callback']));
    $db->query('INSERT INTO shop_product_import_settings (id,prompt_text) VALUES (1,%s) ON DUPLICATE KEY UPDATE prompt_text=VALUES(prompt_text)','Keep prompt');
    $a=new A($db);$before=$a->snapshot();
    $site=['languages'=>['cs'],'default_language'=>'cs'];$method='GET';$action='';$_SESSION=[];
    require dirname(__DIR__).'/src/Admin/settings-export.php';
    $assert($archiveError==='', 'Export controller failed to initialize');
    $downloadData=$envelope($archive->snapshot());
    $assert(isset($downloadData['reference_only']['installation'],$downloadData['data']['checkout']), 'Comprehensive export coverage missing');
    $assert($a->snapshot()===$before, 'Opening Export changed settings');
    $reject(fn()=>$a->plan($before,['google'=>[1=>['settings_json'=>['client_id'=>'other.apps.googleusercontent.com']]]],true));
    $rows=$db->query('SELECT * FROM shop_google_login_settings');
    $assert($before['google'][1]['settings_json']['client_secret']==='fixture-secret','Portable secret missing');
    $assert(!isset($before['google'][1]['settings_json']['client_secret_encrypted']),'Cipher is not portable');
    $assert($a->snapshot()===$before && $db->query('SELECT * FROM shop_google_login_settings')===$rows,'Export mutated data');
    $assert($a->apply(A::decode(A::json(['format'=>A::FORMAT,'version'=>1,'data'=>$before])),true,A::digest($before))===0,'Round trip must be no-op');
    $assert($db->query('SELECT * FROM shop_google_login_settings')===$rows,'No-op import rewrote secret');
    $input=['google'=>[1=>['settings_json'=>['client_secret'=>'changed-secret']]],'product_import'=>[1=>['prompt_text'=>'New prompt']]];
    $assert($a->apply($input,false,A::digest($before))===0,'Default fill overwrote saved settings');
    $assert($a->apply(['google'=>[1=>['settings_json'=>['client_secret'=>'']]]],true,A::digest($before))===0,'Empty secret erased value');
    $reject(fn()=>$a->plan($before,['orders'=>[]],true));
    $reject(fn()=>$a->plan($before,['google'=>[2=>['settings_json'=>[]]]],true));
    $reject(fn()=>$a->plan($before,['google'=>[1=>['settings_json'=>['enabled'=>'yes']]]],true));
    $db->query('CREATE TRIGGER '.$trigger.' BEFORE UPDATE ON shop_product_import_settings FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'archive test rollback\'');
    $failed=false;try{$a->apply($input,true,A::digest($before));}catch(Throwable $e){$failed=true;}
    $assert($failed,'Fault injection did not fail');
    $assert($a->snapshot()===$before && $db->query('SELECT * FROM shop_google_login_settings')===$rows,'Failed import did not roll back every row');
    $db->query('DROP TRIGGER '.$trigger);
    $assert($a->apply($input,true,A::digest($before))===2,'Confirmed overwrite failed');
    $after=$a->snapshot();
    $assert($after['google'][1]['settings_json']['client_secret']==='changed-secret','Secret round trip failed');
    $assert($after['google'][1]['settings_json']['client_id']===$before['google'][1]['settings_json']['client_id'],'Omitted key was erased');
    $saved=json_decode($db->queryFirstField('SELECT settings_json FROM shop_google_login_settings WHERE id=1'),true);
    $assert(!isset($saved['client_secret']) && MailCredential::decrypt($saved['client_secret_encrypted'])==='changed-secret','Secret not encrypted on disk');
    $reject(fn()=>$a->apply($input,true,A::digest($before)));
    $assert($a->snapshot()===$after,'Stale preview wrote data');
    $assert((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_orders')===$orders,'Import changed orders');
    $checkout=require dirname(__DIR__).'/config/checkout.example.php';$checkout['bank_transfer']['enabled']=false;
    $before['checkout']=[1=>['settings_json'=>$checkout]];
    $plan=$a->plan($before,['checkout'=>[1=>['settings_json'=>['packeta'=>['api_password'=>'fixture-password']]]]],true);
    $assert(count($plan['changes'])===1,'Valid carrier update rejected');
    $assert($a->snapshot()===$after,'Checkout validator wrote data');
    $reject(fn()=>$a->plan($before,['checkout'=>[1=>['settings_json'=>['shipping_methods'=>['zasilkovna_pickup'=>['price_czk'=>-1]]]]]],true));
    echo "Settings archive DB: read-only export, secret round trip, non-destructive import, atomic rollback and stale preview passed.\n";
} finally {
    $db->query('DROP TRIGGER IF EXISTS '.$trigger);
    foreach($original as $table=>$rows){$db->query('DELETE FROM '.$table);foreach($rows as $row)$db->insert($table,$row);}
    if(!$keyExisted && is_file($keyFile))unlink($keyFile);
}
