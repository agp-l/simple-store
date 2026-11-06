<?php
declare(strict_types=1);
namespace SimpleStore\Notification;

/** Fixed HTTPS origin, no redirects; never return server bodies or credentials in errors. */
final class NtfyClient
{
    public function send(array $payload,string $token):array
    {
        if(strlen($token)>512 || preg_match('/[^\x21-\x7e]/',$token))throw new \InvalidArgumentException('Neplatný token ntfy.');
        if(!function_exists('curl_init'))return ['state'=>'failed','error'=>'Na serveru chybí PHP cURL.'];
        $headers=['Content-Type: application/json; charset=utf-8'];
        if($token!=='')$headers[]='Authorization: Bearer '.$token;
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $curl=curl_init('https://ntfy.sh/');$response='';
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{
                if(strlen($response)+strlen($chunk)>16384)return 0;$response.=$chunk;return strlen($chunk);
            }]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        return self::result($ok!==false,$status,$response);
    }
    public static function result(bool $transportOk,int $status,string $response):array
    {
        if(!$transportOk)return ['state'=>'uncertain','error'=>'Spojení skončilo bez potvrzení. Před opakováním zkontroluj telefon.'];
        if($status>=200 && $status<300){
            $data=json_decode($response,true);
            if(is_array($data) && ($data['event']??'')==='message' && is_string($data['id']??null) && $data['id']!=='')return ['state'=>'sent','error'=>''];
            return ['state'=>'uncertain','error'=>'ntfy nevrátilo potvrzení zprávy. Před opakováním zkontroluj telefon.'];
        }
        return ['state'=>'failed','error'=>match($status){401,403=>'ntfy odmítlo přístup. Zkontroluj token a oprávnění tématu.',429=>'ntfy omezilo počet zpráv. Zkus to později.',default=>'ntfy zprávu odmítlo (HTTP '.$status.').'}];
    }
}
