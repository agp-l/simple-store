<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Product\ProductPartnerAssignment;
use SimpleStore\Product\PurchasePartnerRepository;
use Throwable;

final class PriceManager
{
    private $transport;
    private $clock;
    public function __construct(private MeekroDB $db, ?callable $transport = null, ?callable $clock = null)
    {
        $this->transport = $transport ?? [new HttpClient(),'get'];
        $this->clock = $clock ?? static fn():int => time();
    }
    private function now(): int { return ($this->clock)(); }
    private static function json(array $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    public function installed(): bool
    {
        return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN %ls',
            ['shop_supplier_price_settings','shop_supplier_price_jobs','shop_supplier_price_hosts','shop_supplier_price_cache']) === 4;
    }
    public function settings(): array
    {
        $this->db->query('INSERT IGNORE INTO shop_supplier_price_settings (id) VALUES (1)');
        return $this->db->queryFirstRow('SELECT * FROM shop_supplier_price_settings WHERE id=1');
    }
    private function locked(callable $run): mixed
    {
        $name = 'supplier-prices:' . sha1((string)$this->db->queryFirstField('SELECT DATABASE()'));
        if((int)$this->db->queryFirstField('SELECT GET_LOCK(%s,0)',$name)!==1) throw new InvalidArgumentException('Právě probíhá jiný krok. Zkus to za chvíli.');
        try{return $run();}finally{$this->db->query('SELECT RELEASE_LOCK(%s)',$name);}
    }
    public function configure(bool $weekly, bool $paused): void
    {
        $this->locked(function()use($weekly,$paused):void{
            $settings=$this->settings();
            $this->db->update('shop_supplier_price_settings',['weekly_enabled'=>(int)$weekly,'paused'=>(int)$paused,
                'next_weekly'=>$weekly && !(int)$settings['weekly_enabled'] ? $this->now() : (int)$settings['next_weekly']],'id=1');
        });
    }
    public function rotateKey(): string
    {
        return $this->locked(function():string{$this->settings();$key=bin2hex(random_bytes(32));
            $this->db->update('shop_supplier_price_settings',['cron_hash'=>hash('sha256',$key)],'id=1');return $key;});
    }
    public function enqueue(string $scope, string $mode, string $key=''): array
    {
        if(!in_array($scope,['all','missing','one'],true)||!in_array($mode,['normal','fast'],true)||
            ($scope==='one'&&!preg_match('/^[a-f0-9]{32}$/D',$key))) throw new InvalidArgumentException('Neplatný rozsah kontroly.');
        return $this->locked(fn():array=>$this->enqueueInternal($scope,$mode,$key));
    }
    private function enqueueInternal(string $scope,string $mode,string $key=''): array
    {
        $count=['added'=>0,'daily'=>0,'unconfigured'=>0];$now=$this->now();
        $keys=$scope==='one'?[$key]:$this->db->queryFirstColumn('SELECT DISTINCT p.product_key FROM shop_product_revisions p
            LEFT JOIN shop_product_sales s ON s.product_key=p.product_key WHERE p.active_product_key IS NOT NULL'.
            ($scope==='missing'?' AND (s.supplier_price_czk IS NULL OR s.supplier_price_czk=0)':''));
        foreach($keys as $key){
            if($this->db->queryFirstField('SELECT id FROM shop_supplier_price_jobs WHERE product_key=%s AND (created_at>%i OR checked_at>%i OR status=%s) LIMIT 1',$key,$now-86400,$now-86400,'queued')){$count['daily']++;continue;}
            $snapshot=$this->snapshot($key);
            if($snapshot===null||$snapshot['candidates']===[]){$count['unconfigured']++;continue;}
            $data=['snapshot'=>$snapshot,'fingerprint'=>hash('sha256',self::json($snapshot)),'index'=>0,'trace'=>[],'redirect'=>'','hops'=>0];
            $this->db->insert('shop_supplier_price_jobs',['product_key'=>$key,'product_name'=>$snapshot['products'][0]['name'],
                'mode'=>$mode,'created_at'=>$now,'next_at'=>$now,'data_json'=>self::json($data)]);$count['added']++;
        }
        return $count;
    }
    public static function candidates(array $links,array $partners,int $selected): array
    {
        $hosts=[];
        foreach($partners as $partner){$host=ProductPartnerAssignment::host((string)$partner['website_url']);if($host!==null)$hosts[$host][]=$partner;}
        $items=[];$seen=[];
        foreach($links as $link){
            $host=ProductPartnerAssignment::host($link['url']);
            if($host===null||!isset($hosts[$host])||count($hosts[$host])!==1||isset($seen[$link['url']]))continue;
            try{HttpClient::url($link['url']);}catch(InvalidArgumentException $e){continue;}
            $partner=$hosts[$host][0];$seen[$link['url']]=true;
            $items[]=['id'=>(int)$link['id'],'url'=>$link['url'],'partner_id'=>(int)$partner['id'],
                'name'=>$partner['name'],'priority'=>(int)$partner['priority'],'selected'=>(int)$partner['id']===$selected];
        }
        usort($items,static fn(array $a,array $b):int=>[(int)!$a['selected'],$a['priority'],$a['id']]<=>[(int)!$b['selected'],$b['priority'],$b['id']]);
        return $items;
    }
    private function snapshot(string $key,bool $lock=false): ?array
    {
        $suffix=$lock?' FOR UPDATE':'';
        $products=$this->db->query('SELECT id,name,language,slug,price_czk FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL ORDER BY (language=%s) DESC,language'.$suffix,$key,'cs');
        if($products===[])return null;
        $sale=$this->db->queryFirstRow('SELECT * FROM shop_product_sales WHERE product_key=%s'.$suffix,$key);
        $links=$this->db->query('SELECT id,url FROM shop_product_supplier_links WHERE product_key=%s ORDER BY id'.$suffix,$key);
        $partners=$this->db->query('SELECT id,name,website_url,priority,version FROM shop_purchase_partners ORDER BY id'.$suffix);
        $variants=$this->db->query('SELECT variant_key,price_czk,enabled FROM shop_product_variants WHERE product_key=%s ORDER BY variant_key'.$suffix,$key);
        $variantSettings=$this->db->queryFirstRow('SELECT enabled,version FROM shop_product_variant_settings WHERE product_key=%s'.$suffix,$key);
        // Only relevant merchant profiles participate in the optimistic check.
        $candidates=self::candidates($links,$partners,(int)($sale['partner_id']??0));
        $ids=array_column($candidates,'partner_id');$ids[]=(int)($sale['partner_id']??0);
        $partners=array_values(array_filter($partners,static fn(array $p):bool=>in_array((int)$p['id'],$ids,true)));
        return compact('products','sale','links','partners','variants','variantSettings','candidates');
    }
    public function tick(): array
    {
        return $this->locked(function():array{
            $settings=$this->settings();$now=$this->now();
            $this->db->update('shop_supplier_price_settings',['last_tick'=>$now],'id=1');
            if((int)$settings['paused'])return ['state'=>'paused','wait'=>30];
            if((int)$settings['weekly_enabled'] && (int)$settings['next_weekly']<=$now){
                $this->enqueueInternal('all','normal');
                $this->db->update('shop_supplier_price_settings',['next_weekly'=>$now+604800],'id=1');
            }
            // Bounded local work, at most ONE remote request in the entire tick.
            $jobs=$this->db->query('SELECT * FROM shop_supplier_price_jobs WHERE status=%s AND next_at<=%i ORDER BY next_at,id LIMIT 12','queued',$now);
            foreach($jobs as $job){
                $data=json_decode($job['data_json'],true,64,JSON_THROW_ON_ERROR);
                $snapshot=$this->snapshot($job['product_key']);
                if($snapshot===null||!hash_equals($data['fingerprint'],hash('sha256',self::json($snapshot)))){
                    $this->finish($job,'failed','Produkt nebo odkazy se od zařazení změnily. Cena zůstala.');continue;
                }
                $candidate=$data['snapshot']['candidates'][$data['index']]??null;
                if($candidate===null){$this->finish($job,'failed','Žádný odkaz neposkytl použitelnou cenu.');continue;}
                $target=$data['redirect']?:$candidate['url'];
                $robotsUrl='https://'.HttpClient::url($target).'/robots.txt';
                $robots=$this->cached($robotsUrl,'robots',$job['mode'],0);
                if(isset($robots['wait'])){$this->defer($job,$robots['wait']);continue;}
                if(isset($robots['error'])){$this->failure($job,$data,$robots['error']);return ['state'=>'working','wait'=>2];}
                $policy=Robots::policy($robots['body'],$target);
                if(!$policy['allowed']){$this->failure($job,$data,'robots.txt nepovoluje čtení této adresy.');return ['state'=>'working','wait'=>2];}
                if(!empty($robots['fetched'])){$this->defer($job,max($policy['delay'],$job['mode']==='fast'?15:60));return ['state'=>'working','wait'=>2];}
                $result=$this->cached($target,'product',$job['mode'],$policy['delay']);
                if(isset($result['wait'])){$this->defer($job,$result['wait']);continue;}
                if(isset($result['redirect'])){
                    try{if($data['hops']>=3)throw new InvalidArgumentException('Příliš mnoho přesměrování. Oprav odkaz.');
                        $data['redirect']=HttpClient::redirect($target,$result['redirect']);$data['hops']++;
                        $this->saveData($job,$data,'Přesměrování na aktuální adresu.',2);
                    }catch(InvalidArgumentException $e){$this->failure($job,$data,$e->getMessage());}
                    return ['state'=>'working','wait'=>2];
                }
                if(isset($result['error'])){$this->failure($job,$data,$result['error']);return ['state'=>'working','wait'=>2];}
                $result['partner_id']=$candidate['partner_id'];$result['partner_name']=$candidate['name'];$result['source_url']=$target;
                $result['old_price']=$snapshot['sale']['supplier_price_czk']??null;
                $result['fallback']=!$candidate['selected'];$result['observed_at']=$result['observed_at']??$now;
                $reason=self::reviewReason($snapshot,$result);
                $this->db->update('shop_supplier_price_jobs',['status'=>'review','checked_at'=>$now,'result_json'=>self::json($result),
                    'message'=>$reason?:'Jednoznačná nabídka vybraného prodávajícího.'],'id=%i',$job['id']);
                if($reason===''){
                    try{$this->applyInternal((int)$job['id'],false,false);}catch(InvalidArgumentException $e){
                        $this->db->update('shop_supplier_price_jobs',['message'=>$e->getMessage()],'id=%i',$job['id']);
                    }
                }
                return ['state'=>'working','wait'=>2];
            }
            $next=$this->db->queryFirstField('SELECT MIN(next_at) FROM shop_supplier_price_jobs WHERE status=%s','queued');
            return ['state'=>$next===null?'idle':'waiting','wait'=>$next===null?30:max(2,min(60,(int)$next-$now))];
        });
    }
    private function cached(string $url,string $kind,string $mode,int $crawlDelay): array
    {
        $now=$this->now();$hash=hash('sha256',$kind.':'.$url);
        $cache=$this->db->queryFirstRow('SELECT * FROM shop_supplier_price_cache WHERE url_hash=%s AND expires_at>%i',$hash,$now);
        if($cache)return json_decode($cache['data_json'],true,32,JSON_THROW_ON_ERROR);
        $host=ProductPartnerAssignment::host($url);
        $this->db->query('INSERT IGNORE INTO shop_supplier_price_hosts (host) VALUES (%s)',$host);
        $next=(int)$this->db->queryFirstField('SELECT next_at FROM shop_supplier_price_hosts WHERE host=%s',$host);
        if($next>$now)return ['wait'=>$next-$now];
        $delay=max($crawlDelay,$mode==='fast'?15:60);
        // Reservations survive PHP timeouts/crashes; a lost response is never retried in a tight loop.
        $this->db->update('shop_supplier_price_hosts',['next_at'=>$now+$delay],'host=%s',$host);
        $this->db->replace('shop_supplier_price_cache',['url_hash'=>$hash,'expires_at'=>$now+86400,
            'data_json'=>self::json(['error'=>'Předchozí načtení bylo přerušeno. Další pokus nejdříve za 24 hodin.'])]);
        try{
            $response=($this->transport)($url);$code=(int)$response['code'];$headers=$response['headers']??[];
            if(in_array($code,[403,429,503],true)){
                $retry=$headers['retry-after']??'';
                $seconds=ctype_digit((string)$retry)?(int)$retry:max(0,(int)strtotime((string)$retry)-$now);
                $this->db->update('shop_supplier_price_hosts',['next_at'=>$now+max(3600,min(86400,$seconds))],'host=%s',$host);
            }
            if($kind==='robots' && in_array($code,[404,410],true))$result=['body'=>''];
            elseif($code>=300&&$code<400&&$kind==='product'&&isset($headers['location']))$result=['redirect'=>$headers['location']];
            elseif($code!==200)throw new InvalidArgumentException('Dodavatel odpověděl HTTP '.$code.($kind==='robots'?' při ověření robots.txt.':'. Původní cena zůstala.'));
            elseif($kind==='robots'){
                if(strlen($response['body'])>262144||preg_match('/<\s*(?:html|!doctype)/i',$response['body']))throw new InvalidArgumentException('robots.txt nelze spolehlivě přečíst.');
                $result=['body'=>$response['body']];
            }else{$result=PriceParser::read($response['body'],$url);$result['observed_at']=$now;}
        }catch(\Exception $error){$result=['error'=>$error instanceof InvalidArgumentException?$error->getMessage():'Načtení selhalo (spojení, DNS nebo limit stránky). Cena zůstala.'];}
        $this->db->update('shop_supplier_price_cache',['data_json'=>self::json($result)],'url_hash=%s',$hash);
        // Keep cache storage bounded without retaining supplier HTML.
        $this->db->query('DELETE FROM shop_supplier_price_cache WHERE expires_at<%i LIMIT 100',$now-86400);
        return $result+['fetched'=>true];
    }
    private function defer(array $job,int $wait):void{$this->db->update('shop_supplier_price_jobs',['next_at'=>$this->now()+max(2,$wait)],'id=%i',$job['id']);}
    private function saveData(array $job,array $data,string $message,int $wait=2):void
    {$this->db->update('shop_supplier_price_jobs',['data_json'=>self::json($data),'message'=>$message,'next_at'=>$this->now()+$wait],'id=%i',$job['id']);}
    private function failure(array $job,array $data,string $message):void
    {
        $c=$data['snapshot']['candidates'][$data['index']];
        $data['trace'][]=['seller'=>$c['name'],'url'=>$data['redirect']?:$c['url'],'time'=>$this->now(),'message'=>$message];
        $data['index']++;$data['redirect']='';$data['hops']=0;
        $this->saveData($job,$data,$message);
        if(!isset($data['snapshot']['candidates'][$data['index']]))$this->finish($job,'failed',$message);
    }
    private function finish(array $job,string $status,string $message):void
    {$this->db->update('shop_supplier_price_jobs',['status'=>$status,'checked_at'=>$this->now(),'message'=>$message],'id=%i',$job['id']);}
    public static function reviewReason(array $snapshot,array $result):string
    {
        if($result['fallback'])return 'Cena pochází ze záložního obchodu. Zkontroluj nabídku a případně převezmi cenu i prodávajícího.';
        if($result['uncertain'])return 'Odkaz a strukturovaná nabídka se přesně neshodují. Ověř variantu.';
        if(!empty($snapshot['variantSettings']['enabled']))return 'Produkt používá varianty. Ověř, zda cena platí pro varianty se základní dodavatelskou cenou.';
        $old=(int)($snapshot['sale']['supplier_price_czk']??0);
        if($old>0&&abs($result['price']-$old)/$old>0.30)return 'Změna přesahuje 30 %. Před převzetím ověř cenu.';
        if($result['availability']==='out_of_stock')return 'Nabídka není skladem. Před převzetím ověř podmínky dodání.';
        return '';
    }
    public function apply(int $id,bool $variants):void{$this->locked(fn()=>$this->applyInternal($id,true,$variants));}
    private function applyInternal(int $id,bool $manual,bool $variants):void
    {
        $this->db->startTransaction();
        try{
            $job=$this->db->queryFirstRow('SELECT * FROM shop_supplier_price_jobs WHERE id=%i FOR UPDATE',$id);
            if(!$job||$job['status']!=='review')throw new InvalidArgumentException('Nabídka už není připravena k převzetí.');
            $data=json_decode($job['data_json'],true,64,JSON_THROW_ON_ERROR);$result=json_decode($job['result_json'],true,32,JSON_THROW_ON_ERROR);
            $snapshot=$this->snapshot($job['product_key'],true);
            if(!$snapshot||!hash_equals($data['fingerprint'],hash('sha256',self::json($snapshot))))throw new InvalidArgumentException('Produkt se mezitím změnil. Nabídku už nelze bezpečně převzít.');
            if((int)$result['observed_at']<=$this->now()-86400)throw new InvalidArgumentException('Nabídka je starší než 24 hodin. Spusť novou kontrolu.');
            if(!$manual&&self::reviewReason($snapshot,$result)!=='')throw new InvalidArgumentException('Nabídka vyžaduje ruční kontrolu.');
            if(!empty($snapshot['variantSettings']['enabled'])&&!$variants)throw new InvalidArgumentException('Potvrď, že cena platí pro varianty se základní cenou.');
            if($result['fallback']&&!empty(json_decode($snapshot['sale']['variant_prices_json']??'{}',true)))throw new InvalidArgumentException('Produkt má vlastní dodavatelské ceny variant. Změň prodávajícího a tyto ceny společně v detailu produktu.');
            if($result['fallback']){
                $partner=$this->db->queryFirstRow('SELECT * FROM shop_purchase_partners WHERE id=%i FOR UPDATE',$result['partner_id']);
                if(!$partner||PurchasePartnerRepository::missing($partner)!==[])throw new InvalidArgumentException('Záložní prodávající nemá dokončený a potvrzený profil. Nejprve jej doplň.');
            }
            $retail=array_map('intval',array_column($snapshot['products'],'price_czk'));
            if(!empty($snapshot['variantSettings']['enabled']))foreach($snapshot['variants']as$v)if((int)$v['enabled']&&$v['price_czk']!==null)$retail[]=(int)$v['price_czk'];
            if($result['price']>min($retail))throw new InvalidArgumentException('Cena dodavatele je vyšší než prodejní cena. Nejdřív uprav cenotvorbu produktu.');
            $sale=$snapshot['sale'];
            if(!$sale)throw new InvalidArgumentException('Produkt nemá uložené nastavení prodeje. Nejprve ho doplň v detailu.');
            $same=(int)$sale['supplier_price_czk']===$result['price']&&(int)$sale['partner_id']===$result['partner_id'];
            if(!$same)$this->db->update('shop_product_sales',['supplier_price_czk'=>$result['price'],'partner_id'=>$result['partner_id'],'version'=>(int)$sale['version']+1],
                'product_key=%s AND version=%i',$job['product_key'],$sale['version']);
            $this->db->update('shop_supplier_price_jobs',['status'=>$same?'checked':'applied','applied_at'=>$same?0:$this->now(),
                'message'=>$same?'Cena ověřena, zůstává stejná.':($result['fallback']?'Cena i prodávající převzati po kontrole.':'Cena dodavatele aktualizována.')],'id=%i',$id);
            $this->db->commit();
        }catch(Throwable $error){$this->db->rollback();throw $error;}
    }
    public function dismiss(int $id):void
    {$this->locked(function()use($id):void{$this->db->update('shop_supplier_price_jobs',['status'=>'dismissed','message'=>'Nabídka ponechána bez změny ceny.'],'id=%i AND status=%s',$id,'review');});}
    public function dashboard(string $filter='',string $query='',int $page=1):array
    {
        if(!in_array($filter,['','queued','review','failed','applied','checked','dismissed'],true))$filter='';
        $where='1=1';$args=[];
        if($filter!==''){$where.=' AND status=%s';$args[]=$filter;}
        if($query!==''){$where.=' AND product_name LIKE %ss';$args[]=$query;}
        $total=(int)$this->db->queryFirstField('SELECT COUNT(*) FROM shop_supplier_price_jobs WHERE '.$where,...$args);
        $page=max(1,min($page,max(1,(int)ceil($total/30))));
        $rows=$this->db->query('SELECT * FROM shop_supplier_price_jobs WHERE '.$where.' ORDER BY id DESC LIMIT 30 OFFSET %i',...array_merge($args,[($page-1)*30]));
        $counts=['queued'=>0,'review'=>0,'failed'=>0,'applied'=>0,'checked'=>0];
        foreach($this->db->query('SELECT status,COUNT(*) AS n FROM shop_supplier_price_jobs GROUP BY status')as$row)$counts[$row['status']]=(int)$row['n'];
        $products=$this->db->query('SELECT p.product_key,MIN(p.name) AS name,MAX(s.supplier_price_czk) AS price FROM shop_product_revisions p
            LEFT JOIN shop_product_sales s ON s.product_key=p.product_key WHERE p.active_product_key IS NOT NULL GROUP BY p.product_key ORDER BY name');
        return compact('rows','counts','products','total','page','filter','query');
    }
}
