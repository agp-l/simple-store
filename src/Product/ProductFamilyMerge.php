<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Atomic consolidation of an explicitly reviewed, never-ordered size family. */
final class ProductFamilyMerge
{
    public function __construct(private MeekroDB $db) {}
    public static function family(string $name):?array
    {
        $name=trim($name);
        if(preg_match('/^(.+?)\s+[–—-]\s+((?:\d{2,3}\s*cm|XS|S|M|L|XL|XXL))(?:\s+([\p{L},\s\/]+))?$/uD',$name,$m))return ['name'=>$m[1],'size'=>$m[2],'colors'=>isset($m[3])?array_map('trim',explode(',',$m[3])):[]];
        if(preg_match('/^(.+?)\s+[–—-]\s+((?:Regular|Long|Small|Large)(?: (?:Wide|Mummy|Rectangular Wide))?|\d{2,3}\s*x\s*\d{2,3}(?:\s*cm)?(?:\s*-\s*[\p{L}, ]+)?)$/uD',$name,$m)||
           preg_match('/^(.+?)\s+((?:XS|S|M|L|XL|XXL)|(?:\d{1,4}(?:[.,]\d+)?\s*(?:l|ml)))$/uD',$name,$m))return ['name'=>$m[1],'size'=>$m[2],'colors'=>[]];
        return null;
    }
    public static function choices(array $choices,string $size):array
    {
        if(isset($choices['Varianta'])&&count($choices)===1&&preg_match('~^(.+?)\s*/\s*(Levá strana|Pravá strana|levý \(L\)|pravý \(R\))$~uD',$choices['Varianta'],$m)){
            $choices=['Barva'=>$m[1],'Zip'=>in_array($m[2],['Levá strana','levý (L)'],true)?'Levá strana':'Pravá strana'];
        }
        $dimension=preg_match('/\d\s*(?:l|ml)$/D',$size)?'Objem':'Velikost';
        if(isset($choices[$dimension])&&$choices[$dimension]!==$size&&!(preg_match('/^do \d{2,3} cm - (?:prodloužený|standardní) \((XS|S|M|L|XL|XXL)\)$/uD',$choices[$dimension],$m)&&$m[1]===$size))throw new InvalidArgumentException('Velikost v názvu odporuje vlastnostem varianty.');
        $choices[$dimension]??=$size;return ProductVariants::choices($choices);
    }
    public function installed():bool
    {return (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN %ls',['shop_product_family_merges','shop_product_supplier_variant_modes'])===2;}
    private function state(array $keys,bool $lock=false):array
    {
        sort($keys);$out=[];$suffix=$lock?' FOR UPDATE':'';
        foreach($keys as$key){
            if($lock){(new ProductStockRepository($this->db))->ensure($key);$this->db->queryFirstRow('SELECT * FROM shop_product_inventory WHERE product_key=%s FOR UPDATE',$key);}
            $out[$key]=[
                'products'=>$this->db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL ORDER BY language'.$suffix,$key),
                'inventory'=>$this->db->queryFirstRow('SELECT * FROM shop_product_inventory WHERE product_key=%s'.$suffix,$key),
                'settings'=>$this->db->queryFirstRow('SELECT * FROM shop_product_variant_settings WHERE product_key=%s'.$suffix,$key),
                'variants'=>$this->db->query('SELECT * FROM shop_product_variants WHERE product_key=%s ORDER BY position,variant_key'.$suffix,$key),
                'sale'=>$this->db->queryFirstRow('SELECT * FROM shop_product_sales WHERE product_key=%s'.$suffix,$key),
                'links'=>$this->db->query('SELECT * FROM shop_product_supplier_links WHERE product_key=%s ORDER BY id'.$suffix,$key),
                'mode'=>$this->installed()?$this->db->queryFirstRow('SELECT * FROM shop_product_supplier_variant_modes WHERE product_key=%s'.$suffix,$key):null,
            ];
        }
        return $out;
    }
    private static function hash(array $state):string{return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));}
    private function noOrders(array $keys):void
    {
        foreach($keys as$key){
            if($this->db->queryFirstField('SELECT id FROM shop_orders WHERE items_json LIKE %ss LIMIT 1',$key)!==null||
                $this->db->queryFirstField('SELECT order_id FROM shop_order_stock_reservations WHERE product_key=%s LIMIT 1',$key)!==null||
                $this->db->queryFirstField('SELECT order_id FROM shop_order_variant_reservations WHERE product_key=%s LIMIT 1',$key)!==null)
                throw new InvalidArgumentException('Některý produkt už patří do objednávky. Automatické sloučení je zastavené, aby se nezměnily vazby skladu a historie.');
        }
    }
    public function preview(string $target,string $language):array
    {
        $row=$this->db->queryFirstRow('SELECT * FROM shop_product_revisions WHERE product_key=%s AND language=%s AND active_product_key IS NOT NULL',$target,$language);
        $family=$row?self::family($row['name']):null;
        if(!$family)throw new InvalidArgumentException('Název musí končit samostatnou velikostí, například „Model – 195 cm“ nebo „Model – M“.');
        $keys=[];
        foreach($this->db->query('SELECT product_key,name FROM shop_product_revisions WHERE language=%s AND active_product_key IS NOT NULL AND published=1 AND brand=%s AND category=%s AND subcategory=%s AND name LIKE %ss',$language,$row['brand'],$row['category'],$row['subcategory'],$family['name'])as$c){
            $f=self::family($c['name']);if($f&&$f['name']===$family['name'])$keys[]=$c['product_key'];
        }
        if(count($keys)<2||count($keys)>12||!in_array($target,$keys,true))throw new InvalidArgumentException('Potřebuji 2 až 12 zveřejněných velikostí stejného modelu, značky a kategorie.');
        $this->noOrders($keys);$state=$this->state($keys);
        $slug=\SimpleStore\Navigation\Slugger::fromTitle($family['name']);
        if($slug===$row['slug']||$this->db->queryFirstField('SELECT id FROM shop_product_revisions WHERE language=%s AND active_slug=%s AND product_key<>%s LIMIT 1',$language,$slug,$target))throw new InvalidArgumentException('Nelze bezpečně odvodit volnou společnou adresu produktu.');
        $variants=[];$costs=[];$members=[];$seller=null;
        $products=array_map(static fn(array $s):array=>$s['products'][0],$state);
        $copy=self::combinedCopy($products);
        if(count(self::combinedDetails($products)['sections'])+count($copy['sections'])>30)throw new InvalidArgumentException('Sloučený obsah by přesáhl 30 bloků. Nejdřív zkrať opakované popisy jednotlivých velikostí.');
        foreach($state as$key=>$s){
            if(count($s['products'])!==1||$s['products'][0]['language']!==$language||!$s['sale'])throw new InvalidArgumentException('Slučované produkty musí být v jednom jazyce a mít nastavený způsob prodeje.');
            $p=$s['products'][0];$sourceFamily=self::family($p['name']);$size=$sourceFamily['size'];$seenColors=[];
            $identity=[$s['sale']['mode'],(int)$s['sale']['partner_id']];$seller??=$identity;
            if($seller!==$identity)throw new InvalidArgumentException('Slučované produkty musí mít stejný způsob prodeje a stejného prodávajícího.');
            $usesVariants=!empty($s['settings']['enabled']);
            if($usesVariants&&(int)($s['inventory']['available_quantity']??0)!==0)throw new InvalidArgumentException('Produkt má zbylý společný sklad. Nejdřív vyjasni jeho přiřazení k variantám.');
            $own=json_decode($s['sale']['variant_prices_json']??'{}',true)??[];$n=0;
            $sourceVariants=$s['variants'];
            if(!$usesVariants){
                $details=ProductDetails::decode($p['details_json'],$p['sizes']);
                if($details['options']!==[]||array_filter($sourceVariants,static fn(array $v):bool=>(bool)$v['enabled']||(int)$v['available_quantity']>0))throw new InvalidArgumentException('Společný sklad má další volby nebo připravené varianty. Jejich rozdělení musí nejdřív vyjasnit správce.');
                $sourceVariants=[['variant_key'=>md5('family:'.$key),'enabled'=>1,'choices_json'=>'{}','available_quantity'=>(int)($s['inventory']['available_quantity']??0),'price_czk'=>$p['price_czk'],'stock_status'=>$p['stock_status'],'image_path'=>$p['image_path'],'sku'=>'']];
            }
            foreach($sourceVariants as$v){
                if(!(int)$v['enabled'])continue;$n++;
                $choices=self::choices(json_decode($v['choices_json'],true,16,JSON_THROW_ON_ERROR),$size);
                if(isset($choices['Barva']))$seenColors[]=$choices['Barva'];
                $variants[]=['id'=>$v['variant_key'],'choices'=>$choices,
                    'quantity'=>(int)$v['available_quantity'],'price_czk'=>(int)($v['price_czk']??$p['price_czk']),
                    'stock_status'=>$v['stock_status'],'image_path'=>$v['image_path']?:$p['image_path'],'sku'=>$v['sku'],'enabled'=>true];
                $cost=$own[$v['variant_key']]??$s['sale']['supplier_price_czk'];if($cost!==null&&(int)$cost>0)$costs[$v['variant_key']]=(int)$cost;
            }
            if(!$n)throw new InvalidArgumentException('Některá velikost nemá žádnou nabízenou variantu.');
            foreach($sourceFamily['colors']as$color)if(!in_array($color,$seenColors,true))throw new InvalidArgumentException('Doplňující provedení v názvu není doložené vlastnostmi variant. Automatické sloučení je zastavené.');
            $members[]=['key'=>$key,'name'=>$p['name'],'size'=>$size,'variants'=>$n,'price'=>(int)$p['price_czk']];
        }
        $variants=ProductVariants::normalize($variants);
        usort($variants,static fn(array $a,array $b):int=>strnatcmp($a['choices']['Velikost']??$a['choices']['Objem'],$b['choices']['Velikost']??$b['choices']['Objem'])?:strcmp(ProductVariants::label($a['choices']),ProductVariants::label($b['choices'])));
        foreach($state as$s)if($this->db->queryFirstField('SELECT source_slug FROM shop_product_redirects WHERE target_key=%s OR (language=%s AND source_slug=%s) LIMIT 1',$s['products'][0]['product_key'],$language,$s['products'][0]['slug'])!==null)throw new InvalidArgumentException('Produkt už má historii slučování nebo přesměrování. Vyžaduje samostatné posouzení.');
        $proof=null;$models=[];$resolved=[];
        $proofLinks=[];foreach($state as$s)foreach($s['links']as$link){
            $proofLinks[$link['url']]=$link;
            parse_str((string)parse_url($link['url'],PHP_URL_QUERY),$query);
            if(isset($query['v_id'])&&count($query)===1){$base=explode('?',$link['url'],2)[0];$proofLinks[$base]=['url'=>$base];}
        }
        foreach($proofLinks as$link){
            $url=$link['url'];
            for($hop=0;$hop<4;$hop++){
                $cache=$this->db->queryFirstRow('SELECT data_json FROM shop_supplier_price_cache WHERE url_hash=%s AND expires_at>%i',hash('sha256','product:'.$url),time());
                $data=$cache?json_decode($cache['data_json'],true):null;
                if(empty($data['redirect']))break;
                try{$url=\SimpleStore\Supplier\HttpClient::redirect($url,$data['redirect']);}catch(InvalidArgumentException $e){$data=null;break;}
            }
            $resolved[$link['url']]=$url;
            if(empty($data['document']))continue;
            $partner=$this->db->queryFirstRow('SELECT website_url FROM shop_purchase_partners WHERE id=%i',(int)$seller[1]);
            if(!$partner||ProductPartnerAssignment::host($partner['website_url'])!==ProductPartnerAssignment::host($url))continue;
            $offers=$data['document']['offers']??[];$modelName=static fn(string $n):string=>strtolower(preg_replace('/\s+/u','',$n));
            $sameModel=$offers!==[];foreach($offers as$o)if($modelName($o['group_name']?:$o['product_name'])!==$modelName($family['name'])||!\SimpleStore\Supplier\PriceParser::sameModel($url,$o['group_url']?:$o['url']))$sameModel=false;
            if($sameModel)$models[]=$url;
            $mapped=array_map(static fn(array $v):array=>['variant_key'=>$v['id'],'enabled'=>1,'choices_json'=>json_encode($v['choices'])],$variants);
            $foundProof=\SimpleStore\Supplier\GraminoVariantScope::select($data['document'],$url,['products'=>[['name'=>$family['name']]],'variants'=>$mapped,'variantSettings'=>['enabled'=>1],'variantPriceMode'=>['enabled'=>1]]);
            if($foundProof!==null)$proof=$foundProof;
        }
        foreach($state as$s){$verified=false;foreach($s['links']as$link)foreach($models as$model)if(\SimpleStore\Supplier\PriceParser::sameModel($resolved[$link['url']]??$link['url'],$model))$verified=true;
            if(!$verified)throw new InvalidArgumentException('U některé velikosti nelze potvrdit společný model z uložené nabídky vybraného prodávajícího. Nejdřív ověř dodavatelský odkaz.');}
        $managedPrices=$proof!==null&&count($proof['variant_offers']??[])===count($variants);
        $pricing=(new \SimpleStore\Supplier\PriceManager($this->db))->settings();$previousPrices=[];
        foreach($variants as&$v){
            $previousPrices[$v['id']]=$v['price_czk'];
            if(!$managedPrices)continue;
            $price=(int)$proof['variant_offers'][$v['id']]['price'];$old=$costs[$v['id']]??0;
            if($old>0&&abs($price-$old)/$old>0.30)throw new InvalidArgumentException('Cena některé varianty se změnila o více než 30 %. Nejdřív ji ověř samostatně.');
            $costs[$v['id']]=$price;$previousPrices[$v['id']]=$v['price_czk'];
            if((int)$pricing['pricing_enabled'])$v['price_czk']=\SimpleStore\Supplier\PricingPolicy::price($price,$pricing);
            if($v['price_czk']<$price)throw new InvalidArgumentException('Prodejní cena některé varianty nepokrývá dodavatele. Nejdřív uprav cenotvorbu.');
        }unset($v);
        return ['target'=>$target,'language'=>$language,'name'=>$family['name'],'slug'=>$slug,'keys'=>$keys,'members'=>$members,'variants'=>$variants,'costs'=>$costs,'previous_prices'=>$previousPrices,
            'state_hash'=>self::hash($state),'expected'=>self::hash([$state,$costs,array_column($variants,'price_czk'),$managedPrices]),'proof'=>$proof,'managed_prices'=>$managedPrices,'copy'=>$copy];
    }
    public function candidates(string $language):array
    {
        $groups=[];
        foreach($this->db->query('SELECT product_key,name,brand,category,subcategory FROM shop_product_revisions WHERE language=%s AND active_product_key IS NOT NULL AND published=1 ORDER BY name',$language)as$p){
            $f=self::family($p['name']);if(!$f)continue;
            $signature=json_encode([$f['name'],$p['brand'],$p['category'],$p['subcategory']]);$groups[$signature][]=$p;
        }
        return array_values(array_filter($groups,static fn(array $group):bool=>count($group)>1));
    }
    public function apply(string $target,string $language,string $expected):string
    {
        if(!$this->installed())throw new InvalidArgumentException('Nejdřív aktualizuj databázi administrace.');
        $preview=$this->preview($target,$language);
        $this->db->startTransaction();
        try{
            $before=$this->state($preview['keys'],true);$this->noOrders($preview['keys']);
            if(!hash_equals($preview['expected'],$expected)||!hash_equals(self::hash($before),$preview['state_hash']))throw new InvalidArgumentException('Produkty, ceny nebo sklad se změnily. Zobraz nový náhled.');
            foreach($preview['keys']as$key)$this->db->update('shop_product_variants',['enabled'=>0,'available_quantity'=>0],'product_key=%s',$key);
            foreach($preview['variants']as$position=>$v)$this->db->replace('shop_product_variants',['product_key'=>$target,'variant_key'=>$v['id'],'choices_json'=>json_encode($v['choices'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'available_quantity'=>$v['quantity'],'stock_status'=>$v['stock_status'],'sku'=>$v['sku'],'price_czk'=>$v['price_czk'],'image_path'=>$v['image_path'],'enabled'=>1,'position'=>$position]);
            $allImages=[];$urls=[];
            foreach($before as$key=>$s){
                $p=$s['products'][0];$allImages[]=$p['image_path'];$d=ProductDetails::decode($p['details_json'],$p['sizes']);$allImages=array_merge($allImages,$d['gallery']);
                foreach($s['links']as$l)if(!isset($urls[$l['url']]))$urls[$l['url']]=$l['label'];
                $this->db->replace('shop_product_variant_settings',['product_key'=>$key,'enabled'=>$key===$target?1:0,'version'=>(int)($s['settings']['version']??0)+1]);
                $this->db->update('shop_product_inventory',['available_quantity'=>0],'product_key=%s',$key);
                $this->db->query('INSERT INTO shop_product_redirects(language,source_slug,target_key) VALUES(%s,%s,%s) ON DUPLICATE KEY UPDATE target_key=VALUES(target_key)',$language,$p['slug'],$target);
                if($key!==$target)$this->revision($p,['published'=>0]);
            }
            $p=$before[$target]['products'][0];$details=ProductDetails::decode($p['details_json'],$p['sizes']);
            $details['options']=[];$details['gallery']=array_values(array_filter(array_unique($allImages),static fn(string $path):bool=>$path!==''&&$path!==$p['image_path']));
            $mergedDetails=self::combinedDetails(array_map(static fn(array $s):array=>$s['products'][0],$before));
            $details['specifications']=$mergedDetails['specifications'];$details['sections']=array_merge($preview['copy']['sections'],$mergedDetails['sections']);
            $this->revision($p,['name'=>$preview['name'],'slug'=>$preview['slug'],'active_slug'=>$preview['slug'],'price_czk'=>min(array_column($preview['variants'],'price_czk')),
                'summary'=>$preview['copy']['summary'],'description'=>$preview['copy']['description'],'details_json'=>json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'sizes'=>'']);
            $links=new ProductSupplierLinkRepository($this->db);$existing=array_column($before[$target]['links'],'url');
            foreach($urls as$url=>$label)if(!in_array($url,$existing,true))$links->save($target,0,$label,$url);
            $sale=$before[$target]['sale'];$this->db->update('shop_product_sales',['supplier_price_czk'=>count($preview['costs'])===count($preview['variants'])?min($preview['costs']):null,'variant_prices_json'=>json_encode((object)$preview['costs'],JSON_THROW_ON_ERROR),'version'=>(int)$sale['version']+1],'product_key=%s',$target);
            $this->db->replace('shop_product_supplier_variant_modes',['product_key'=>$target,'enabled'=>(int)$preview['managed_prices']]);
            $this->db->insert('shop_product_family_merges',['target_key'=>$target,'before_json'=>json_encode($before,JSON_THROW_ON_ERROR),'after_hash'=>self::hash($this->state($preview['keys']))]);
            $this->db->update('shop_supplier_price_jobs',['status'=>'dismissed','message'=>'Produkt byl sloučen do společné nabídky; spusť kontrolu výsledného produktu.'],'product_key IN %ls AND status IN %ls',$preview['keys'],['queued','review','failed']);
            $this->db->commit();return $preview['slug'];
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    /** Only verbatim shared sentences describe the whole model; all differences stay size-labelled. */
    public static function combinedCopy(array $products):array
    {
        $products=array_values($products);
        $result=['summary'=>'','description'=>'','sections'=>[],'changed'=>false];$specific=[];
        foreach(['summary','description']as$field){
            $texts=array_map(static fn(array $p):string=>trim((string)($p[$field]??'')),$products);
            if(count(array_unique($texts))===1){$result[$field]=$texts[0];continue;}
            $result['changed']=true;
            $sentences=array_map(static fn(string $text):array=>array_values(array_filter(preg_split('/(?<=[.!?])\s+(?=[\p{Lu}\d])/u',$text)?:[])),$texts);
            $common=array_values(array_intersect(...$sentences));$result[$field]=implode(' ',$common);
            foreach($sentences as$i=>$parts){$unique=array_values(array_diff($parts,$common));if($unique!==[])$specific[$i][]=implode(' ',$unique);}
        }
        if($result['summary']===''&&$result['changed'])$result['summary']='Vyberte provedení podle svých potřeb. Jednotlivé varianty mají vlastní rozměry, cenu a dostupnost.';
        if($result['description']===''&&$result['changed'])$result['description']='Podrobnosti jednotlivých provedení najdete níže. Cenu a dostupnost uvidíte po výběru varianty.';
        foreach($specific as$i=>$parts){$f=self::family($products[$i]['name']);$label=$f['size'].($f['colors']?' '.implode(', ',$f['colors']):'');
            $result['sections'][]=['type'=>'text','heading'=>'Provedení '.$label,'body'=>implode("\n\n",array_unique($parts))];}
        return $result;
    }
    /** Keep differing weights and construction details explicitly tied to their original size. */
    public static function combinedDetails(array $products):array
    {
        $specs=[];$sections=[];$result=['specifications'=>[],'sections'=>[]];
        foreach($products as$p){
            $family=self::family($p['name']);$label=$family['size'].($family['colors']?' '.implode(', ',$family['colors']):'');
            $details=ProductDetails::decode($p['details_json'],$p['sizes']);
            foreach($details['specifications']as$spec)$specs[$spec['name']][$spec['value']][]=$label;
            foreach($details['sections']as$section){
                if($section['heading']==='Výběr velikosti')continue;
                $signature=json_encode([$section['type'],$section['heading'],$section['body']]);
                $sections[$signature]['section']=$section;$sections[$signature]['labels'][]=$label;
            }
        }
        foreach($specs as$name=>$values){
            if(count($values)===1&&count(reset($values))===count($products))$value=(string)array_key_first($values);
            else{$parts=[];foreach($values as$text=>$labels)$parts[]=implode(', ',array_unique($labels)).': '.$text;$value=implode(' · ',$parts);}
            $result['specifications'][]=['name'=>$name,'value'=>$value];
        }
        foreach($sections as$item){$section=$item['section'];if(count($item['labels'])!==count($products))$section['heading'].=' — '.implode(', ',array_unique($item['labels']));$result['sections'][]=$section;}
        $result['sections'][]=['type'=>'text','heading'=>'Výběr velikosti','body'=>'Provedení vyber v nabídce variant. Každá kombinace má uvedenou vlastní cenu a dostupnost. Rozdílné parametry velikostí najdeš v přehledu výše.'];
        return $result;
    }
    private function revision(array $row,array $changes):void
    {
        $this->db->update('shop_product_revisions',['active_product_key'=>null,'active_slug'=>null],'id=%i',$row['id']);
        unset($row['id'],$row['saved_at']);$row['revision_number']=(int)$row['revision_number']+1;
        $this->db->insert('shop_product_revisions',array_replace($row,$changes));
    }
    public function history():array
    {
        if(!$this->installed())return [];
        $rows=$this->db->query('SELECT id,target_key,created_at,undone,before_json FROM shop_product_family_merges ORDER BY id DESC LIMIT 100');$keys=[];
        foreach($rows as&$row){$row['source_keys']=array_values(array_diff(array_keys(json_decode($row['before_json'],true,64,JSON_THROW_ON_ERROR)),[$row['target_key']]));$keys=array_merge($keys,$row['source_keys']);unset($row['before_json']);}unset($row);
        $present=$keys?$this->db->queryFirstColumn('SELECT DISTINCT product_key FROM shop_product_revisions WHERE active_product_key IS NOT NULL AND product_key IN %ls',array_values(array_unique($keys))):[];
        foreach($rows as&$row){$row['sources_deleted']=!$row['undone']&&array_intersect($row['source_keys'],$present)===[];unset($row['source_keys']);}unset($row);
        return $rows;
    }
    /** Only obsolete source cards recorded by a completed merge can be removed. */
    public function deletionPreview(int $id,bool $lock=false):array
    {
        $entry=$this->db->queryFirstRow('SELECT * FROM shop_product_family_merges WHERE id=%i'.($lock?' FOR UPDATE':''),$id);
        if(!$entry||$entry['undone'])throw new InvalidArgumentException('Toto sloučení není připravené k odstranění původních karet.');
        $before=json_decode($entry['before_json'],true,64,JSON_THROW_ON_ERROR);$target=$entry['target_key'];
        $current=$this->state(array_keys($before),$lock);$parent=$current[$target]??null;
        if(!$parent||count($parent['products'])!==1||!$parent['products'][0]['published']||empty($parent['settings']['enabled']))throw new InvalidArgumentException('Společný produkt už není zveřejněný nebo nemá zapnuté varianty.');
        $active=[];foreach($parent['variants']as$v)if($v['enabled'])$active[$v['variant_key']]=$v;
        $sources=[];$redirects=[];
        foreach($before as$key=>$old){
            if($key===$target)continue;
            $live=$current[$key];if($live['products']===[])continue;
            if(count($old['products'])!==1||count($live['products'])!==1)throw new InvalidArgumentException('Původní karta má nečekané jazykové verze.');
            $p=$live['products'][0];$original=$old['products'][0];$expected=$original;$expected['published']=0;$expected['revision_number']=(int)$original['revision_number']+1;
            $comparable=$p;foreach(['id','saved_at']as$field){unset($expected[$field],$comparable[$field]);}
            if($expected!=$comparable||$live['sale']!=$old['sale']||$live['links']!=$old['links']||$live['mode']!=$old['mode']||!empty($live['settings']['enabled'])||(int)($live['inventory']['available_quantity']??0)!==0)throw new InvalidArgumentException('Původní karta byla po sloučení upravena. Automatické odstranění je zastavené.');
            foreach($live['variants']as$v)if($v['enabled']||(int)$v['available_quantity']!==0)throw new InvalidArgumentException('Původní karta má aktivní variantu nebo skladové kusy.');
            $originalVariants=!empty($old['settings']['enabled'])?array_filter($old['variants'],static fn(array $v):bool=>(bool)$v['enabled']):[['variant_key'=>md5('family:'.$key),'choices_json'=>'{}']];
            $size=self::family($original['name'])['size'];
            foreach($originalVariants as$v){
                $moved=$active[$v['variant_key']]??null;
                if(!$moved||json_decode($moved['choices_json'],true)!=self::choices(json_decode($v['choices_json'],true),$size))throw new InvalidArgumentException('Ve společném produktu chybí některá původní kombinace.');
            }
            $redirect=$this->db->queryFirstRow('SELECT language,source_slug,target_key FROM shop_product_redirects WHERE language=%s AND source_slug=%s'.($lock?' FOR UPDATE':''),$p['language'],$p['slug']);
            if(!$redirect||$redirect['target_key']!==$target)throw new InvalidArgumentException('Původní adresa nemá správné přesměrování.');
            $redirects[]=$redirect;$sources[]=['key'=>$key,'name'=>$p['name'],'language'=>$p['language']];
        }
        $this->noOrders(array_column($sources,'key'));
        return ['id'=>$id,'name'=>$parent['products'][0]['name'],'sources'=>$sources,'expected'=>self::hash([$current,$redirects])];
    }
    public function deleteSources(int $id,string $expected):int
    {
        $this->db->startTransaction();
        try{
            $plan=$this->deletionPreview($id,true);
            if(!hash_equals($plan['expected'],$expected))throw new InvalidArgumentException('Od náhledu se změnily produkty, ceny nebo sklad. Připrav nový náhled odstranění.');
            $relations=(new ProductRelationRepository($this->db))->installed();
            foreach($plan['sources']as$source){
                if($relations)$this->db->query('DELETE FROM shop_product_relations WHERE language=%s AND (product_a=%s OR product_b=%s)',$source['language'],$source['key'],$source['key']);
                $this->db->query('DELETE FROM shop_product_revisions WHERE product_key=%s AND language=%s',$source['key'],$source['language']);
            }
            // Keep redirects, media, order history and the complete pre-merge backup.
            $this->db->commit();return count($plan['sources']);
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function undo(int $id):void
    {
        $this->db->startTransaction();
        try{
            $entry=$this->db->queryFirstRow('SELECT * FROM shop_product_family_merges WHERE id=%i FOR UPDATE',$id);
            if(!$entry||$entry['undone'])throw new InvalidArgumentException('Toto sloučení už není připravené k vrácení.');
            $before=json_decode($entry['before_json'],true,64,JSON_THROW_ON_ERROR);$keys=array_keys($before);
            $current=$this->state($keys,true);$this->noOrders($keys);
            if(!hash_equals($entry['after_hash'],self::hash($current)))throw new InvalidArgumentException('Od sloučení se změnily produkty, ceny nebo sklad. Automatické vrácení by přepsalo nové změny, proto je zastavené. Původní záloha zůstala uložená.');
            foreach($before as$key=>$s){
                $this->db->update('shop_product_variants',['enabled'=>0,'available_quantity'=>0],'product_key=%s',$key);
                foreach($s['variants']as$v)$this->db->replace('shop_product_variants',$v);
                if($s['settings']){$settings=$s['settings'];$settings['version']=(int)$current[$key]['settings']['version']+1;$this->db->replace('shop_product_variant_settings',$settings);}else$this->db->query('DELETE FROM shop_product_variant_settings WHERE product_key=%s',$key);
                if($s['inventory'])$this->db->replace('shop_product_inventory',$s['inventory']);
                if($s['sale']){$sale=$s['sale'];$sale['version']=(int)$current[$key]['sale']['version']+1;$this->db->replace('shop_product_sales',$sale);}
                foreach($s['products']as$old){
                    $live=$current[$key]['products'][0];$changes=$old;unset($changes['id'],$changes['saved_at'],$changes['revision_number']);$this->revision($live,$changes);
                    $this->db->query('DELETE FROM shop_product_redirects WHERE language=%s AND source_slug=%s AND target_key=%s',$old['language'],$old['slug'],$entry['target_key']);
                }
                $this->db->query('DELETE FROM shop_product_supplier_links WHERE product_key=%s',$key);
                foreach($s['links']as$link)$this->db->insert('shop_product_supplier_links',$link);
                if($s['mode'])$this->db->replace('shop_product_supplier_variant_modes',$s['mode']);else$this->db->query('DELETE FROM shop_product_supplier_variant_modes WHERE product_key=%s',$key);
            }
            $this->db->update('shop_product_family_merges',['undone'=>1],'id=%i',$id);$this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}
