<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Shared identity vocabulary. Normalization never removes model or variant evidence. */
final class VariantAttributes
{
    public static function text(string $value): string
    {
        $value=preg_replace('/\s+/u',' ',trim(str_replace('\\/','/',$value)));
        return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower(strtr($value,['Č'=>'č','Š'=>'š','Ž'=>'ž','Ř'=>'ř','Ý'=>'ý','Á'=>'á','Í'=>'í','É'=>'é','Ě'=>'ě','Ů'=>'ů','Ú'=>'ú','Ď'=>'ď','Ť'=>'ť','Ň'=>'ň']));
    }
    public static function value(string $dimension,string $value): string
    {
        $value=self::text($value);
        if($dimension==='color'){
            $value=str_replace('hi-vis','hi vis',$value);
            $colors=['černá'=>'black','bílá'=>'white','červená'=>'red','modrá'=>'blue','zelená'=>'green','žlutá'=>'yellow',
                'oranžová'=>'orange','šedá'=>'grey','gray'=>'grey','stříbrná'=>'silver','fialová'=>'purple','růžová'=>'pink','hnědá'=>'brown',
                'antracitová'=>'anthracite','béžová'=>'beige','limetková'=>'lime','světle modrá'=>'light blue','světle blue'=>'light blue','tmavě zelená'=>'dark green','tmavě green'=>'dark green',
                'olivově zelená'=>'olive green','olivově green'=>'olive green','šedozelená'=>'grey green','šedogreen'=>'grey green','navy blue'=>'navy'];
            if(str_contains($value,'/'))return implode(' ',array_map(static fn(string $part):string=>self::value('color',$part),explode('/',$value)));
            if(preg_match('/^(.+?)\s*\(([^()]+)\)$/uD',$value,$m)&&self::value('color',$m[1])===self::value('color',$m[2]))return self::value('color',$m[1]);
            if(isset($colors[$value]))return $colors[$value];
            // Preserve named shades and qualifiers while translating whole colour words.
            foreach($colors as$from=>$to)$value=preg_replace('~(?<![\pL])'.preg_quote($from,'~').'(?![\pL])~u',$to,$value);
            return $value;
        }
        if($dimension==='size'){
            $value=trim(preg_replace('~\s*/\s*~',' ',preg_replace('/\s*eu$/','',$value)));
            $value=preg_replace('/\s*[x×]\s*/u','x',$value);
            $value=preg_replace('/(?<=\d)\s+(?=cm$|ml$|l$)/','',$value);
            return ['small'=>'s','medium'=>'m','large'=>'l'][$value]??$value;
        }
        if($dimension==='zip')return match($value){'levá strana','levý','levá','levý (l)','left'=>'left','pravá strana','pravý','pravá','pravý (r)','right'=>'right',default=>$value};
        if($dimension==='capacity')return str_replace(' ','',$value);
        return $value;
    }
    public static function normalize(array $values): ?array
    {
        $out=[];
        foreach($values as$key=>$value){
            if(!is_scalar($value))return null;
            $key=self::text((string)$key);$value=(string)$value;
            if(in_array($key,['barva a velikost','velikost a barva'],true)){
                $pattern=$key==='barva a velikost'?'~^(.+)\s+(?:/|[–—-])\s+(.+)$~uD':'~^(.+?)\s+(?:/|[–—-])\s+(.+)$~uD';
                if(!preg_match($pattern,trim(str_replace('\\/','/',$value)),$m))return null;
                $pair=$key==='barva a velikost'?['color'=>$m[1],'size'=>$m[2]]:['size'=>$m[1],'color'=>$m[2]];
                foreach($pair as$dimension=>$part){if(isset($out[$dimension]))return null;$out[$dimension]=self::value($dimension,$part);}
                continue;
            }
            $dimension=match($key){
                'barva','barva (vzor)','color','colour'=>'color',
                'velikost','velikost produktu','velikost karimatky','výška postavy','size','objem','rozměr','velikost a bederní pás'=>'size',
                'kapacita','kapacita baterie','capacity'=>'capacity','zip','strana zipu','pozice zipu'=>'zip',
                'verze','version'=>'version','příchuť','flavor'=>'flavor','varianta','variant'=>'variant',default=>null};
            if($dimension===null||isset($out[$dimension]))return null;
            $value=self::value($dimension,$value);if($value==='')return null;
            $out[$dimension]=$value;
        }
        return $out;
    }
    /** A generic local label must equal explicit supplier attributes, including every word. */
    public static function matches(array $wanted,array $actual): bool
    {
        foreach($wanted as$key=>$value){
            if($key!=='variant'){
                if(($actual[$key]??null)!==$value)return false;
                continue;
            }
            $found=false;
            foreach($actual as$dimension=>$part)if(self::value($dimension,$value)===$part)$found=true;
            foreach([['size','color'],['color','size']]as[$first,$second]){
                if(!isset($actual[$first],$actual[$second]))continue;
                // Match against the two explicitly known fields. Internal S/M or colour
                // slashes stay part of their field; they are never split into alternatives.
                foreach([' / ',' – ',' — ',' - ']as$separator){
                    $pos=strpos($value,$separator);
                    if($pos!==false&&self::value($first,substr($value,0,$pos))===$actual[$first]&&
                        self::value($second,substr($value,$pos+strlen($separator)))===$actual[$second])$found=true;
                }
            }
            if(!$found)return false;
        }
        return true;
    }
    public static function offer(array $offer,string $host): array
    {
        $actual=self::normalize($offer['attributes']??[])??[];
        if($host==='gramino.cz'&&($offer['group_name']??'')!==''&&
            str_starts_with($offer['product_name']??'',$offer['group_name'].' – ')){
            $tail=substr($offer['product_name'],strlen($offer['group_name'].' – '));
            // Explicit side in the SKU title supplements JSON-LD that only contains size/color.
            foreach(explode(',',$tail)as$part){$zip=self::value('zip',$part);if(in_array($zip,['left','right'],true)){
                if(isset($actual['zip'])&&$actual['zip']!==$zip)return [];$actual['zip']=$zip;
            }}
            if($actual===[])$actual['flavor']=self::value('flavor',$tail);
        }
        return $actual;
    }
}
