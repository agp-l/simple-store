<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Proves a common price for the exact sizes/colours sold locally, never a cheapest-price guess. */
final class GraminoVariantScope
{
    public static function select(array $document,string $url,array $snapshot):?array
    {
        if(!in_array(strtolower((string)parse_url($url,PHP_URL_HOST)),['gramino.cz','www.gramino.cz'],true)||empty($document['single_offers']))return null;
        $offers=$document['offers']??[];
        if(!$offers||count($offers)!==($document['product_count']??0))return null;
        $group=$offers[0]['group_name']??'';
        $name=SupplierCatalogAliases::name($snapshot['products'][0]['name']??'',$group,$url);
        $suffix=self::suffix($name,$group,$url);
        if($group===''||$suffix===null)return null;
        $base=self::constraints($suffix);
        if($base===null)return null;
        $parsed=[];
        foreach($offers as$i=>$offer){
            if(($offer['group_name']??'')!==$group||!PriceParser::sameModel($url,$offer['group_url']??'')||!PriceParser::sameModel($url,$offer['url']))return null;
            $tail=self::suffix($offer['product_name']??'',$group,$url);
            // Gramino's full variant name explicitly lists colour, size; retain this evidence in old caches too.
            $dimensions=$tail===null?null:self::sourceDimensions($tail);
            if($dimensions===null)return null;
            $parsed[$i]=$dimensions;
        }
        $variants=!empty($snapshot['variantSettings']['enabled'])?array_values(array_filter($snapshot['variants']??[],static fn(array $v):bool=>!empty($v['enabled']))):[['choices_json'=>'{}']];
        if(!$variants)return null;
        $matched=[];$variantOffers=[];
        foreach($variants as$variant){
            $constraints=$base;$choices=json_decode($variant['choices_json']??'null',true);
            if(!is_array($choices))return null;
            foreach($choices as$key=>$value)if(self::normalize((string)$key)==='barva a velikost'){
                if(!is_string($value)||!preg_match('~^(.+?)\s+/\s+([^/]+)$~u',str_replace('\\/','/',$value),$parts))return null;
                if(isset($choices['Barva'])||isset($choices['Velikost'])||!self::size($parts[2]))return null;
                unset($choices[$key]);$choices['Barva']=$parts[1];$choices['Velikost']=$parts[2];
            }
            foreach($choices as$key=>$value){
                $dimension=match(self::normalize((string)$key)){'barva','color','colour'=>'color','velikost','size','objem'=>'size','zip','strana zipu'=>'zip',default=>null};
                if($dimension===null||!is_string($value))return null;
                $value=match($dimension){'color'=>self::color($value),'size'=>self::sizeValue($value),'zip'=>self::zip($value)};
                if($value===null)return null;
                if($value===''||(isset($constraints[$dimension])&&!in_array($value,$constraints[$dimension],true)))return null;
                $constraints[$dimension]=[$value];
            }
            $found=false;$variantMatches=[];
            foreach($parsed as$i=>$dimensions){
                foreach($constraints as$key=>$values)if(!isset($dimensions[$key])||!in_array($dimensions[$key],$values,true))continue 2;
                $matched[$i]=$offers[$i];$variantMatches[$i]=$offers[$i];$found=true;
            }
            if(!$found)return null; // Every enabled local combination must be represented.
            if(!empty($snapshot['variantPriceMode']['enabled'])){
                if(count(array_unique(array_map(static fn(array $o):string=>number_format((float)$o['amount'],2,'.',''),$variantMatches)))!==1)return null;
                $choice=reset($variantMatches);$states=array_unique(array_column($variantMatches,'availability'));
                $variantOffers[$variant['variant_key']]=['price'=>$choice['price'],'availability'=>count($states)===1?reset($states):'unknown','urls'=>array_column($variantMatches,'url')];
            }
        }
        $uniform=count(array_unique(array_map(static fn(array $o):string=>number_format((float)$o['amount'],2,'.',''),$matched)))===1;
        if(!$uniform&&$variantOffers===[])return null;
        $result=reset($matched);$result['url']=$url;$result['uniform_variants']=true;$result['uncertain']=false;
        $availability=array_unique(array_column($matched,'availability'));
        $result['availability']=count($availability)===1?reset($availability):'unknown';
        $result['identity_evidence']=['method'=>'exact_local_variant_scope','verified_url'=>$url,'model'=>$group,
            'local_variants'=>count($variants),'matched_offers'=>array_values(array_map(static fn(array $o):array=>array_intersect_key($o,array_flip(['url','product_name','amount'])),$matched))];
        // A single exact choice has a stable variant link. For a shared price keep the model URL.
        $result['corrected_url']=count($matched)===1?$result['identity_evidence']['matched_offers'][0]['url']:$offers[0]['group_url'];
        if($variantOffers!==[]){$result['variant_offers']=$variantOffers;$result['price']=min(array_column($variantOffers,'price'));$result['uniform_variants']=$uniform;}
        return $result;
    }
    private static function normalize(string $value):string
    {
        $value=str_replace(['™','®','’','‘'],['','','\'','\''],$value);
        $value=strtr(trim($value),['Č'=>'č','Š'=>'š','Ž'=>'ž','Ř'=>'ř','Ý'=>'ý','Á'=>'á','Í'=>'í','É'=>'é','Ě'=>'ě','Ů'=>'ů','Ú'=>'ú','Ď'=>'ď','Ť'=>'ť','Ň'=>'ň']);
        return strtolower(preg_replace('/\s+/u',' ',$value));
    }
    private static function suffix(string $name,string $model,string $url=''):?string
    {
        // Only spacing in an otherwise identical model may differ: D890 and D 890.
        $name=self::normalize($name);$model=self::normalize($model);
        // Gender can move within a title, but must never disappear or change.
        $name=self::gender($name,$url);$model=self::gender($model,$url);
        if(str_replace(' ','',$name)===str_replace(' ','',$model))return '';
        if($model==='')return null;
        $pattern=str_replace(' ','\\s*',preg_quote($model,'~'));
        // A word boundary also permits catalog titles such as "Packing Cube M".
        return preg_match('~^'.$pattern.'(?:\s+[–—−-]\s+|\s+)(.+)$~uD',$name,$m)?$m[1]:null;
    }
    private static function gender(string $name,string $url):string
    {
        $gender='';
        foreach(['women'=>"women's|dámské|dámská|dámský",'men'=>"men's|pánské|pánská|pánský"]as$key=>$pattern){
            if(preg_match('~(?:^|\s)(?:'.$pattern.')(?=\s|$)~u',$name)){
                if($gender!=='')return $name;
                $gender=$key;$name=preg_replace('~(?:^|\s)(?:'.$pattern.')(?=\s|$)~u',' ',$name);
            }
        }
        $path=(string)parse_url($url,PHP_URL_PATH);
        if($gender==='')foreach(['women','men']as$key)if(str_contains($path,'-'.$key.'s-')||str_ends_with($path,'-'.$key.'s'))$gender=$key;
        $name=trim(preg_replace('/\s+/u',' ',$name));$name=preg_replace('/\s+[–—−-]$/u','',$name);
        return ($gender!==''?$gender.' ':'').$name;
    }
    private static function size(string $value):bool
    {return (bool)preg_match('/^(?:[2-5]?x{0,3}[sml]|small|medium|large|short|regular|long|tall|wide|long[ \/]wide|regular[ \/](?:regular|wide|short|mummy)|\d+(?:\s*[x×]\s*\d+)?(?:\.\d+)?\s*(?:cm|l|ml))$/uD',self::normalize($value));}
    private static function sizeValue(string $value):string
    {
        $value=self::normalize($value);$value=preg_replace('/\s*[x×]\s*/u','x',$value);
        $value=str_replace('/',' ',$value);
        return ['small'=>'s','medium'=>'m','large'=>'l'][$value]??preg_replace('/(?<=\d)\s+(?=cm$|ml$|l$)/','',$value);
    }
    private static function zip(string $value):?string
    {return match(self::normalize($value)){'levá strana','levý','levá','left'=>'left','pravá strana','pravý','pravá','right'=>'right',default=>null};}
    private static function color(string $value):string
    {
        $value=self::normalize($value);
        if(preg_match('/^(.+?)\s*\(([^()]+)\)$/uD',$value,$parts)&&self::color($parts[1])===self::color($parts[2]))$value=self::color($parts[1]);
        $value=str_replace('hi-vis','hi vis',$value);
        if(str_contains($value,'/'))return implode('/',array_map([self::class,'color'],explode('/',$value)));
        return ['červená'=>'red','modrá'=>'blue','zelená'=>'green','žlutá'=>'yellow','černá'=>'black','bílá'=>'white',
            'šedá'=>'grey','gray'=>'grey','stříbrná'=>'silver','oranžová'=>'orange','fialová'=>'purple','růžová'=>'pink',
            'hnědá'=>'brown','antracitová'=>'anthracite'][$value]??$value;
    }
    private static function sourceDimensions(string $tail):?array
    {
        if($tail==='')return null;
        $parts=array_map('trim',explode(',',$tail));$dimensions=[];
        foreach($parts as$part){
            if($part==='')return null;
            if(self::size($part)){$key='size';$value=self::sizeValue($part);}
            elseif(($zip=self::zip($part))!==null){$key='zip';$value=$zip;}
            else{$key='color';$value=self::color($part);}
            if(isset($dimensions[$key]))return null;
            $dimensions[$key]=$value;
        }
        return $dimensions;
    }
    private static function constraints(string $tail):?array
    {
        if($tail==='')return [];
        $result=[];
        if(self::size($tail))return ['size'=>[self::sizeValue($tail)]];
        if(preg_match('/^((?:\d+(?:\.\d+)?\s*(?:cm|ml|l))|\S+)(?:\s+|$)/u',$tail,$m)&&self::size($m[1])){
            $result['size']=[self::sizeValue($m[1])];$tail=trim(substr($tail,strlen($m[0])));
        }
        if($tail!==''){
            $colors=array_map([self::class,'color'],array_map('trim',explode(',',$tail)));
            if(in_array('',$colors,true))return null;
            $result['color']=$colors;
        }
        return $result;
    }
}
