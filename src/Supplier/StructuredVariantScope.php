<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Exact supplier attributes, scoped to the current model; prices always come from offers. */
final class StructuredVariantScope
{
    public static function attributes(array $values):?array
    {
        return VariantAttributes::normalize($values);
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
        $offers=$document['scoped_offers']??$document['offers']??[];$matched=[];$perVariant=[];$stockOffers=[];
        foreach($snapshot['variants']??[]as$variant){
            if(empty($variant['enabled']))continue;
            $choices=json_decode($variant['choices_json']??'null',true);
            if(!is_array($choices)||!($wanted=self::attributes($choices)))return null;
            $wanted=SupplierCatalogAliases::variantAttributes($wanted,$url,$snapshot['products'][0]['name']??'',true);
            $found=[];
            foreach($offers as$i=>$offer){
                $actual=SupplierCatalogAliases::variantAttributes(VariantAttributes::offer($offer,$host),$url,$snapshot['products'][0]['name']??'',false);
                if(!$actual||!self::sameSourceModel($url,$offer['url'],$host))continue;
                if(!VariantAttributes::matches($wanted,$actual))continue;
                $found[$i]=$offer;
            }
            if(!$found||count(array_unique(array_column($found,'amount')))!==1)return null;
            $matched+=$found;
            if(isset($variant['variant_key']))$stockOffers[$variant['variant_key']]=PartnerStockPolicy::evidence($found);
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
        $result=array_replace($result,PartnerStockPolicy::evidence($matched));
        if(!empty($stockOffers))$result['canonical_variant_offers']=$stockOffers;
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
