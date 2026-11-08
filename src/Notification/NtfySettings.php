<?php
declare(strict_types=1);
namespace SimpleStore\Notification;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Accounting\MailCredential;

final class NtfySettings
{
    public const DEFAULTS = ['enabled'=>false, 'topic'=>'', 'token_encrypted'=>'', 'priority'=>3, 'include_contact'=>false];
    public const SCHEMA = [
        'CREATE TABLE IF NOT EXISTS shop_ntfy_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY, settings_json LONGTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS shop_ntfy_outbox (
            order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, order_number VARCHAR(40) NOT NULL,
            topic_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            state VARCHAR(16) NOT NULL DEFAULT \'pending\', attempts INT UNSIGNED NOT NULL DEFAULT 0,
            attempted_at DATETIME NULL, next_attempt_at DATETIME NULL, sent_at DATETIME NULL,
            last_error VARCHAR(190) NOT NULL DEFAULT \'\', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ntfy_due (state,next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];
    public function __construct(private MeekroDB $db) {}
    public function installed(): bool
    {
        return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)', 'shop_ntfy_settings','shop_ntfy_outbox') === 2;
    }
    public function install(): void { foreach(self::SCHEMA as $sql) $this->db->query($sql); }
    public function load(): array
    {
        if (!(int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','shop_ntfy_settings')) return self::DEFAULTS;
        $json=$this->db->queryFirstField('SELECT settings_json FROM shop_ntfy_settings WHERE id=1');
        return $json===null ? self::DEFAULTS : array_replace(self::DEFAULTS,json_decode($json,true,32,JSON_THROW_ON_ERROR));
    }
    public static function portable(array $settings): array
    {
        $settings['token']=empty($settings['token_encrypted']) ? '' : MailCredential::decrypt($settings['token_encrypted']);
        unset($settings['token_encrypted']);
        return $settings;
    }
    public static function validate(array $s): void
    {
        if (!is_bool($s['enabled']??null) || !is_bool($s['include_contact']??null) ||
            !is_int($s['priority']??null) || $s['priority']<1 || $s['priority']>5 ||
            !is_string($s['topic']??null) || ($s['topic']!=='' && preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$s['topic'])!==1) ||
            !is_string($s['token']??null) || strlen($s['token'])>512 || preg_match('/[^\x21-\x7e]/',$s['token'])) {
            throw new InvalidArgumentException('Zkontroluj téma, token a prioritu ntfy. Téma smí obsahovat jen písmena bez diakritiky, čísla, pomlčku a podtržítko (nejvýše 64 znaků).');
        }
        if ($s['enabled'] && $s['topic']==='') throw new InvalidArgumentException('Pro zapnutí vyplň vlastní téma ntfy.');
    }
    /** Accept the complete secret topic URL without allowing arbitrary outbound hosts. */
    public static function topicFromAddress(string $address):string
    {
        $address=trim($address);
        if($address==='')return '';
        if(preg_match('~^https://ntfy\.sh/([A-Za-z0-9_-]{1,64})/?$~D',$address,$match)!==1) {
            throw new InvalidArgumentException('Zadej adresu ve tvaru https://ntfy.sh/tvoje-tema, bez parametrů a dalších cest.');
        }
        return $match[1];
    }
    public function save(array $input): void
    {
        if(!array_key_exists('address',$input))throw new InvalidArgumentException('Chybí adresa ntfy. Nastavení nebylo změněno.');
        $current=$this->load();$s=self::portable($current);
        foreach(['address','token'] as $field) if (!is_string($input[$field]??'')) throw new InvalidArgumentException('Neplatné nastavení ntfy.');
        $s['enabled']=($input['enabled']??'')==='1';
        $s['include_contact']=($input['include_contact']??'')==='1';
        $s['topic']=self::topicFromAddress($input['address']??'');
        $s['priority']=filter_var($input['priority']??null,FILTER_VALIDATE_INT);
        $token=trim($input['token']??'');
        if (($input['clear_token']??'')==='1') { if($token!=='')throw new InvalidArgumentException('Pro smazání tokenu nejprve vyprázdni jeho pole.'); $s['token']=''; }
        elseif ($token!=='') $s['token']=$token;
        self::validate($s);
        $s['token_encrypted']=$s['token']==='' ? '' : ($s['token']===self::portable($current)['token'] ? $current['token_encrypted'] : MailCredential::encrypt($s['token']));
        unset($s['token']);
        $this->install();
        $this->db->query('INSERT INTO shop_ntfy_settings (id,settings_json) VALUES (1,%s)
            ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)',json_encode($s,JSON_THROW_ON_ERROR));
    }

    /** Preserve a rejected draft without confusing it with the saved configuration. */
    public static function formValues(array $settings, ?array $draft=null):array
    {
        $values=['address'=>$settings['topic']===''?'':'https://ntfy.sh/'.$settings['topic'],
            'token'=>$settings['token'],'enabled'=>$settings['enabled'],
            'include_contact'=>$settings['include_contact'],'priority'=>(string)$settings['priority'],'clear_token'=>false];
        if($draft===null)return $values;
        foreach(['address','token','priority'] as $key)if(is_string($draft[$key]??null))$values[$key]=$draft[$key];
        foreach(['enabled','include_contact','clear_token'] as $key)$values[$key]=($draft[$key]??'')==='1';
        return $values;
    }
}
