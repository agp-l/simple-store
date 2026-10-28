<?php
declare(strict_types=1);
namespace SimpleStore\Customer;
use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Accounting\MailCredential;

final class GoogleLoginSettings
{
    public const DEFAULTS = ['enabled'=>false,'client_id'=>'','client_secret_encrypted'=>'','redirect_uri'=>''];
    public function __construct(private MeekroDB $db) {}
    public function installed(): bool
    {
        return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)', 'shop_google_login_settings','shop_customer_google') === 2;
    }
    public function load(): array
    {
        if (!$this->installed()) return self::DEFAULTS;
        $json=$this->db->queryFirstField('SELECT settings_json FROM shop_google_login_settings WHERE id=1');
        $saved=$json===null ? [] : json_decode($json,true,16);
        if(!is_array($saved)) return self::DEFAULTS;
        foreach(self::DEFAULTS as $key=>$default) if(isset($saved[$key]) && gettype($saved[$key])!==gettype($default)) return self::DEFAULTS;
        return array_replace(self::DEFAULTS,array_intersect_key($saved,self::DEFAULTS));
    }
    public function save(array $input): void
    {
        if (!$this->installed()) throw new InvalidArgumentException('Nejdřív aktualizuj databázi.');
        $old=$this->load();
        foreach(['client_id','client_secret','redirect_uri'] as $key) if(!is_string($input[$key]??null)) throw new InvalidArgumentException('Neplatné nastavení Google.');
        $id=trim($input['client_id']); $uri=trim($input['redirect_uri']); $secret=trim($input['client_secret']);
        $enabled=($input['enabled']??'')==='1';
        if ($id!=='' && !preg_match('/^[a-zA-Z0-9-]+\.apps\.googleusercontent\.com$/D',$id)) throw new InvalidArgumentException('Zkontroluj Google Client ID.');
        $parts=parse_url($uri);
        if ($uri!=='' && (!filter_var($uri,FILTER_VALIDATE_URL) || ($parts['scheme']??'')!=='https' || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['fragment']) || ($parts['query']??'')!=='google=callback' || !str_ends_with($parts['path']??'','/account/'))) throw new InvalidArgumentException('Návratová adresa musí být HTTPS adresa tohoto obchodu zakončená /account/?google=callback.');
        if(strlen($id)>200 || strlen($secret)>512 || preg_match('/[\x00-\x20\x7f]/',$secret) || strlen($uri)>1000) throw new InvalidArgumentException('Neplatné údaje Google.');
        $encrypted=$secret!=='' ? MailCredential::encrypt($secret) : $old['client_secret_encrypted'];
        if($id!==$old['client_id'] && $secret==='') $encrypted='';
        if($enabled && ($id==='' || $encrypted==='' || $uri==='')) throw new InvalidArgumentException('Pro zapnutí vyplň Client ID, Client secret a návratovou adresu.');
        $this->db->query('INSERT INTO shop_google_login_settings (id,settings_json) VALUES (1,%s) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)',json_encode(['enabled'=>$enabled,'client_id'=>$id,'client_secret_encrypted'=>$encrypted,'redirect_uri'=>$uri],JSON_THROW_ON_ERROR));
    }
    public static function ready(array $settings): bool
    {
        return $settings['enabled']===true && $settings['client_id']!=='' && $settings['client_secret_encrypted']!=='' && $settings['redirect_uri']!=='';
    }
}
