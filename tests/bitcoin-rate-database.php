<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater,OperationalSchema};
use SimpleStore\Pricing\BitcoinRateRepository;
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);$database='simple_store_btc_'.bin2hex(random_bytes(5));$admin->query('CREATE DATABASE %b',$database);
$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
try{
 (new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();OperationalSchema::install($db);OperationalSchema::install($db);
 $now=time();$calls=0;$code=200;$retry='';$throw=false;
 $transport=static function()use(&$calls,&$now,&$code,&$retry,&$throw):array{$calls++;if($throw)throw new RuntimeException('Timeout');return ['code'=>$code,'retry_after'=>$retry,'body'=>json_encode(['bitcoin'=>['czk'=>2000000,'last_updated_at'=>$now]])];};
 $repo=new BitcoinRateRepository($db,$transport,static function()use(&$now):int{return $now;});
 for($i=0;$i<100;$i++)$check($repo->current()['rate']===2000000.0,'Cold/shared quote missing.');
 $check($calls===1,'Repeated page views called the feed.');$now+=899;$repo->current();$check($calls===1,'Fresh cache expired early.');
 $other=ConnectionFactory::create(array_replace($config,['database'=>$database]));$lock='btc-czk:'.sha1($database);$other->query('SELECT GET_LOCK(%s,0)',$lock);
 $now++;$repo->current();$check($calls===1,'Parallel reader bypassed shared lock.');$other->query('SELECT RELEASE_LOCK(%s)',$lock);$other->disconnect();
 $repo->current();$check($calls===2,'Expired quote did not refresh.');
 $now+=900;$code=429;$retry='7200';$repo->current();$check($calls===3,'429 fixture not called.');
 $check((int)$db->queryFirstField('SELECT next_attempt_at FROM shop_btc_rate_refresh WHERE id=1')===$now+7200,'Retry-After not respected.');
 $now+=7199;for($i=0;$i<20;$i++)$check($repo->current()===null,'Expired quote remained visible.');$check($calls===3,'Blocked feed polled before deadline.');
 $now++;$throw=true;$repo->current();$check($calls===4,'Transport failure fixture not reached.');
 for($i=0;$i<20;$i++)$repo->current();$check($calls===4,'Thrown transport exception caused repeated calls.');
 $now+=86401;$throw=false;$code=200;$repo->current();$check($calls===5&&$repo->current()!==null,'Feed did not recover.');
 $now+=900;$db->update('shop_btc_rate_refresh',['next_attempt_at'=>$now+900],'id=1');$repo->current();$check($calls===5,'Crash reservation ignored.');
 $check(BitcoinRateRepository::retryDelay(1,429,gmdate('D, d M Y H:i:s \G\M\T',$now+9000),$now)===9000,'HTTP date cooldown ignored.');
 echo "BTC shared cache, concurrent readers, crash reservation, backoff and recovery passed.\n";
}finally{$db->disconnect();$admin->query('DROP DATABASE %b',$database);$admin->disconnect();}
