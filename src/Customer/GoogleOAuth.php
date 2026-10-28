<?php
declare(strict_types=1);
namespace SimpleStore\Customer;
use InvalidArgumentException;
use RuntimeException;
use SimpleStore\Accounting\MailCredential;

/** Authorization code flow only. Tokens are accepted exclusively from Google's TLS token endpoint. */
final class GoogleOAuth
{
    private $transport;
    public function __construct(private array $settings, ?callable $transport=null) { $this->transport=$transport; }
    public static function binding(?array $user): string
    {
        return $user===null ? '' : hash('sha256', $user['id'].':'.$user['password_hash'].':'.($user['auth_version']??0));
    }
    public function begin(array &$session, ?array $user, bool $checkout): string
    {
        if (!GoogleLoginSettings::ready($this->settings)) throw new InvalidArgumentException('Přihlášení přes Google zatím není zapnuté.');
        $state=bin2hex(random_bytes(32)); $nonce=bin2hex(random_bytes(32)); $verifier=bin2hex(random_bytes(32));
        $session['google_oauth']=['state'=>$state,'nonce'=>$nonce,'verifier'=>$verifier,'expires'=>time()+600,'user_id'=>$user===null?null:(int)$user['id'],'binding'=>self::binding($user),'checkout'=>$checkout,'config'=>hash('sha256',json_encode($this->settings))];
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'=>$this->settings['client_id'],'redirect_uri'=>$this->settings['redirect_uri'],'response_type'=>'code',
            'scope'=>'openid email profile','state'=>$state,'nonce'=>$nonce,'prompt'=>'select_account',
            'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256',
        ],'','&',PHP_QUERY_RFC3986);
    }
    public function finish(array &$session, array $query, ?array $user): array
    {
        $pending=$session['google_oauth']??null; unset($session['google_oauth']);
        if (!GoogleLoginSettings::ready($this->settings) || !is_array($pending) || !is_string($query['state']??null) || !hash_equals($pending['state'],$query['state']) || $pending['expires']<time() || !hash_equals($pending['binding'],self::binding($user)) || !hash_equals($pending['config'],hash('sha256',json_encode($this->settings)))) throw new InvalidArgumentException('Přihlášení vypršelo nebo se změnil účet. Zkus to znovu.');
        if (isset($query['error'])) throw new InvalidArgumentException('Přihlášení přes Google bylo zrušeno.');
        if (!is_string($query['code']??null) || strlen($query['code'])>4096 || $query['code']==='') throw new InvalidArgumentException('Google nevrátil platné přihlášení.');
        $body=['code'=>$query['code'],'client_id'=>$this->settings['client_id'],'client_secret'=>MailCredential::decrypt($this->settings['client_secret_encrypted']), 'redirect_uri'=>$this->settings['redirect_uri'],'grant_type'=>'authorization_code','code_verifier'=>$pending['verifier']];
        $tokens=$this->request($body);
        // OIDC core 3.1.3.7: direct token-endpoint TLS authentication is used instead of JWS validation.
        // Never expose a path that accepts browser-supplied id_tokens or a configurable token endpoint.
        $jwt=$tokens['id_token']??null;
        if(!is_string($jwt) || count($parts=explode('.',$jwt))!==3) throw new RuntimeException('Google token response is invalid.');
        $raw=base64_decode(strtr($parts[1],'-_','+/'),true);
        $claims=$raw===false ? null : json_decode($raw,true,16);
        self::validateClaims($claims,$this->settings['client_id'],$pending['nonce']);
        return ['claims'=>$claims,'target_id'=>$pending['user_id'],'binding'=>$pending['binding'],'checkout'=>$pending['checkout']];
    }
    public static function validateClaims(mixed $c,string $aud,string $nonce): void
    {
        if(!is_array($c) || !in_array($c['iss']??'', ['https://accounts.google.com','accounts.google.com'],true) || ($c['aud']??null)!==$aud || (isset($c['azp']) && $c['azp']!==$aud) || !is_int($c['exp']??null) || $c['exp']<=time() || !is_int($c['iat']??null) || $c['iat']>time()+60 || !is_string($c['nonce']??null) || !hash_equals($nonce,$c['nonce']) || !is_string($c['sub']??null) || !preg_match('/^[a-zA-Z0-9_-]{1,255}$/D',$c['sub']) || ($c['email_verified']??false)!==true || !is_string($c['email']??null) || strlen($c['email'])>254 || !filter_var($c['email'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Identitu od Googlu se nepodařilo ověřit.');
    }
    private function request(array $body): array
    {
        if($this->transport!==null) return ($this->transport)($body);
        $response=''; $curl=curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body,'','&',PHP_QUERY_RFC3986),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>65536)return 0;$response.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($ok===false || $status!==200) throw new RuntimeException('Google token exchange failed.');
        $decoded=json_decode($response,true,16);
        if(!is_array($decoded))throw new RuntimeException('Google token response is invalid.');
        return $decoded;
    }
}
