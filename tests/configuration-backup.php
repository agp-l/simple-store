<?php
declare(strict_types=1);
class MeekroDB
{
    public array $copy = [];
    public array $settings = ['smtp_password_encrypted'=>'private-password','smtp_username'=>'private-user','automatic_enabled'=>false];
    public function queryFirstField(string $sql,mixed ...$args): int { return 1; }
    public function queryFirstRow(string $sql,mixed ...$args): ?array {
        if(str_contains($sql,'shop_site_copy'))return ['copy_json'=>json_encode($this->copy)];
        if(str_contains($sql,'shop_mail_settings'))return ['settings_json'=>json_encode($this->settings)];
        return null;
    }
    public function query(string $sql,mixed ...$args): array { return []; }
}
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Admin\ConfigurationBackup as B;
$db=new MeekroDB();$service=new B($db,['cs','en'],require dirname(__DIR__).'/config/menus.php');
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $run):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid backup accepted.');};
foreach(B::KINDS as$kind){$backup=$service->snapshot($kind);$decoded=$service->decode(B::json($backup),$kind);$plan=$service->plan($decoded);$assert($plan['counts']['new']===0&&$plan['counts']['changed']===0,'Round trip changes saved settings.');$assert(!str_contains(B::json($backup),'private-'),'Export leaked credentials.');}
$mail=$service->snapshot('email-texts');
$mail['data']['copy']['footer']='Vlastní <b>text</b>';
$plan=$service->plan($service->decode(B::json($mail),'email-texts'));
$assert($plan['counts']['changed']===1,'Text import changes unrelated fields.');
$token=B::token($mail,$plan['version'],'csrf');
$assert(!hash_equals($token,B::token($mail,$plan['version'],'another-csrf')),'Preview must be bound to its administrator.');
$mail['data']['smtp_password']='injected';$reject(fn()=>$service->decode(B::json($mail),'email-texts'));unset($mail['data']['smtp_password']);
$mail['data']['templates']['order']['message']='Unknown {made_up}';$reject(fn()=>$service->decode(B::json($mail),'email-texts'));
$mail=$service->snapshot('email-texts');$mail['data']['copy']['partner_prices']='Hidden amounts';$reject(fn()=>$service->decode(B::json($mail),'email-texts'));
$reject(fn()=>$service->decode('{bad','navigation'));
$reject(fn()=>$service->decode(str_repeat('x',B::LIMIT+1),'navigation'));
$reject(fn()=>$service->decode(B::json($service->snapshot('site-texts')),'navigation'));
$site=$service->snapshot('site-texts');$site['data']['de']=$site['data']['cs'];$reject(fn()=>$service->decode(B::json($site),'site-texts'));
$site=$service->snapshot('site-texts');$site['data']=['cs'=>['blog_title'=>'Náš nový blog','blog_note'=>'Na cestu!']];
$plan=$service->plan($service->decode(B::json($site),'site-texts'));
$assert($plan['counts']['changed']===2,'Blog copy import must change only the selected texts.');
$db->copy=['blog_title'=>'Saved custom blog'];
$site=$service->snapshot('site-texts');$site['data']=['cs'=>['hero_title'=>'Old backup header']];
$decoded=$service->decode(B::json($site),'site-texts');
$plan=$service->plan($decoded);
$assert($plan['counts']['changed']===1,'Old partial backup must preserve custom blog copy.');
$db->copy=[];
$nav=$service->snapshot('navigation');
$nav['data']['cs']['menus']['utility']['blog_order']=25;
$decoded=$service->decode(B::json($nav),'navigation');
$assert($decoded['data']['cs']['menus']['utility']['blog_order']===25&&$service->plan($decoded)['counts']['changed']===1,'Blog order must be included in navigation import and preview.');
foreach([-1,65536,'20',null]as$invalidOrder){$invalid=$nav;$invalid['data']['cs']['menus']['utility']['blog_order']=$invalidOrder;$reject(fn()=>$service->decode(B::json($invalid),'navigation'));}
unset($nav['data']['cs']['menus']['utility']['blog_order']);
$assert($service->decode(B::json($nav),'navigation')['data']['cs']['menus']['utility']['blog_order']===0,'Legacy backups must accept missing Blog order.');
$nav=$service->snapshot('navigation');
$nav['data']['cs']['categories']=['spani/spacaky'=>['title'=>'Spacáky','sort_order'=>10,'enabled'=>true]];
$reject(fn()=>$service->plan($service->decode(B::json($nav),'navigation')));
$nav['data']['cs']['categories']['spani']=['title'=>'Spaní','sort_order'=>10,'enabled'=>true];
$plan=$service->plan($service->decode(B::json($nav),'navigation'));$assert($plan['counts']['new']===2,'Category paths lost from the plan.');
$nav['data']['cs']['menus']['primary']['items']=[['id'=>str_repeat('a',16),'parent_id'=>str_repeat('b',16),'label'=>'A','target_type'=>'path','target'=>'blog','sort_order'=>10],['id'=>str_repeat('b',16),'parent_id'=>str_repeat('a',16),'label'=>'B','target_type'=>'path','target'=>'blog','sort_order'=>20]];
$reject(fn()=>$service->decode(B::json($nav),'navigation'));
$nav['data']['cs']['menus']['primary']['items'][0]['parent_id']='';$nav['data']['cs']['menus']['primary']['items'][1]['parent_id']='';
$nav['data']['cs']['menus']['primary']['items'][1]['id']=str_repeat('a',16);$reject(fn()=>$service->decode(B::json($nav),'navigation'));
$nav['data']['cs']['menus']['primary']['items']=[];
$nav['data']['cs']['menus']['primary']['parent']='missing';$reject(fn()=>$service->plan($service->decode(B::json($nav),'navigation')));
$nav['data']['cs']['menus']['primary']['parent']='';
$nav['data']['cs']['menus']['primary']['items']=[['id'=>str_repeat('a',16),'parent_id'=>'','label'=>'XSS','target_type'=>'external','target'=>'javascript:alert(1)','sort_order'=>10]];
$reject(fn()=>$service->decode(B::json($nav),'navigation'));
$nav['data']['cs']['menus']['primary']['items']=[];$nav['data']['cs']['pages']['missing-page']=['visible'=>true,'sort_order'=>10];$reject(fn()=>$service->plan($service->decode(B::json($nav),'navigation')));
echo "Configuration backups: round trip, scope, secrets, references, cycles and validation OK\n";
