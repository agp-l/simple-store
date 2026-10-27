<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Explain rejected identity evidence without relaxing any matching rule. */
final class VariantIdentityDiagnostic
{
    public static function message(array $document, string $url, string $name, array $choices): string
    {
        $wanted=VariantAttributes::normalize($choices);
        if($wanted===null)return 'Vlastnosti varianty nelze jednoznačně rozdělit na barvu, velikost nebo jiné podporované údaje. Uprav názvy vlastností.';
        $offers=$document['scoped_offers']??$document['offers']??[];
        if($offers===[])return 'Partner neposkytl žádnou strukturovanou nabídku varianty.';
        $host=preg_replace('/^www\./','',strtolower((string)parse_url($url,PHP_URL_HOST)));
        $wanted=SupplierCatalogAliases::variantAttributes($wanted,$url,$name,true);
        $labels=['color'=>'barva','size'=>'velikost','zip'=>'strana zipu','capacity'=>'kapacita','version'=>'verze','flavor'=>'příchuť','variant'=>'varianta'];
        $actual=[];
        foreach($offers as$offer)foreach(SupplierCatalogAliases::variantAttributes(VariantAttributes::offer($offer,$host),$url,$name,false)as$key=>$value)$actual[$key][$value]=true;
        $differences=[];
        foreach($wanted as$key=>$value){
            if($key==='variant')continue;
            if(!isset($actual[$key]))$differences[]=($labels[$key]??$key).': partner tento údaj neposkytl';
            elseif(!isset($actual[$key][$value])){
                $values=array_keys($actual[$key]);
                $differences[]=($labels[$key]??$key).' „'.$value.'“; partner uvádí '.implode(', ',array_slice($values,0,4)).(count($values)>4?'…':'');
            }
        }
        if($differences!==[])return 'Vlastnosti nabídky se neshodují: '.implode('; ',array_slice($differences,0,3)).'.';
        return 'Partner nepotvrdil přesný model a kombinaci vlastností v uloženém odkazu. Zkontroluj také rok, materiál a variantu odkazu.';
    }
}
