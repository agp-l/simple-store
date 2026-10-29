<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use MeekroDB;
use SimpleStore\Product\ProductVariantSupplierRepository;

/** Independent offer evidence. This service never writes product prices, stock or sellers. */
final class PartnerOfferCatalog
{
    public const SCHEMA = [
        "CREATE TABLE IF NOT EXISTS shop_supplier_offer_scans (
            product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            fingerprint CHAR(64) NOT NULL DEFAULT '', status VARCHAR(16) NOT NULL DEFAULT 'queued',
            next_at BIGINT UNSIGNED NOT NULL DEFAULT 0, checked_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            data_json MEDIUMTEXT NOT NULL, KEY due (status,next_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS shop_supplier_offers (
            offer_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            product_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            variant_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
            partner_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            source_url VARCHAR(1000) NOT NULL, resolved_url VARCHAR(1000) NOT NULL DEFAULT '',
            fingerprint CHAR(64) NOT NULL, status VARCHAR(24) NOT NULL, price_czk INT UNSIGNED DEFAULT NULL, amount DECIMAL(12,2) DEFAULT NULL,
            availability VARCHAR(24) NOT NULL DEFAULT 'unknown', reported_quantity INT UNSIGNED DEFAULT NULL,
            observed_at BIGINT UNSIGNED NOT NULL DEFAULT 0, checked_at BIGINT UNSIGNED NOT NULL,
            evidence_json MEDIUMTEXT NOT NULL, message VARCHAR(1000) NOT NULL DEFAULT '',
            KEY product (product_key), KEY partner (partner_key,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    private ?bool $ready=null;
    public function __construct(private MeekroDB $db, private ?int $now=null) { $this->now ??= time(); }
    private static function json(array $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    public function installed(): bool
    {
        return $this->ready ??= (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN %ls',['shop_supplier_offers','shop_supplier_offer_scans'])===2;
    }
    public function request(string $key): void
    {
        if (!$this->installed()) return;
        $this->db->query('INSERT INTO shop_supplier_offer_scans (product_key,data_json) SELECT %s,%s FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL LIMIT 1
            ON DUPLICATE KEY UPDATE status=%s,next_at=0,fingerprint=%s,data_json=%s',$key,'{}',$key,'queued','','{}');
    }
    /** A default product is represented by the empty variant key in this evidence layer. */
    public static function candidates(array $snapshot): array
    {
        $partners=array_column($snapshot['partners'],null,'id');
        $candidates=$snapshot['candidates']??(VariantOfferPlan::candidates($snapshot)??PriceManager::candidates($snapshot['links'],$snapshot['partners'],(int)($snapshot['sale']['partner_id']??0)));
        foreach ($candidates as &$candidate) {
            $candidate['partner_key']=$partners[$candidate['partner_id']]['partner_key'];
            if (($candidate['kind']??'')==='variant') continue;
            $active=VariantOfferPlan::activeVariants($snapshot);
            $candidate['links']=[];
            if ($active!==[]) {
                $candidate['kind']='variant';
                foreach ($active as $variant) $candidate['links'][]=['variant_key'=>$variant['variant_key'],'link_key'=>hash('sha256',$variant['variant_key'].$candidate['url']),'origin'=>'inherited'];
            } else $candidate['links'][]=['variant_key'=>'','link_key'=>'','origin'=>'manual'];
        }
        unset($candidate);
        return $candidates;
    }
    public static function fingerprint(array $snapshot): string
    {
        $variants=[];
        foreach (VariantOfferPlan::activeVariants($snapshot) as $variant) $variants[$variant['variant_key']]=$variant['choices_json'];
        return hash('sha256',self::json([PriceParser::VERSION,$snapshot['products'][0]['name'],$variants,self::candidates($snapshot)]));
    }
    public static function offerKey(string $key,string $variant,string $partner,string $url): string
    {
        return hash('sha256',self::json([$key,$variant,$partner,$url]));
    }
    /** All current shared identities in a fixed number of queries, without checkout/customer data. */
    public function snapshots(?array $keys = null): array
    {
        $snapshots=[];
        if ($keys === []) return [];
        $where = $keys === null ? '' : ' AND p.product_key IN %ls';
        $params = $keys === null ? ['cs'] : [$keys, 'cs'];
        foreach ($this->db->query('SELECT product_key,name,language,slug,image_path FROM shop_product_revisions p WHERE active_product_key IS NOT NULL AND NOT EXISTS
            (SELECT 1 FROM shop_product_redirects r WHERE r.language=p.language AND r.source_slug=p.slug AND r.target_key<>p.product_key)
            ' . $where . ' ORDER BY (language=%s) DESC,language', ...$params) as $product) {
            $snapshots[$product['product_key']]??=['products'=>[$product],'sale'=>null,'variants'=>[],'variantLinks'=>[],'variantSettings'=>null,'links'=>[]];
        }
        $productKeys=array_keys($snapshots);
        if ($productKeys === []) return [];
        $partners=$this->db->query('SELECT id,partner_key,name,website_url,priority FROM shop_purchase_partners ORDER BY priority,id');
        foreach ($this->db->query('SELECT product_key,mode,partner_id FROM shop_product_sales WHERE product_key IN %ls', $productKeys) as $row) if (isset($snapshots[$row['product_key']])) $snapshots[$row['product_key']]['sale']=$row;
        foreach ($this->db->query('SELECT product_key,enabled FROM shop_product_variant_settings WHERE product_key IN %ls', $productKeys) as $row) if (isset($snapshots[$row['product_key']])) $snapshots[$row['product_key']]['variantSettings']=$row;
        foreach ($this->db->query('SELECT product_key,variant_key,choices_json,enabled FROM shop_product_variants WHERE product_key IN %ls ORDER BY variant_key', $productKeys) as $row) if (isset($snapshots[$row['product_key']])) $snapshots[$row['product_key']]['variants'][]=$row;
        foreach ($this->db->query('SELECT product_key,id,label,url FROM shop_product_supplier_links WHERE product_key IN %ls ORDER BY id', $productKeys) as $row) if (isset($snapshots[$row['product_key']])) $snapshots[$row['product_key']]['links'][]=$row;
        foreach ((new ProductVariantSupplierRepository($this->db))->forProducts(array_keys($snapshots)) as $row) $snapshots[$row['product_key']]['variantLinks'][]=$row;
        foreach ($snapshots as &$snapshot) $snapshot['partners']=$partners;
        unset($snapshot);
        return $snapshots;
    }
    /** Daily refresh, including alternatives even when the selected merchant succeeds. */
    public function schedule(): int
    {
        if (!$this->installed()) return 0;
        $count=0;
        $scans=array_column($this->db->query('SELECT product_key,fingerprint,status,checked_at,next_at FROM shop_supplier_offer_scans'),null,'product_key');
        foreach ($this->snapshots() as $key=>$snapshot) {
            if (self::candidates($snapshot)===[]) continue;
            $fingerprint=self::fingerprint($snapshot); $scan=$scans[$key]??null;
            if ($scan!==null && $scan['fingerprint']===$fingerprint && ($scan['status']==='queued'||(int)$scan['next_at']>$this->now)) continue;
            $this->db->query('INSERT INTO shop_supplier_offer_scans (product_key,fingerprint,status,next_at,data_json) VALUES (%s,%s,%s,%i,%s)
                ON DUPLICATE KEY UPDATE fingerprint=VALUES(fingerprint),status=VALUES(status),next_at=VALUES(next_at),data_json=VALUES(data_json)',
                $key,$fingerprint,'queued',$this->now,'{}'); $count++;
        }
        return $count;
    }
    /** Called under the shared worker lock. The reader consumes at most one remote request. */
    public function step(callable $snapshotFor,callable $read): ?array
    {
        if (!$this->installed()) return null;
        $scan=$this->db->queryFirstRow('SELECT * FROM shop_supplier_offer_scans WHERE status=%s AND next_at<=%i ORDER BY next_at,product_key LIMIT 1','queued',$this->now);
        if (!$scan) return null;
        $key=$scan['product_key']; $snapshot=$snapshotFor($key);
        if ($snapshot===null || self::candidates($snapshot)===[]) {
            $this->db->update('shop_supplier_offer_scans',['status'=>'inactive','checked_at'=>$this->now],'product_key=%s',$key); return ['state'=>'working','wait'=>1];
        }
        $fingerprint=self::fingerprint($snapshot);
        $data=$fingerprint===$scan['fingerprint']?json_decode($scan['data_json'],true,64,JSON_THROW_ON_ERROR):[];
        $index=(int)($data['index']??0); $candidates=self::candidates($snapshot); $candidate=$candidates[$index]??null;
        if ($candidate===null) {
            $this->db->update('shop_supplier_offer_scans',['status'=>'complete','checked_at'=>$this->now,'fingerprint'=>$fingerprint,'data_json'=>'{}'],'product_key=%s',$key); return ['state'=>'working','wait'=>1];
        }
        $result=$read($snapshot,$candidate,$data);
        if (isset($result['wait'])) {
            $data=array_replace($data,$result['state']??[]);
            $this->db->update('shop_supplier_offer_scans',['fingerprint'=>$fingerprint,'data_json'=>self::json($data),'next_at'=>$this->now+max(1,(int)$result['wait'])],'product_key=%s',$key);
            return ['state'=>'working','wait'=>1];
        }
        $observations=[]; $issues=[];
        if (!isset($result['error'])) {
            try {
                if (($candidate['kind']??'')==='variant') {
                    $resolved=$candidate;
                    if ($candidate['url']!==$result['source_url']) $resolved['resolved_url']=$result['source_url'];
                    $evidence=VariantOfferEvaluation::observe($result['document'],$result['source_url'],$snapshot,$resolved,(int)$result['observed_at']);
                    $observations=$evidence['observations']; $issues=array_column($evidence['issues'],null,'link_key');
                } else {
                    $offer=self::defaultOffer($result['document'],$result['source_url'],$snapshot);
                    $observations['']=$offer+['observed_at'=>$result['observed_at']];
                }
            } catch (\InvalidArgumentException $e) { $result['error']=$e->getMessage(); }
        }
        $this->db->startTransaction();
        try {
            // Product edits use the same revision locks. Never publish stale evidence after an edit.
            $current=$snapshotFor($key,true);
            if ($current===null || self::fingerprint($current)!==$fingerprint) { $this->db->rollback(); return ['state'=>'working','wait'=>1]; }
            foreach ($candidate['links'] as $link) {
                $offer=$observations[$link['link_key']]??null;
                $message=$result['error']??$issues[$link['link_key']]['message']??'Přesnou nabídku se nepodařilo ověřit.';
                $this->db->replace('shop_supplier_offers',[
                    'offer_key'=>self::offerKey($key,$link['variant_key'],$candidate['partner_key'],$candidate['url']),
                    'fingerprint'=>$fingerprint,'product_key'=>$key,'variant_key'=>$link['variant_key'],'partner_key'=>$candidate['partner_key'],'source_url'=>$candidate['url'],
                    'resolved_url'=>$offer['url']??$result['source_url']??'','status'=>$offer?'verified':'error',
                    'price_czk'=>$offer['price']??null,'amount'=>$offer['amount']??null,
                    'availability'=>$offer['availability']??'unknown','reported_quantity'=>$offer['reported_quantity']??null,
                    'observed_at'=>$offer['observed_at']??0,'checked_at'=>$this->now,
                    'evidence_json'=>self::json($offer??[]),'message'=>$offer?'':(preg_replace('/^(.{0,1000}).*/us', '$1', $message) ?? 'Chyba načítání.'),
                ]);
            }
            $done=$index+1>=count($candidates);
            // A cache reused shortly before expiry must not postpone refresh for a
            // further day. Errors have their own daily retry time; all observations
            // retain the original fetch time, never the time of this local scan.
            $expires=$this->now+86400;
            foreach($observations as$observation)$expires=min($expires,(int)$observation['observed_at']+86400);
            $expires=min($expires,(int)($data['expires_at']??$expires));
            $this->db->update('shop_supplier_offer_scans',['fingerprint'=>$fingerprint,'status'=>$done?'complete':'queued',
                'next_at'=>$done?max($this->now+60,$expires):$this->now,'checked_at'=>$done?$this->now:(int)$scan['checked_at'],'data_json'=>self::json(['index'=>$index+1,'expires_at'=>$expires])],'product_key=%s',$key);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
        return ['state'=>'working','wait'=>1];
    }
    public static function defaultOffer(array $document,string $url,array $snapshot): array
    {
        $offer=PriceEvaluation::select($document,$url,$snapshot);
        $expected=SupplierCatalogAliases::name($snapshot['products'][0]['name'],($offer['group_name']??'')?:($offer['product_name']??''),$url,$offer);
        $scope=$offer['identity_evidence']??[];
        // A product title may already contain its fixed volume/size. The
        // explicit group matcher proves that identity more precisely than
        // comparing the group's bare name with the full local title again.
        $exactScope=($scope['method']??'')==='exact_local_variant_scope' && count($scope['matched_offers']??[])===1;
        if (!empty($offer['uncertain']) || (!$exactScope&&!PriceParser::sameProductName($offer,$expected))) throw new \InvalidArgumentException('Model nebo varianta se přesně neshodují.');
        if (($scope['method']??'')==='exact_local_variant_scope' && !$exactScope) throw new \InvalidArgumentException('Základní produkt odpovídá více variantám partnera. Doplň přesný odkaz.');
        $offer['url']=$offer['corrected_url']??$offer['url'];
        return $offer;
    }
    public function nextAt(): ?int
    {
        if (!$this->installed()) return null;
        $next=$this->db->queryFirstField('SELECT MIN(next_at) FROM shop_supplier_offer_scans WHERE status=%s','queued');
        return $next===null?null:(int)$next;
    }
    public function report(?array $keys = null): array
    {
        $snapshots=$this->snapshots($keys); $offers=$this->installed() && $snapshots!==[] ? array_column($this->db->query('SELECT * FROM shop_supplier_offers WHERE product_key IN %ls',array_keys($snapshots)),null,'offer_key'):[];
        $products=[];
        foreach ($snapshots as $key=>$snapshot) {
            $fingerprint=self::fingerprint($snapshot);
            $product=$snapshot['products'][0]; $active=VariantOfferPlan::activeVariants($snapshot);
            $product['variants']=$active===[]?[['variant_key'=>'','choices'=>(object)[]]]:array_values(array_map(static fn(array $v):array=>['variant_key'=>$v['variant_key'],'choices'=>json_decode($v['choices_json'])],$active));
            $product['offers']=[]; $product['sale_mode']=$snapshot['sale']['mode']??'own';
            foreach (self::candidates($snapshot) as $candidate) foreach ($candidate['links'] as $link) {
                $offer=$offers[self::offerKey($key,$link['variant_key'],$candidate['partner_key'],$candidate['url'])]??null;
                $entry=['variant_key'=>$link['variant_key'],'partner_key'=>$candidate['partner_key'],'partner_name'=>$candidate['name'],'source_url'=>$candidate['url'],
                    'status'=>'unverified','price_czk'=>null,'amount'=>null,'availability'=>'unknown','reported_quantity'=>null,'observed_at'=>0,'checked_at'=>0,'resolved_url'=>'','message'=>'Čeká na ověření.'];
                if ($offer && ($offer['fingerprint']??'')===$fingerprint) {
                    $entry=array_replace($entry,array_intersect_key($offer,$entry));
                    foreach (['observed_at','checked_at'] as $field) $entry[$field]=(int)$entry[$field];
                    foreach (['price_czk','reported_quantity'] as $field) if ($entry[$field]!==null) $entry[$field]=(int)$entry[$field];
                    if ($entry['status']==='verified' && ($entry['observed_at']<=$this->now-86400||$entry['observed_at']>$this->now)) $entry['status']='expired';
                }
                $product['offers'][]=$entry;
            }
            $products[]=$product;
        }
        $partners=$this->db->query('SELECT partner_key,name,website_url FROM shop_purchase_partners ORDER BY priority,id');
        return ['format'=>'simple-store.partner-offers.v1','exported_at'=>gmdate('c',$this->now),'valid_for_seconds'=>86400,
            'summary'=>PartnerCoverage::summarize($products,$partners),'products'=>$products,
            'queued_products'=>$this->installed()?(int)$this->db->queryFirstField('SELECT COUNT(*) FROM shop_supplier_offer_scans WHERE status=%s','queued'):0];
    }
}
