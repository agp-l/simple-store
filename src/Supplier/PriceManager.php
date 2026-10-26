<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Product\ProductPartnerAssignment;
use SimpleStore\Product\PurchasePartnerRepository;
use SimpleStore\Product\ProductVariantSupplierRepository;
use Throwable;

final class PriceManager
{
    public const EVALUATOR_VERSION = 10;
    private $transport;
    private $clock;
    private ?bool $variantModesInstalled=null;
    private ProductVariantSupplierRepository $variantLinks;
    public function __construct(private MeekroDB $db, ?callable $transport = null, ?callable $clock = null)
    {
        $this->variantLinks = new ProductVariantSupplierRepository($db);
        $this->transport = $transport ?? [new HttpClient(),'get'];
        $this->clock = $clock ?? static fn():int => time();
    }
    private function now(): int { return ($this->clock)(); }
    private static function json(array $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    public function installed(): bool
    {
        return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN %ls',
            ['shop_supplier_price_settings','shop_supplier_price_jobs','shop_supplier_price_hosts','shop_supplier_price_cache']) === 4 &&
            (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND
                ((TABLE_NAME=%s AND COLUMN_NAME=%s) OR (TABLE_NAME=%s AND COLUMN_NAME=%s))',
                'shop_supplier_price_settings','options_json','shop_supplier_price_jobs','retry_of')===2;
    }
    public function settings(): array
    {
        $this->db->query('INSERT IGNORE INTO shop_supplier_price_settings (id) VALUES (1)');
        $row=$this->db->queryFirstRow('SELECT * FROM shop_supplier_price_settings WHERE id=1');
        return $row + (json_decode($row['options_json']??'null',true)??[]) + PricingPolicy::DEFAULTS;
    }
    public function configurePricing(array $input): void
    {
        $options=PricingPolicy::settings($input);
        $this->locked(function()use($options):void{$settings=$this->settings();
            $stored=json_decode($settings['options_json']??'null',true)??[];
            $this->db->update('shop_supplier_price_settings',['options_json'=>self::json(array_replace($stored,$options))],'id=1');});
    }
    public function configureVariantLinks(bool $enabled): void
    {
        $this->locked(function()use($enabled):void{
            $settings=$this->settings();$options=json_decode($settings['options_json']??'null',true)??[];
            $options['variant_links_enabled']=$enabled;
            $options['variant_links_cursor']='';$options['variant_links_next_at']=0;
            $this->db->update('shop_supplier_price_settings',['options_json'=>self::json($options)],'id=1');
        });
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
        if(!in_array($scope,['all','missing','one'],true)||!in_array($mode,['normal','fast','rapid'],true)||
            ($scope==='one'&&!preg_match('/^[a-f0-9]{32}$/D',$key))) throw new InvalidArgumentException('Neplatný rozsah kontroly.');
        return $this->locked(fn():array=>$this->enqueueInternal($scope,$mode,$key));
    }
    /** Manual, bounded exception for unresolved results; successful checks retain the 24h limit. */
    public function retry(string $scope,string $mode,bool $fresh,int $id=0):array
    {
        if(!in_array($scope,['review','failed','problems','one'],true)||!in_array($mode,['normal','fast','rapid'],true))throw new InvalidArgumentException('Neplatný rozsah opakování.');
        return $this->locked(fn():array=>$this->retryInternal($scope,$mode,$fresh,$id));
    }
    private function retryInternal(string $scope,string $mode,bool $fresh,int $id=0):array
    {
            $now=$this->now();$count=['added'=>0,'daily'=>0,'unconfigured'=>0];
            $rows=$this->db->query('SELECT j.* FROM shop_supplier_price_jobs j INNER JOIN
                (SELECT product_key,MAX(id) AS id FROM shop_supplier_price_jobs GROUP BY product_key) latest ON latest.id=j.id
                WHERE '.($scope==='one'?'j.product_key=(SELECT product_key FROM shop_supplier_price_jobs WHERE id=%i)':'j.status IN %ls'),
                $scope==='one'?$id:($scope==='review'?['review']:($scope==='failed'?['failed']:['review','failed'])));
            foreach($rows as$job){
                if($scope==='one'&&(int)$job['id']!==$id)throw new InvalidArgumentException('Tento historický pokus už má novější kontrolu. Obnov výsledky produktu.');
                if($job['status']==='queued'){$count['daily']++;continue;}
                $snapshot=$this->snapshot($job['product_key']);
                $problem=self::configurationIssue($snapshot);
                if($problem!==''){
                    if($scope==='one')$count['reason']=$problem;
                    $count['unconfigured']++;continue;
                }
                $previous=json_decode($job['data_json'],true);
                $changed=self::sourceIdentity($snapshot)!==self::sourceIdentity($previous['snapshot']??[]);
                if(!in_array($job['status'],['review','failed'],true)&&!$changed)throw new InvalidArgumentException('Výsledek je již dokončený. Pro běžnou kontrolu použij zařazení produktu do fronty.');
                $lastFresh=(int)$this->db->queryFirstField('SELECT MAX(GREATEST(created_at,checked_at)) FROM shop_supplier_price_jobs WHERE product_key=%s AND retry_of>0',$job['product_key']);
                if($fresh&&!$changed&&$lastFresh>$now-86400){$count['daily']++;continue;}
                // A new source is a normal cache-respecting check, not a forced re-fetch of the old seller.
                $force=$fresh&&!$changed;
                $data=['snapshot'=>$snapshot,'fingerprint'=>hash('sha256',self::json($snapshot)),'index'=>0,'trace'=>[],'redirect'=>'','hops'=>0,
                    'retry_of'=>(int)$job['id'],'refresh_after'=>$force?$now:0,'cache_only'=>!$fresh&&!$changed,'evaluator_version'=>self::EVALUATOR_VERSION];
                $next=$force?max($now,(int)$job['checked_at']+900):$now;
                $this->db->insert('shop_supplier_price_jobs',['product_key'=>$job['product_key'],'product_name'=>$snapshot['products'][0]['name'],
                    'mode'=>$mode,'created_at'=>$now,'next_at'=>$next,'retry_of'=>$force?(int)$job['id']:0,
                    'message'=>$changed?'Partner nebo odkazy se změnily. Ověříme aktuální nabídku s běžnými limity načítání.':($fresh?'Opakování vyžádáno. Respektuje prodlevy obchodu a nejméně 15 minut od poslední kontroly.':'Nové vyhodnocení uložené nabídky bez dotazu na dodavatele.'),
                    'data_json'=>self::json($data)]);
                $this->db->update('shop_supplier_price_jobs',['status'=>'retried','message'=>$job['message'].' · Zařazeno k novému ověření.'],'id=%i',$job['id']);
                $count['added']++;
            }
            return $count;
    }

    private static function sourceIdentity(array $snapshot): string
    {
        $sources=[];
        foreach($snapshot['candidates']??[]as$c)$sources[]=array_intersect_key($c,array_flip(['kind','url','partner_id','selected','links']));
        return self::json(['mode'=>$snapshot['sale']['mode']??'own','partner_id'=>(int)($snapshot['sale']['partner_id']??0),'sources'=>$sources]);
    }

    private static function configurationIssue(?array $snapshot): string
    {
        if($snapshot===null)return 'Produkt už neexistuje nebo byl sloučen. Otevři jeho aktuální kartu.';
        if(($snapshot['sale']['mode']??'own')!=='assisted')return 'Produkt je v režimu Prodáváme my. Partnerské ceny se ověřují pouze u asistovaného nákupu.';
        if($snapshot['candidates']!==[])return '';
        return 'Chybí použitelný partnerský odkaz. V editoru produktu ověř HTTPS adresu a web v profilu prodávajícího; u variant zkontroluj jejich aktivní odkazy.';
    }
    public function queueMode(string $mode):int
    {
        if(!in_array($mode,['normal','fast','rapid'],true))throw new InvalidArgumentException('Neplatné tempo.');
        return $this->locked(function()use($mode):int{
            $count=(int)$this->db->queryFirstField('SELECT COUNT(*) FROM shop_supplier_price_jobs WHERE status=%s','queued');
            // Do not shorten reservations, server backoffs, daily limits or scheduled retries.
            $this->db->update('shop_supplier_price_jobs',['mode'=>$mode],'status=%s','queued');return $count;
        });
    }
    public static function delay(string $mode,int $crawlDelay=0):int
    {
        $base=max($crawlDelay,match($mode){'rapid'=>1,'fast'=>15,default=>60});
        return random_int($base,(int)ceil($base*1.5));
    }
    private function enqueueInternal(string $scope,string $mode,string $key=''): array
    {
        $count=['added'=>0,'daily'=>0,'unconfigured'=>0];$now=$this->now();
        $keys=$scope==='one'?[$key]:$this->db->queryFirstColumn('SELECT DISTINCT p.product_key FROM shop_product_revisions p
            INNER JOIN shop_product_sales s ON s.product_key=p.product_key AND s.mode=\'assisted\' WHERE p.active_product_key IS NOT NULL'.
            ($scope==='missing'?' AND (s.supplier_price_czk IS NULL OR s.supplier_price_czk=0)':''));
        foreach($keys as $key){
            if($this->db->queryFirstField('SELECT id FROM shop_supplier_price_jobs WHERE product_key=%s AND (created_at>%i OR checked_at>%i OR status=%s) LIMIT 1',$key,$now-86400,$now-86400,'queued')){$count['daily']++;continue;}
            $snapshot=$this->snapshot($key);
            if($snapshot===null||($snapshot['sale']['mode']??'own')!=='assisted'||$snapshot['candidates']===[]){$count['unconfigured']++;continue;}
            $data=['snapshot'=>$snapshot,'fingerprint'=>hash('sha256',self::json($snapshot)),'index'=>0,'trace'=>[],'redirect'=>'','hops'=>0,'evaluator_version'=>self::EVALUATOR_VERSION];
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
        if($this->db->queryFirstField('SELECT target_key FROM shop_product_redirects WHERE language=%s AND source_slug=%s AND target_key<>%s LIMIT 1',$products[0]['language'],$products[0]['slug'],$key)!==null)return null;
        $sale=$this->db->queryFirstRow('SELECT * FROM shop_product_sales WHERE product_key=%s'.$suffix,$key);
        $links=$this->db->query('SELECT id,url FROM shop_product_supplier_links WHERE product_key=%s ORDER BY id'.$suffix,$key);
        $partners=$this->db->query('SELECT id,partner_key,name,website_url,priority,version FROM shop_purchase_partners ORDER BY id'.$suffix);
        $variants=$this->db->query('SELECT variant_key,price_czk,enabled,choices_json FROM shop_product_variants WHERE product_key=%s ORDER BY variant_key'.$suffix,$key);
        $variantLinks=$this->variantLinks->identitiesForProduct($key,$lock);
        $variantSettings=$this->db->queryFirstRow('SELECT enabled,version FROM shop_product_variant_settings WHERE product_key=%s'.$suffix,$key);
        // Only relevant merchant profiles participate in the optimistic check.
        $candidates=VariantOfferPlan::candidates(compact('variants','variantSettings','variantLinks','partners','sale'))??
            self::candidates($links,$partners,(int)($sale['partner_id']??0));
        $ids=array_column($candidates,'partner_id');$ids[]=(int)($sale['partner_id']??0);
        $partners=array_values(array_filter($partners,static fn(array $p):bool=>in_array((int)$p['id'],$ids,true)));
        $this->variantModesInstalled??=(int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','shop_product_supplier_variant_modes')===1;
        $variantPriceMode=$this->variantModesInstalled?$this->db->queryFirstRow('SELECT * FROM shop_product_supplier_variant_modes WHERE product_key=%s'.$suffix,$key):null;
        return compact('products','sale','links','partners','variants','variantSettings','candidates','variantPriceMode','variantLinks');
    }
    public function tick(string $origin='browser'): array
    {
        if(!in_array($origin,['browser','cron','cli'],true))throw new InvalidArgumentException('Neplatný zdroj workeru.');
        return $this->locked(function()use($origin):array{
            $settings=$this->settings();$now=$this->now();
            $options=json_decode($settings['options_json']??'null',true)??[];
            $options['last_'.$origin.'_tick']=$now;
            // Once per evaluator release, retry unresolved current products from cache only.
            // Incomplete obsolete documents may schedule one guarded maintenance refresh.
            if(!(int)$settings['paused']&&(int)($options['rechecked_version']??0)<self::EVALUATOR_VERSION){
                // Revisit parser migrations deferred by an older evaluator. This only
                // wakes local work; normal URL cache, robots and host reservations still apply.
                foreach($this->db->query('SELECT id,data_json FROM shop_supplier_price_jobs WHERE status=%s','queued')as$pending){
                    $pendingData=json_decode($pending['data_json'],true);
                    if(empty($pendingData['parser_refresh'])||(int)$pendingData['parser_refresh']>=PriceParser::VERSION)continue;
                    $pendingData['parser_refresh']=0;$pendingData['cache_only']=true;$pendingData['refresh_after']=0;
                    $this->db->update('shop_supplier_price_jobs',['data_json'=>self::json($pendingData),'next_at'=>$now],'id=%i',$pending['id']);
                }
                $this->retryInternal('problems','rapid',false);
                $options['rechecked_version']=self::EVALUATOR_VERSION;
            }
            $this->db->update('shop_supplier_price_settings',['last_tick'=>$now,'options_json'=>self::json($options)],'id=1');
            if((int)$settings['paused'])return ['state'=>'paused','wait'=>30];
            $variantLinksMore=false;
            if(!empty($options['variant_links_enabled'])&&(int)($options['variant_links_next_at']??0)<=$now){
                try{
                    $batch=(new VariantLinkDiscovery($this->db,$now))->batch((string)($options['variant_links_cursor']??''));
                    $variantLinksMore=$batch['more'];$options['variant_links_cursor']=$batch['cursor'];
                    $options['variant_links_next_at']=$batch['more']?0:$now+300;
                    $options['variant_links_last_tick']=$now;$options['variant_links_error']='';
                    if(!$batch['more'])$options['variant_links_last_scan']=$now;
                }catch(Throwable $e){
                    // Isolate discovery from the existing price queue; retry a failed batch later.
                    $options['variant_links_next_at']=$now+300;
                    $options['variant_links_error']='Párování odkazů se nezdařilo. Automatika zopakuje dávku za 5 minut.';
                    error_log('Variant link discovery: '.get_class($e));
                }
                $this->db->update('shop_supplier_price_settings',['options_json'=>self::json($options)],'id=1');
            }
            if((int)$settings['weekly_enabled'] && (int)$settings['next_weekly']<=$now){
                $this->enqueueInternal('all','normal');
                $this->db->update('shop_supplier_price_settings',['next_weekly'=>$now+604800],'id=1');
            }
            // Bounded local work, at most ONE remote request in the entire tick.
            $jobs=$this->db->query('SELECT * FROM shop_supplier_price_jobs WHERE status=%s AND next_at<=%i ORDER BY next_at,id LIMIT 12','queued',$now);
            for($cursor=0;$cursor<min(count($jobs),24);$cursor++){
                $job=$jobs[$cursor];$wait=$job['mode']==='rapid'?0.25:2;
                $data=json_decode($job['data_json'],true,64,JSON_THROW_ON_ERROR);
                $snapshot=$this->snapshot($job['product_key']);
                if($snapshot!==null&&($snapshot['sale']['mode']??'own')!=='assisted'){
                    $this->finish($job,'dismissed','Produkt je v režimu Prodáváme my. Partnerská kontrola byla ukončena bez změny cen.');continue;
                }
                if($snapshot===null||!hash_equals($data['fingerprint'],hash('sha256',self::json($snapshot)))){
                    if($snapshot!==null&&$snapshot['candidates']!==[]&&($data['rebases']??0)<2){
                        if(self::sourceIdentity($snapshot)!==self::sourceIdentity($data['snapshot'])){$data['cache_only']=false;$data['refresh_after']=0;}
                        $data['snapshot']=$snapshot;$data['fingerprint']=hash('sha256',self::json($snapshot));
                        $data['variant_observations']=[];$data['variant_issues']=[];
                        $data['index']=0;$data['redirect']='';$data['hops']=0;$data['rebases']=($data['rebases']??0)+1;
                        $this->saveData($job,$data,'Produkt se změnil; kontrola automaticky pokračuje s aktuálními údaji.');continue;
                    }
                    $this->finish($job,'failed','Produkt nebo odkazy se opakovaně změnily. Spusť kontrolu po dokončení úprav.');continue;
                }
                $candidate=$data['snapshot']['candidates'][$data['index']]??null;
                if($candidate===null){$this->finish($job,'failed','Žádný odkaz neposkytl použitelnou cenu.');continue;}
                $target=$data['redirect']?:$candidate['url'];
                $robotsUrl='https://'.HttpClient::url($target).'/robots.txt';
                $robots=$this->cached($robotsUrl,'robots',$job['mode'],0,0,!empty($data['cache_only']));
                if(isset($robots['wait'])){$this->defer($job,$robots['wait']);continue;}
                if(isset($robots['error'])){$this->failure($job,$data,$robots['error'],!empty($robots['temporary']));
                    if(empty($robots['fetched']))continue;return ['state'=>'working','wait'=>$wait];}
                $policy=Robots::policy($robots['body'],$target);
                $requestPolicy=Robots::policy($robots['body'],PriceParser::requestUrl($target));
                $policy=['allowed'=>$policy['allowed']&&$requestPolicy['allowed'],'delay'=>max($policy['delay'],$requestPolicy['delay'])];
                if(!$policy['allowed']){$this->failure($job,$data,'robots.txt nepovoluje čtení této adresy.');
                    if(empty($robots['fetched']))continue;return ['state'=>'working','wait'=>$wait];}
                if(!empty($robots['fetched'])){$this->defer($job,self::delay($job['mode'],$policy['delay']));return ['state'=>'working','wait'=>1];}
                $refreshAfter=max((int)($data['refresh_after']??0),(int)($data['parser_refresh_after'][HttpClient::cacheKey('product',$target)]??0));
                $result=(($candidate['kind']??'')==='variant'?$this->reusableVariantDocument($snapshot,$candidate,$target,$refreshAfter):null)??
                    $this->cached($target,'product',$job['mode'],$policy['delay'],$refreshAfter,!empty($data['cache_only']));
                if(isset($result['wait'])){$this->defer($job,$result['wait']);continue;}
                if(isset($result['redirect'])){
                    try{if($data['hops']>=3)throw new InvalidArgumentException('Příliš mnoho přesměrování. Oprav odkaz.');
                        $data['redirect']=HttpClient::redirect($target,$result['redirect']);$data['hops']++;
                        $data['permanent_redirects']=($data['permanent_redirects']??($data['hops']===1))&&in_array($result['http_code']??0,[301,308],true);
                        $this->saveData($job,$data,'Přesměrování na aktuální adresu.',$job['mode']==='rapid'?1:2);
                        if(empty($result['fetched'])){
                            // Cached redirects do not consume the request budget. Follow them locally,
                            // bounded by both the hop limit and the 24 local steps per tick.
                            $job['data_json']=self::json($data);array_splice($jobs,$cursor+1,0,[$job]);continue;
                        }
                    }catch(InvalidArgumentException $e){$this->failure($job,$data,$e->getMessage());}
                    if(empty($result['fetched']))continue;return ['state'=>'working','wait'=>$wait];
                }
                if(isset($result['error'])){$this->failure($job,$data,$result['error'],!empty($result['temporary']));
                    if(empty($result['fetched']))continue;return ['state'=>'working','wait'=>$wait];}
                $remote=!empty($result['fetched']);
                $legacy=!empty($result['document']['offers'])&&(int)($result['document']['parser_version']??0)<PriceParser::VERSION;
                $cachedResult=$result;
                if(($candidate['kind']??'')==='variant'){
                    $resolved=$candidate; if($data['hops']>0)$resolved['resolved_url']=$target;
                    $observed=VariantOfferEvaluation::observe($result['document'],$result['document_source']??$target,$snapshot,$resolved,(int)$result['observed_at']);
                    if($legacy&&$observed['issues']!==[]&&$this->scheduleParserRefresh($job,$data,$cachedResult,$target))continue;
                    $data['variant_observations']=array_replace($data['variant_observations']??[],$observed['observations']);
                    $data['variant_issues']=array_merge($data['variant_issues']??[],$observed['issues']);
                    $this->advanceVariant($job,$data,$observed['issues'][0]['message']??'Přesné nabídky variant načteny.');
                    if(!$remote)continue;return ['state'=>'working','wait'=>$wait];
                }
                if(isset($result['document'])){
                    try{$result=PriceEvaluation::select($result['document'],$target,$snapshot,$this->variantModesInstalled?$settings:[])+['observed_at'=>$result['observed_at']];}
                    catch(InvalidArgumentException $e){
                        if($legacy&&$this->scheduleParserRefresh($job,$data,$cachedResult,$target))continue;
                        $this->failure($job,$data,$e->getMessage());if(!$remote)continue;return ['state'=>'working','wait'=>$wait];
                    }
                }
                if(!isset($result['corrected_url'])&&$data['hops']>0&&!empty($data['permanent_redirects'])&&!$result['uncertain']&&
                    PriceParser::sameProductName($result,$snapshot['products'][0]['name']))$result['corrected_url']=$target;
                $result['partner_id']=$candidate['partner_id'];$result['partner_name']=$candidate['name'];$result['source_url']=$result['corrected_url']??$target;
                $result['old_price']=$snapshot['sale']['supplier_price_czk']??null;
                $result['fallback']=!$candidate['selected'];$result['observed_at']=$result['observed_at']??$now;
                $reason=self::reviewReason($snapshot,$result);
                if($reason!==''&&$legacy&&($result['uncertain']||
                    (!empty($snapshot['variantSettings']['enabled'])&&empty($result['uniform_variants'])&&empty($result['variant_offers'])))&&
                    $this->scheduleParserRefresh($job,$data,$cachedResult,$target))continue;
                $this->db->update('shop_supplier_price_jobs',['status'=>'review','checked_at'=>$now,'result_json'=>self::json($result),
                    'message'=>$reason?:'Jednoznačná nabídka vybraného prodávajícího.'],'id=%i',$job['id']);
                if($reason===''){
                    try{$this->applyInternal((int)$job['id'],false,false);}catch(InvalidArgumentException $e){
                        $this->db->update('shop_supplier_price_jobs',['message'=>$e->getMessage()],'id=%i',$job['id']);
                    }
                }
                if(!$remote)continue;
                return ['state'=>'working','wait'=>$wait];
            }
            $next=$this->db->queryFirstField('SELECT MIN(next_at) FROM shop_supplier_price_jobs WHERE status=%s','queued');
            if($variantLinksMore)return ['state'=>'working','wait'=>1];
            return ['state'=>$next===null?'idle':'waiting','wait'=>$next===null?30:max(1,min(60,(int)$next-$now)),'next_at'=>$next===null?0:(int)$next];
        });
    }
    /** Reuse a fresh model document only when it proves every requested variant URL.
     * Never replace a response already cached for the exact target (including a denial).
     */
    private function reusableVariantDocument(array $snapshot,array $candidate,string $target,int $refreshAfter=0):?array
    {
        if($this->db->queryFirstField('SELECT url_hash FROM shop_supplier_price_cache WHERE url_hash=%s AND expires_at>%i',HttpClient::cacheKey('product',$target),$this->now())!==null)return null;
        $urls=array_unique(array_merge(array_column($snapshot['links'],'url'),array_column($snapshot['candidates'],'url')));
        foreach($urls as $url){
            if(ProductPartnerAssignment::host($url)!==ProductPartnerAssignment::host($target))continue;
            for($hop=0;$hop<4;$hop++){
                $row=$this->db->queryFirstRow('SELECT data_json FROM shop_supplier_price_cache WHERE url_hash=%s AND expires_at>%i',HttpClient::cacheKey('product',$url),$this->now());
                if(!$row)break;$cached=json_decode($row['data_json'],true,48,JSON_THROW_ON_ERROR);
                if(!empty($cached['redirect'])){
                    try{$url=HttpClient::redirect($url,$cached['redirect']);}catch(InvalidArgumentException $e){break;}
                    continue;
                }
                $observed=(int)($cached['observed_at']??0);
                if(empty($cached['document'])||$observed<=$this->now()-86400||$observed>$this->now()||$observed<$refreshAfter)break;
                $proof=VariantOfferEvaluation::observe($cached['document'],$url,$snapshot,$candidate,$observed);
                if($proof['issues']===[]&&count($proof['observations'])===count($candidate['links']))return $cached+['document_source'=>$url];
                break;
            }
        }
        return null;
    }
    private function cached(string $url,string $kind,string $mode,int $crawlDelay,int $refreshAfter=0,bool $cacheOnly=false): array
    {
        $now=$this->now();$hash=HttpClient::cacheKey($kind,$url);
        $cache=$this->db->queryFirstRow('SELECT * FROM shop_supplier_price_cache WHERE url_hash=%s AND expires_at>%i',$hash,$now);
        if($cache){
            $cached=json_decode($cache['data_json'],true,48,JSON_THROW_ON_ERROR);
            if($kind==='robots')$cached=Robots::cachedResponse($cached);
            $fetched=(int)($cached['fetched_at']??((int)$cache['expires_at']-86400));
            if($cacheOnly||$refreshAfter===0||$fetched>=$refreshAfter||in_array($cached['http_code']??0,[403,429,503],true))return $cached;
        }
        if($cacheOnly)return ['error'=>'Uložená nabídka chybí nebo vypršela. Vyžádej nové načtení stránky.'];
        $host=ProductPartnerAssignment::host($url);
        $this->db->query('INSERT IGNORE INTO shop_supplier_price_hosts (host) VALUES (%s)',$host);
        $next=(int)$this->db->queryFirstField('SELECT next_at FROM shop_supplier_price_hosts WHERE host=%s',$host);
        if($next>$now)return ['wait'=>$next-$now];
        $delay=self::delay($mode,$crawlDelay);
        // Reservations survive PHP timeouts/crashes; a lost response is never retried in a tight loop.
        $this->db->update('shop_supplier_price_hosts',['next_at'=>$now+$delay],'host=%s',$host);
        $this->db->replace('shop_supplier_price_cache',['url_hash'=>$hash,'expires_at'=>$now+86400,
            'data_json'=>self::json(['error'=>'Předchozí načtení bylo přerušeno. Další pokus nejdříve za 24 hodin.'])]);
        try{
            $requestUrl=$kind==='product'?PriceParser::requestUrl($url):$url;
            $response=($this->transport)($requestUrl);$code=(int)$response['code'];$headers=$response['headers']??[];
            if(in_array($code,[403,429,503],true)&&!($kind==='robots'&&Robots::unavailable($code)&&empty($headers['retry-after']))){
                $retry=$headers['retry-after']??'';
                $seconds=ctype_digit((string)$retry)?(int)$retry:max(0,(int)strtotime((string)$retry)-$now);
                $this->db->update('shop_supplier_price_hosts',['next_at'=>$now+max(3600,min(86400,$seconds))],'host=%s',$host);
            }
            if($kind==='robots' && Robots::unavailable($code))$result=['body'=>'','unavailable'=>true];
            elseif($code>=300&&$code<400&&$kind==='product'&&isset($headers['location']))$result=['redirect'=>$headers['location']];
            elseif($code!==200)throw new InvalidArgumentException('Dodavatel odpověděl HTTP '.$code.($kind==='robots'?' při ověření robots.txt.':'. Původní cena zůstala.'));
            elseif($kind==='robots'){
                if(strlen($response['body'])>262144||preg_match('/<\s*(?:html|!doctype)/i',$response['body']))throw new InvalidArgumentException('robots.txt nelze spolehlivě přečíst.');
                $result=['body'=>$response['body']];
            }else{$result=['document'=>PriceParser::document($response['body'],HttpClient::withoutFragment($url)),'observed_at'=>$now];}
        }catch(\Exception $error){$result=['error'=>$error instanceof InvalidArgumentException?$error->getMessage():'Načtení selhalo (spojení, DNS nebo limit stránky). Cena zůstala.',
            'temporary'=>!isset($code)||in_array($code,[408,429,500,502,503,504],true)];}
        $result['fetched_at']=$now;$result['http_code']=$code??0;$result['request_url']=$requestUrl??$url;
        $this->db->update('shop_supplier_price_cache',['data_json'=>self::json($result)],'url_hash=%s',$hash);
        // Keep cache storage bounded without retaining supplier HTML.
        $this->db->query('DELETE FROM shop_supplier_price_cache WHERE expires_at<%i LIMIT 100',$now-86400);
        return $result+['fetched'=>true];
    }
    private function defer(array $job,int $wait):void{$this->db->update('shop_supplier_price_jobs',['next_at'=>$this->now()+max(1,$wait)],'id=%i',$job['id']);}
    private function scheduleParserRefresh(array $job,array $data,array $cached,string $target):bool
    {
        $key=HttpClient::cacheKey('product',$target);
        if(isset($data['parser_refresh_after'][$key]))return false;
        if(empty($cached['document'])||(int)($cached['document']['parser_version']??0)>=PriceParser::VERSION)return false;
        // One maintenance refresh per obsolete document, separate from ordinary daily
        // checks. A successful request replaces it with the current parser; a failed
        // request replaces it with an error, so neither can trigger a refresh loop.
        $due=max($this->now(),(int)($cached['fetched_at']??$cached['observed_at']??$this->now())+900);
        $data['parser_refresh']=PriceParser::VERSION;$data['cache_only']=false;
        $data['parser_refresh_after'][$key]=$due;
        $this->db->update('shop_supplier_price_jobs',['data_json'=>self::json($data),'next_at'=>$due,
            'retry_of'=>(int)($data['retry_of']??$job['id']),
            'message'=>'Po aktualizaci načítání jednorázově obnovíme neúplná starší data variant. Prodlevy a omezení partnera zůstávají v platnosti.'],'id=%i',$job['id']);
        return true;
    }
    private function saveData(array $job,array $data,string $message,int $wait=2):void
    {$this->db->update('shop_supplier_price_jobs',['data_json'=>self::json($data),'message'=>$message,'next_at'=>$this->now()+$wait],'id=%i',$job['id']);}
    /** Persist each fetched source; commit prices only after the whole merchant has been evaluated. */
    private function advanceVariant(array $job,array $data,string $message,bool $temporary=false):void
    {
        $snapshot=$data['snapshot'];$candidate=$snapshot['candidates'][$data['index']];
        $data['trace'][]=['seller'=>$candidate['name'],'url'=>$data['redirect']?:$candidate['url'],'time'=>$this->now(),'message'=>$message];
        $data['temporary']=!empty($data['temporary'])||$temporary;
        $next=$snapshot['candidates'][$data['index']+1]??null;
        if($next===null||(int)$next['partner_id']!==(int)$candidate['partner_id']){
            try{
                $result=VariantOfferEvaluation::assemble($snapshot,$data['variant_observations']??[],(int)$candidate['partner_id'],$this->now(),$this->variantModesInstalled?$this->settings():[]);
                $result['partner_id']=$candidate['partner_id'];$result['partner_name']=$candidate['name'];
                $result['source_url']=$result['url'];$result['fallback']=!$candidate['selected'];
                $result['old_price']=$snapshot['sale']['supplier_price_czk']??null;
                $reason=self::reviewReason($snapshot,$result);
                $this->db->update('shop_supplier_price_jobs',['status'=>'review','checked_at'=>$this->now(),
                    'data_json'=>self::json($data),'result_json'=>self::json($result),
                    'message'=>$reason?:'Přesná nabídka ověřena pro každou aktivní variantu.'],'id=%i',$job['id']);
                if($reason==='')try{$this->applyInternal((int)$job['id'],false,false);}catch(InvalidArgumentException $e){
                    $this->db->update('shop_supplier_price_jobs',['message'=>$e->getMessage()],'id=%i',$job['id']);
                }
                return;
            }catch(InvalidArgumentException $e){
                $message=$e->getMessage();
                $data['trace'][]=['seller'=>$candidate['name'],'url'=>$candidate['url'],'time'=>$this->now(),'message'=>$message];
            }
        }
        $data['index']++;$data['redirect']='';$data['hops']=0;$data['permanent_redirects']=true;
        $this->saveData($job,$data,$message);
        if($next!==null)return;
        if(!empty($data['temporary'])&&empty($data['automatic_retry'])&&empty($data['cache_only'])){
            $data['automatic_retry']=1;$data['index']=0;$data['temporary']=false;$data['variant_observations']=[];$data['variant_issues']=[];
            $this->saveData($job,$data,'Dočasné selhání. Jeden automatický pokus je naplánovaný za 24 hodin.',86400);
            $this->db->update('shop_supplier_price_jobs',['checked_at'=>$this->now()],'id=%i',$job['id']);
        }else $this->finish($job,'failed',$message);
    }
    private function failure(array $job,array $data,string $message,bool $temporary=false):void
    {
        $c=$data['snapshot']['candidates'][$data['index']];
        if(($c['kind']??'')==='variant'){$this->advanceVariant($job,$data,$message,$temporary);return;}
        $data['trace'][]=['seller'=>$c['name'],'url'=>$data['redirect']?:$c['url'],'time'=>$this->now(),'message'=>$message];
        $data['temporary']=!empty($data['temporary'])||$temporary;
        $data['index']++;$data['redirect']='';$data['hops']=0;$data['permanent_redirects']=true;
        $this->saveData($job,$data,$message);
        if(!isset($data['snapshot']['candidates'][$data['index']])){
            if(!empty($data['temporary'])&&empty($data['automatic_retry'])&&empty($data['cache_only'])){
                $data['automatic_retry']=1;$data['index']=0;$data['temporary']=false;$data['variant_observations']=[];$data['variant_issues']=[];
                $this->saveData($job,$data,'Dočasné selhání. Jeden automatický pokus je naplánovaný za 24 hodin.',86400);
                $this->db->update('shop_supplier_price_jobs',['checked_at'=>$this->now()],'id=%i',$job['id']);
            }else $this->finish($job,'failed',$message);
        }
    }
    private function finish(array $job,string $status,string $message):void
    {$this->db->update('shop_supplier_price_jobs',['status'=>$status,'checked_at'=>$this->now(),'message'=>$message],'id=%i',$job['id']);}
    public static function reviewReason(array $snapshot,array $result):string
    {
        if($result['fallback'])return 'Cena pochází ze záložního obchodu. Zkontroluj nabídku a případně převezmi cenu i prodávajícího.';
        if($result['uncertain'])return 'Odkaz a strukturovaná nabídka se přesně neshodují. Ověř variantu.';
        if(!empty($snapshot['variantSettings']['enabled'])&&empty($result['uniform_variants'])&&empty($result['variant_offers']))return 'Produkt používá varianty. Ověř, zda cena platí pro varianty se základní dodavatelskou cenou.';
        $old=(int)($snapshot['sale']['supplier_price_czk']??0);
        if($old>0&&abs($result['price']-$old)/$old>0.30)return 'Změna přesahuje 30 %. Před převzetím ověř cenu.';
        // Availability is independent of a valid public price; it never changes local inventory.
        return '';
    }
    public function apply(int $id,bool $variants):void{$this->locked(fn()=>$this->applyInternal($id,true,$variants));}
    /** Read-only price diagnostic: shared 24h URL cache/host reservations, at most one HTTP request.
     * Does not enqueue, approve, or modify any product, price, stock or existing job.
     */
    public function probe(int $id):array
    {
        return $this->locked(function()use($id):array{
            if(!empty($this->settings()['paused']))throw new InvalidArgumentException('Zpracování je pozastavené.');
            $key=$this->db->queryFirstField('SELECT product_key FROM shop_supplier_price_jobs WHERE id=%i',$id);
            $snapshot=$key?$this->snapshot($key):null;$candidate=$snapshot['candidates'][0]??null;
            $problem=self::configurationIssue($snapshot);if($problem!=='')throw new InvalidArgumentException($problem);
            $url=$candidate['url'];$label=$candidate['name'].': ';
            $reply=static fn(?bool $ok,string $message):array=>['ok'=>$ok,'partner'=>$candidate['name'],'url'=>$url,'message'=>$label.$message.' Ceny produktu se při diagnostice nemění.'];
            for($hop=0;$hop<4;$hop++){
                $robots=$this->cached('https://'.HttpClient::url($url).'/robots.txt','robots','normal',0);
                if(isset($robots['wait']))return $reply(null,'Další požadavek je povolený za '.$robots['wait'].' s.');
                if(isset($robots['error']))return $reply(false,$robots['error']);
                $policy=Robots::policy($robots['body'],$url);$requestPolicy=Robots::policy($robots['body'],PriceParser::requestUrl($url));
                if(!$policy['allowed']||!$requestPolicy['allowed'])return $reply(false,'robots.txt nepovoluje čtení této adresy.');
                if(!empty($robots['fetched']))return $reply(null,'Pravidla načítání ověřena. Zopakuj diagnostiku po prodlevě obchodu (nejméně 60 s).');
                $result=$this->cached($url,'product','normal',max($policy['delay'],$requestPolicy['delay']));
                if(isset($result['wait']))return $reply(null,'Další požadavek je povolený za '.$result['wait'].' s.');
                if(isset($result['error']))return $reply(false,$result['error']);
                if(!empty($result['redirect'])){
                    $url=HttpClient::redirect($url,$result['redirect']);
                    if(!empty($result['fetched']))return $reply(null,'Stránka se přesměrovala. Zopakuj diagnostiku po prodlevě obchodu (nejméně 60 s).');
                    continue;
                }
                try{
                    if(($candidate['kind']??'')==='variant'){
                        $proof=VariantOfferEvaluation::observe($result['document'],$url,$snapshot,$candidate,(int)$result['observed_at']);
                        if($proof['issues']!==[])return $reply(false,$proof['issues'][0]['message']);
                        $prices=array_column($proof['observations'],'price');
                        if($prices===[])return $reply(false,'Stránka nepotvrdila přesnou nabídku varianty.');
                        return $reply(true,'Přesně ověřených variant na této adrese: '.count($prices).'. Cena '.min($prices).(min($prices)!==max($prices)?'–'.max($prices):'').' Kč.')+['prices'=>$prices];
                    }
                    $offer=PriceEvaluation::select($result['document'],$url,$snapshot,$this->settings());
                    $offer['fallback']=!$candidate['selected'];
                    $reason=self::reviewReason($snapshot,$offer);
                    return $reply(!$offer['uncertain'],'Načtená cena '.$offer['price'].' Kč. '.($reason!==''?'Před převzetím: '.$reason:'Nabídka je jednoznačná.'))+
                        ['price'=>$offer['price'],'availability'=>$offer['availability'],'identity_evidence'=>$offer['identity_evidence'],'review_reason'=>$reason];
                }catch(InvalidArgumentException $e){return $reply(false,$e->getMessage());}
            }
            return $reply(false,'Příliš mnoho přesměrování. Oprav partnerský odkaz.');
        });
    }
    private function applyInternal(int $id,bool $manual,bool $variants):void
    {
        $this->db->startTransaction();
        try{
            $job=$this->db->queryFirstRow('SELECT * FROM shop_supplier_price_jobs WHERE id=%i FOR UPDATE',$id);
            if(!$job||$job['status']!=='review')throw new InvalidArgumentException('Nabídka už není připravena k převzetí.');
            $data=json_decode($job['data_json'],true,64,JSON_THROW_ON_ERROR);$result=json_decode($job['result_json'],true,32,JSON_THROW_ON_ERROR);
            $snapshot=$this->snapshot($job['product_key'],true);
            if($snapshot!==null&&($snapshot['sale']['mode']??'own')!=='assisted')throw new InvalidArgumentException('Partnerskou cenu lze převzít pouze v režimu Asistovaný nákup.');
            if(!$snapshot||!hash_equals($data['fingerprint'],hash('sha256',self::json($snapshot))))throw new InvalidArgumentException('Produkt se mezitím změnil. Nabídku už nelze bezpečně převzít.');
            if((int)$result['observed_at']<=$this->now()-86400)throw new InvalidArgumentException('Nabídka je starší než 24 hodin. Spusť novou kontrolu.');
            if(!$manual&&self::reviewReason($snapshot,$result)!=='')throw new InvalidArgumentException('Nabídka vyžaduje ruční kontrolu.');
            if(!empty($snapshot['variantSettings']['enabled'])&&!$variants&&empty($result['uniform_variants'])&&empty($result['variant_offers']))throw new InvalidArgumentException('Potvrď, že cena platí pro varianty se základní cenou.');
            if($result['fallback']&&!empty(json_decode($snapshot['sale']['variant_prices_json']??'{}',true)))throw new InvalidArgumentException('Produkt má vlastní dodavatelské ceny variant. Změň prodávajícího a tyto ceny společně v detailu produktu.');
            if($result['fallback']){
                $partner=$this->db->queryFirstRow('SELECT * FROM shop_purchase_partners WHERE id=%i FOR UPDATE',$result['partner_id']);
                if(!$partner||PurchasePartnerRepository::missing($partner)!==[])throw new InvalidArgumentException('Záložní prodávající nemá dokončený a potvrzený profil. Nejprve jej doplň.');
            }
            $pricing=$this->settings();
            if(($snapshot['candidates'][0]['kind']??'')==='variant'){
                $verified=VariantOfferEvaluation::assemble($snapshot,$data['variant_observations']??[],(int)$result['partner_id'],$this->now(),$pricing);
                if(($result['canonical_variant_offers']??null)!==$verified['canonical_variant_offers'] || $result['price']!==$verified['price'])
                    throw new InvalidArgumentException('Chybí úplný důkaz přesných nabídek variant. Spusť novou kontrolu.');
            }
            $enableVariantPricing=!empty($result['enable_variant_pricing'])&&!$result['fallback']&&$this->variantModesInstalled&&PriceEvaluation::mayEnableVariantPricing($snapshot,$pricing);
            $newRetail=(int)$pricing['pricing_enabled'] ? PricingPolicy::price($result['price'],$pricing) : null;
            $retail=$newRetail===null?array_map('intval',array_column($snapshot['products'],'price_czk')):[$newRetail];
            if($result['price']>min($retail))throw new InvalidArgumentException('Cena dodavatele je vyšší než prodejní cena. Zapni cenotvorbu nebo uprav cenu produktu.');
            $sale=$snapshot['sale'];
            if(!$sale)throw new InvalidArgumentException('Produkt nemá uložené nastavení prodeje. Nejprve ho doplň v detailu.');
            $overrides=json_decode($sale['variant_prices_json']??'{}',true)??[];
            $variantPrices=[];$oldOverrides=$overrides;
            if(!empty($result['variant_offers'])){
                if((empty($snapshot['variantPriceMode']['enabled'])&&!$enableVariantPricing)||$result['fallback'])throw new InvalidArgumentException('Automatická cenotvorba variant není pro tento produkt povolená.');
                foreach($snapshot['variants']as$v){
                    if(!(int)$v['enabled'])continue;
                    $offer=$result['variant_offers'][$v['variant_key']]??null;
                    if(!$offer)throw new InvalidArgumentException('Chybí přesná cena některé aktivní varianty.');
                    $old=(int)($overrides[$v['variant_key']]??$sale['supplier_price_czk']??0);
                    if(!$manual&&$old>0&&abs($offer['price']-$old)/$old>0.30)throw new InvalidArgumentException('Cena některé varianty se změnila o více než 30 %. Ověř nabídku.');
                    $overrides[$v['variant_key']]=(int)$offer['price'];
                    if($newRetail!==null)$variantPrices[$v['variant_key']]=PricingPolicy::price((int)$offer['price'],$pricing);
                }
            }
            if(!empty($snapshot['variantSettings']['enabled']))foreach($snapshot['variants']as$v){
                if(!(int)$v['enabled'])continue;
                $cost=(int)($overrides[$v['variant_key']]??$result['price']);
                $selling=$variantPrices[$v['variant_key']]??($v['price_czk']===null?min($retail):(int)$v['price_czk']);
                if($cost>$selling)throw new InvalidArgumentException('Vlastní cena některé varianty je nižší než její nákupní cena. Uprav ji v detailu produktu.');
            }
            $retailChanges=[];
            if($newRetail!==null){
                // Keep the revision history and invalidate open inline editors, in every language.
                foreach($snapshot['products']as$product){
                    if((int)$product['price_czk']===$newRetail)continue;
                    $row=$this->db->queryFirstRow('SELECT * FROM shop_product_revisions WHERE id=%i FOR UPDATE',$product['id']);
                    $this->db->update('shop_product_revisions',['active_product_key'=>null,'active_slug'=>null],'id=%i',$row['id']);
                    unset($row['id'],$row['saved_at']);$row['revision_number']=(int)$row['revision_number']+1;$row['price_czk']=$newRetail;
                    $this->db->insert('shop_product_revisions',$row);
                    $retailChanges[]=['language'=>$product['language'],'old'=>(int)$product['price_czk'],'new'=>$newRetail];
                }
            }
            $same=(int)$sale['supplier_price_czk']===$result['price']&&(int)$sale['partner_id']===$result['partner_id'];
            if(!empty($result['corrected_url'])&&($data['snapshot']['candidates'][$data['index']]['kind']??'')!=='variant'){
                $candidate=$data['snapshot']['candidates'][$data['index']];
                $corrected=HttpClient::redirect($candidate['url'],$result['corrected_url']);
                $this->db->update('shop_product_supplier_links',['url'=>$corrected],
                    'id=%i AND product_key=%s AND url=%s',$candidate['id'],$job['product_key'],$candidate['url']);
                $result['link_repair']=['old'=>$candidate['url'],'new'=>$corrected];
            }
            $variantChanges=[];
            if($enableVariantPricing)$this->db->insert('shop_product_supplier_variant_modes',['product_key'=>$job['product_key'],'enabled'=>1]);
            foreach($snapshot['variants']as$v)if(isset($variantPrices[$v['variant_key']])&&(int)$v['price_czk']!==$variantPrices[$v['variant_key']]){
                $this->db->update('shop_product_variants',['price_czk'=>$variantPrices[$v['variant_key']]],'product_key=%s AND variant_key=%s',$job['product_key'],$v['variant_key']);
                $variantChanges[]=['variant_key'=>$v['variant_key'],'old'=>$v['price_czk'],'new'=>$variantPrices[$v['variant_key']]];
            }
            if($variantChanges!==[])$this->db->query('UPDATE shop_product_variant_settings SET version=version+1 WHERE product_key=%s',$job['product_key']);
            $same=$same&&$overrides===$oldOverrides&&$variantChanges===[];
            if(!$same||$retailChanges!==[])$this->db->update('shop_product_sales',['supplier_price_czk'=>$result['price'],'partner_id'=>$result['partner_id'],'variant_prices_json'=>self::json((array)$overrides),'version'=>(int)$sale['version']+1],
                'product_key=%s AND version=%i',$job['product_key'],$sale['version']);
            $result['variant_price_changes']=$variantChanges;
            $result['retail_changes']=$retailChanges;$result['pricing_policy']=$newRetail===null?null:array_intersect_key($pricing,PricingPolicy::DEFAULTS);
            $same=$same&&$retailChanges===[];
            $this->db->update('shop_supplier_price_jobs',['status'=>$same?'checked':'applied','applied_at'=>$same?0:$this->now(),
                'result_json'=>self::json($result),'message'=>$same?'Cena ověřena, zůstává stejná.':
                ($retailChanges!==[]?'Cena dodavatele a společná prodejní cena aktualizovány.':($result['fallback']?'Cena i prodávající převzati po kontrole.':'Cena dodavatele aktualizována.'))],'id=%i',$id);
            $this->db->commit();
        }catch(Throwable $error){$this->db->rollback();throw $error;}
    }
    public function dismiss(int $id):void
    {$this->locked(function()use($id):void{$this->db->update('shop_supplier_price_jobs',['status'=>'dismissed','message'=>'Nabídka ponechána bez změny ceny.'],'id=%i AND status=%s',$id,'review');});}
    private static function currentJobs():string
    {
        return 'id IN (SELECT latest.id FROM (SELECT product_key,MAX(id) AS id FROM shop_supplier_price_jobs GROUP BY product_key) latest
            WHERE EXISTS (SELECT 1 FROM shop_product_revisions p WHERE p.product_key=latest.product_key AND p.active_product_key IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM shop_product_redirects r WHERE r.language=p.language AND r.source_slug=p.slug AND r.target_key<>p.product_key)))';
    }
    public function dashboard(string $filter='',string $query='',int $page=1,bool $history=false):array
    {
        if(!in_array($filter,['','queued','review','failed','applied','checked','dismissed','retried'],true))$filter='';
        $where=$history?'1=1':self::currentJobs();$args=[];
        if($filter==='applied'&&!$history){$where.=' AND status IN %ls';$args[]=['applied','checked'];}
        elseif($filter!==''){$where.=' AND status=%s';$args[]=$filter;}
        if($query!==''){$where.=' AND product_name LIKE %ss';$args[]=$query;}
        $total=(int)$this->db->queryFirstField('SELECT COUNT(*) FROM shop_supplier_price_jobs WHERE '.$where,...$args);
        $page=max(1,min($page,max(1,(int)ceil($total/30))));
        $rows=$this->db->query('SELECT * FROM shop_supplier_price_jobs WHERE '.$where.' ORDER BY id DESC LIMIT 30 OFFSET %i',...array_merge($args,[($page-1)*30]));
        $snapshots=[];
        foreach($rows as&$row){
            if(!array_key_exists($row['product_key'],$snapshots))$snapshots[$row['product_key']]=$this->snapshot($row['product_key']);
            $snapshot=$snapshots[$row['product_key']];$old=json_decode($row['data_json'],true);
            $row['current_sources']=$snapshot['candidates']??[];
            $row['current_product']=$snapshot['products'][0]??null;
            $row['configuration_issue']=self::configurationIssue($snapshot);
            $row['sources_changed']=$snapshot!==null&&self::sourceIdentity($snapshot)!==self::sourceIdentity($old['snapshot']??[]);
            $row['stale_result']=$snapshot===null||!hash_equals($old['fingerprint']??'',hash('sha256',self::json($snapshot)));
        }unset($row);
        $counts=['queued'=>0,'review'=>0,'failed'=>0,'applied'=>0,'checked'=>0];
        foreach($this->db->query('SELECT status,COUNT(*) AS n FROM shop_supplier_price_jobs WHERE '.self::currentJobs().' GROUP BY status')as$row)$counts[$row['status']]=(int)$row['n'];
        $counts['applied']+=$counts['checked'];
        $products=$this->db->query('SELECT p.product_key,MIN(p.name) AS name,MAX(s.supplier_price_czk) AS price FROM shop_product_revisions p
            LEFT JOIN shop_product_sales s ON s.product_key=p.product_key WHERE p.active_product_key IS NOT NULL GROUP BY p.product_key ORDER BY name');
        return compact('rows','counts','products','total','page','filter','query','history');
    }
    /** Business diagnostics only: no customer data, auth tokens, supplier HTML or cron secrets. */
    public function diagnostics():array
    {
        $items=[];
        $rows=$this->db->query('SELECT j.* FROM shop_supplier_price_jobs j INNER JOIN
            (SELECT product_key,MAX(id) AS id FROM shop_supplier_price_jobs GROUP BY product_key) latest ON latest.id=j.id ORDER BY j.id LIMIT 5000');
        foreach($rows as$row){
            $data=json_decode($row['data_json'],true);$sources=[];$snapshot=$this->snapshot($row['product_key']);
            foreach(($snapshot??$data['snapshot'])['candidates']??[]as$c){
                $resolved=$c['url'];$chain=[];
                for($hop=0;$hop<4;$hop++){
                    $cache=$this->db->queryFirstRow('SELECT expires_at,data_json FROM shop_supplier_price_cache WHERE url_hash=%s',HttpClient::cacheKey('product',$resolved));
                    $cached=$cache?json_decode($cache['data_json'],true):null;
                    if(empty($cached['redirect']))break;
                    $chain[]=['url'=>$resolved,'redirect'=>$cached['redirect'],'http_code'=>$cached['http_code']??null];
                    try{$resolved=HttpClient::redirect($resolved,$cached['redirect']);}catch(InvalidArgumentException $e){break;}
                }
                if(($c['kind']??'')==='variant'&&($cached===null||($cache&&$cache['expires_at']<=$this->now()))){
                    $reused=$this->reusableVariantDocument($snapshot??$data['snapshot'],$c,$resolved);
                    if($reused){$cached=$reused;$resolved=$reused['document_source'];$cache=['expires_at'=>(int)$reused['observed_at']+86400];}
                }
                $robotsCache=$this->db->queryFirstRow('SELECT expires_at,data_json FROM shop_supplier_price_cache WHERE url_hash=%s',
                    hash('sha256','robots:https://'.HttpClient::url($resolved).'/robots.txt'));
                $robotsData=Robots::cachedResponse($robotsCache?json_decode($robotsCache['data_json'],true):[]);
                $sources[]=['candidate'=>$c,'url'=>$c['url'],'seller'=>$c['name'],'selected'=>$c['selected'],'priority'=>$c['priority'],
                    'resolved_url'=>$resolved,'redirects'=>$chain,'cache'=>$cached,'expires_at'=>$cache?(int)$cache['expires_at']:0,
                    'robots'=>array_intersect_key($robotsData??[],array_flip(['error','http_code','fetched_at','unavailable']))];
            }
            $items[]=['id'=>(int)$row['id'],'product_key'=>$row['product_key'],'name'=>$row['product_name'],'status'=>$row['status'],
                'mode'=>$row['mode'],'created_at'=>(int)$row['created_at'],'checked_at'=>(int)$row['checked_at'],
                'next_at'=>(int)$row['next_at'],'applied_at'=>(int)$row['applied_at'],'message'=>$row['message'],
                'active'=>$snapshot!==null,'snapshot'=>$snapshot,
                'local_variants'=>($snapshot??$data['snapshot'])['variants']??[],'variants_enabled'=>!empty(($snapshot??$data['snapshot'])['variantSettings']['enabled']),
                'issue'=>PriceEvaluation::issue($row['status'],$row['message']),
                'sources'=>$sources,'result'=>json_decode($row['result_json']??'null',true),'trace'=>$data['trace']??[],'variant_issues'=>$data['variant_issues']??[],'variant_observations'=>array_values($data['variant_observations']??[])];
        }
        $settings=$this->settings();$summary=[];
        foreach($items as$item)if($item['active']){$code=$item['issue'];$summary[$code]=($summary[$code]??0)+1;}
        return ['format'=>'dobrodruzi.supplier-price-diagnostics','version'=>3,'parser_version'=>PriceParser::VERSION,'evaluator_version'=>self::EVALUATOR_VERSION,'exported_at'=>gmdate('c',$this->now()),
            'summary'=>$summary,'worker'=>array_intersect_key($settings,array_flip(['paused','weekly_enabled','next_weekly','last_tick','last_browser_tick','last_cron_tick','last_cli_tick'])),
            'pricing'=>array_intersect_key($settings,PricingPolicy::DEFAULTS),
            'hosts'=>$this->db->query('SELECT host,next_at FROM shop_supplier_price_hosts ORDER BY host'),'items'=>$items];
    }
}
