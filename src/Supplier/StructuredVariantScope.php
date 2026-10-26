<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Exact supplier attributes, scoped to the current model; prices always come from offers. */
final class StructuredVariantScope
{
    private static function lower(string $value):string
    {
        return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower(strtr($value,['Č'=>'č','Š'=>'š','Ž'=>'ž','Ř'=>'ř','Ý'=>'ý','Á'=>'á','Í'=>'í','É'=>'é','Ě'=>'ě','Ů'=>'ů','Ú'=>'ú','Ď'=>'ď','Ť'=>'ť','Ň'=>'ň']));
    }
    public static function attributes(array $values):?array
    {
        $out=[];
        foreach($values as$key=>$value){
            if(!is_scalar($value))return null;
            $key=self::lower(trim((string)$key));
            $dimension=match($key){
                'barva','barva (vzor)','color','colour'=>'color',
                'velikost','velikost produktu','velikost karimatky','výška postavy','size','objem','rozměr'=>'size',
                'kapacita','kapacita baterie'=>'capacity','zip','strana zipu','pozice zipu'=>'zip','verze'=>'version',default=>null};
            if($dimension===null)return null;
            $value=self::lower(preg_replace('/\s+/u',' ',trim(str_replace('\\/','/',(string)$value))));
            if($dimension==='color')$value=strtr($value,['černá'=>'black','bílá'=>'white','červená'=>'red','modrá'=>'blue','zelená'=>'green','žlutá'=>'yellow','oranžová'=>'orange','šedá'=>'grey','gray'=>'grey']);
            if($dimension==='color'&&$value==='navy blue')$value='navy';
            if($dimension==='size')$value=trim(preg_replace('~\s*/\s*~',' ',preg_replace('/\s*eu$/','',$value)));
            if($dimension==='capacity')$value=str_replace(' ','',$value);
            if($dimension==='zip')$value=match($value){'levá strana','levý','levá','levý (l)','left'=>'left','pravá strana','pravý','pravá','pravý (r)','right'=>'right',default=>$value};
            if($value===''||isset($out[$dimension]))return null;
            $out[$dimension]=$value;
        }
        return $out;
    }
    public static function productAttributes(array $product,string $body,string $url,bool $single):array
    {
        $values=[];
        foreach(['color','size']as$key)if(isset($product[$key])&&is_scalar($product[$key]))$values[$key]=$product[$key];
        // Gramino's documented markdown mirror exposes the specifications of a single SKU.
        if($single&&preg_match('~^https://(?:www\.)?gramino\.cz/p/~',$url)&&
            PriceParser::sameModel($product['url']??'',$url)){
            preg_match_all('/^## Specifikace\s*\n(.*?)(?=^## |\z)/ms',$body,$sections);
            foreach($sections[1]as$section){
                preg_match_all('/^- (Barva|Kapacita baterie|Velikost produktu|Strana zipu|Pozice zipu|Rozměr): ([^\r\n]+)$/mu',$section,$matches,PREG_SET_ORDER);
                foreach($matches as$m){
                    if(isset($values[$m[1]])&&$values[$m[1]]!==$m[2])return [];
                    $values[$m[1]]=$m[2];
                }
            }
        }
        if($single)$values+=Pod7kiloVariantIdentity::specifications($body,$url);
        return self::attributes($values)??[];
    }
    public static function select(array $document,string $url,array $snapshot):?array
    {
        $host=preg_replace('/^www\./','',strtolower((string)parse_url($url,PHP_URL_HOST)));
        if(!in_array($host,['gramino.cz','hudy.cz','pod7kilo.cz','4camping.cz'],true)||empty($document['single_offers']))return null;
        if(empty($snapshot['variantSettings']['enabled']))return null;
        $offers=$document['scoped_offers']??$document['offers']??[];$matched=[];$perVariant=[];
        foreach($snapshot['variants']??[]as$variant){
            if(empty($variant['enabled']))continue;
            $choices=json_decode($variant['choices_json']??'null',true);
            if(!is_array($choices)||!($wanted=self::attributes($choices)))return null;
            $wanted=SupplierCatalogAliases::variantAttributes($wanted,$url,$snapshot['products'][0]['name']??'',true);
            $found=[];
            foreach($offers as$i=>$offer){
                $actual=SupplierCatalogAliases::variantAttributes($offer['attributes']??[],$url,$snapshot['products'][0]['name']??'',false);
                if(!$actual||!self::sameSourceModel($url,$offer['url'],$host))continue;
                foreach($wanted as$key=>$value)if(($actual[$key]??null)!==$value)continue 2;
                $found[$i]=$offer;
            }
            if(!$found||count(array_unique(array_column($found,'amount')))!==1)return null;
            $matched+=$found;
            if(!empty($snapshot['variantPriceMode']['enabled'])){
                $offer=reset($found);$states=array_unique(array_column($found,'availability'));
                $perVariant[$variant['variant_key']]=['price'=>$offer['price'],'availability'=>count($states)===1?reset($states):'unknown','urls'=>array_column($found,'url')];
            }
        }
        if(!$matched)return null;
        $uniform=count(array_unique(array_column($matched,'amount')))===1;
        if(!$uniform&&!$perVariant)return null;
        $result=reset($matched);$result['uncertain']=false;$result['uniform_variants']=$uniform;
        $states=array_unique(array_column($matched,'availability'));$result['availability']=count($states)===1?reset($states):'unknown';
        $result['identity_evidence']=['method'=>'exact_structured_attributes','verified_url'=>$url,'matched_offers'=>array_values(array_map(static fn(array $o):array=>array_intersect_key($o,array_flip(['url','amount','attributes'])),$matched))];
        if(count($matched)===1)$result['corrected_url']=$result['url'];
        if($perVariant){$result['variant_offers']=$perVariant;$result['price']=min(array_column($perVariant,'price'));}
        return $result;
    }
    private static function sameSourceModel(string $model,string $variant,string $host):bool
    {
        try{
            if(preg_replace('/^www\./','',HttpClient::url($model))!==preg_replace('/^www\./','',HttpClient::url($variant)))return false;
        }catch(\InvalidArgumentException $e){return false;}
        $a=rtrim((string)parse_url($model,PHP_URL_PATH),'/');$b=rtrim((string)parse_url($variant,PHP_URL_PATH),'/');
        if($host==='pod7kilo.cz')return $a===$b||str_starts_with($b,$a.'/');
        return $a===$b;
    }
}
