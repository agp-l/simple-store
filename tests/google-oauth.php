<?php
declare(strict_types=1);
namespace SimpleStore\Accounting { class MailCredential { public static function decrypt(string $value):string{return 'test-secret';} } }
namespace {
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Customer\{GoogleOAuth,GoogleLoginSettings};
function check(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function reject(callable $f):void{try{$f();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe OAuth input accepted');}
$settings=['enabled'=>true,'client_id'=>'123.apps.googleusercontent.com','client_secret_encrypted'=>'encrypted','redirect_uri'=>'https://shop.example/account/?google=callback'];
$calls=0;$nonce='';
$claims=['sub'=>'123456','iss'=>'https://accounts.google.com','aud'=>$settings['client_id'],'iat'=>time(),'exp'=>time()+300,'email'=>'test@example.org','email_verified'=>true];
$transport=function($body)use(&$calls,&$nonce,$claims,$settings){$calls++;check($body['redirect_uri']===$settings['redirect_uri'] && strlen($body['code_verifier'])===64,'Token request mismatch');return ['id_token'=>'header.'.rtrim(strtr(base64_encode(json_encode($claims+['nonce'=>$nonce])),'+/','-_'),'=').'.signature'];};
$oauth=new GoogleOAuth($settings,$transport);$session=[];
$url=$oauth->begin($session,null,true);parse_str(parse_url($url,PHP_URL_QUERY),$q);$nonce=$q['nonce'];
check($q['scope']==='openid email profile' && $q['code_challenge_method']==='S256','Scopes/PKCE');
check($q['code_challenge']===rtrim(strtr(base64_encode(hash('sha256',$session['google_oauth']['verifier'],true)),'+/','-_'),'='),'PKCE challenge mismatch');
$result=$oauth->finish($session,['code'=>'code','state'=>$q['state']],null);
check($result['claims']['sub']==='123456' && $result['checkout'] && !isset($session['google_oauth']),'Result/state consumption');
reject(fn()=>$oauth->finish($session,['code'=>'code','state'=>$q['state']],null));
foreach(['state','expiry','binding','config','cancel'] as $case){
 $oauth->begin($session,null,false);$query=['code'=>'code','state'=>$session['google_oauth']['state']];$user=null;
 if($case==='state')$query['state']='forged';if($case==='expiry')$session['google_oauth']['expires']=time()-1;
 if($case==='binding')$user=['id'=>1,'password_hash'=>'hash'];if($case==='config')$session['google_oauth']['config']='changed';if($case==='cancel')$query['error']='access_denied';
 reject(fn()=>$oauth->finish($session,$query,$user));
}
check($calls===1,'Invalid callback contacted token endpoint');
$valid=$claims+['nonce'=>'n'];GoogleOAuth::validateClaims($valid,$settings['client_id'],'n');
foreach(['aud'=>'other','azp'=>'other','iss'=>'https://evil.example','exp'=>time()-1,'iat'=>time()+100,'nonce'=>'wrong','email_verified'=>false,'sub'=>[],'email'=>'invalid'] as $key=>$value){$bad=$valid;$bad[$key]=$value;reject(fn()=>GoogleOAuth::validateClaims($bad,$settings['client_id'],'n'));}
$off=new GoogleOAuth(GoogleLoginSettings::DEFAULTS,$transport);reject(fn()=>$off->begin($session,null,false));
echo "Google OAuth: state, nonce, PKCE, expiry, audience, session binding and replay checks passed.\n";
}
