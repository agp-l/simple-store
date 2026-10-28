<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Customer\{GoogleIdentityRepository,GoogleOAuth,CustomerRepository};
use SimpleStore\Database\ConnectionFactory;
$db=ConnectionFactory::create(['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306]);
$r=new GoogleIdentityRepository($db);$customers=new CustomerRepository($db);$ids=[];$suffix=bin2hex(random_bytes(8));
$check=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};
$reject=static function(callable $f):void{try{$f();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe identity operation accepted.');};
$claims=['sub'=>'google_'.$suffix,'email'=>'google_'.$suffix.'@example.org','name'=>'Google customer'];
try {
 $ids[]=$id=$r->resolve($claims,null,true);$user=$customers->byId($id);
 $check($user['password_hash']==='' && $user['role']==='customer','Google-only password/role');
 $check($r->resolve($claims,null,false)===$id,'Existing Google login must not require registration enabled');
 $reject(fn()=>$r->unlink($id,''));
 $reject(fn()=>$r->resolve(['sub'=>'other_'.$suffix,'email'=>$claims['email']],null,true));
 $reject(fn()=>$r->resolve(['sub'=>'disabled_'.$suffix,'email'=>'disabled_'.$claims['email']],null,false));
 $r->firstPassword($id,'good-password-123');$user=$customers->byId($id);$check(password_verify('good-password-123',$user['password_hash']),'First password not usable');
 $reject(fn()=>$r->firstPassword($id,'overwrite-password-123'));
 $reject(fn()=>$r->unlink($id,'wrong'));
 $version=$user['auth_version'];$r->unlink($id,'good-password-123');$check($r->linked($id)===null && $customers->byId($id)['auth_version']>$version,'Unlink must revoke prior sessions');
 $reject(fn()=>$r->resolve($claims,null,true)); // same email is not permission to reconnect
 $user=$customers->byId($id);$check($r->resolve($claims,$id,true,GoogleOAuth::binding($user))===$id,'Explicit linking failed');
 $reject(fn()=>$r->resolve($claims,$id,true,'stale'));
 $customers->register('second_'.$suffix.'@example.org','second-password-123','Second');$second=$customers->byEmail('second_'.$suffix.'@example.org');$ids[]=$secondId=(int)$second['id'];
 $reject(fn()=>$r->resolve($claims,$secondId,true,GoogleOAuth::binding($second)));
 $db->query('UPDATE shop_users SET is_active=0 WHERE id=%i',$id);$reject(fn()=>$r->resolve($claims,null,true));
 $db->query('UPDATE shop_users SET is_active=1,role=%s WHERE id=%i','admin',$id);$reject(fn()=>$r->resolve($claims,null,true));
 $recoveryClaims=['sub'=>'recovery_'.$suffix,'email'=>'recovery_'.$suffix.'@example.org'];
 $ids[]=$recoveryId=$r->resolve($recoveryClaims,null,true);$token=bin2hex(random_bytes(32));
 $db->query('INSERT INTO shop_password_resets (token_hash,user_id,password_hash_at_issue,role,created_at,expires_at) VALUES (%s,%i,%s,%s,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))',hash('sha256',$token),$recoveryId,hash('sha256',''),'customer');
 (new \SimpleStore\Auth\PasswordResetService($db))->complete('customer',$token,'recovered-password-123','recovered-password-123');
 $check(password_verify('recovered-password-123',$customers->byId($recoveryId)['password_hash']),'Google-only email recovery failed');
 $reject(fn()=>(new \SimpleStore\Auth\PasswordResetService($db))->complete('customer',$token,'replayed-password-123','replayed-password-123'));
 echo "Google identities: registration, no email auto-link, collision, password, unlink, revocation and roles passed.\n";
} finally { foreach($ids as $id)$db->query('DELETE FROM shop_users WHERE id=%i',$id); }
