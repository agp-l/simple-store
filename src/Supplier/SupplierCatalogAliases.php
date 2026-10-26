<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Explicit catalog translations, scoped to a verified supplier model. Never a price lookup. */
final class SupplierCatalogAliases
{
    public static function name(string $name,string $group,string $url):string
    {
        $host=preg_replace('/^www\./','',strtolower((string)parse_url($url,PHP_URL_HOST)));
        if(in_array($host,['pod7kilo.cz','hudy.cz'],true)){
            // A category prefix is not part of the manufacturer's model name. Do not remove
            // brands, years, gender, capacities or any other suffix/word from the model.
            $prefix='/^(?:batoh|spacák|ponožky|boty|přikrývka|karimatka|polštář)\s+/ui';
            $local=preg_replace($prefix,'',$name);$remote=preg_replace($prefix,'',$group);
            if(($local!==$name||$remote!==$group)&&PriceParser::sameProductName(['product_name'=>$remote],$local))return $group;
        }
        $path=rtrim((string)parse_url($url,PHP_URL_PATH),'/');
        // Size tables: https://gramino.cz/p/nemo-tensor-elite,
        // /p/nemo-tensor-all-season, /p/therm-a-rest-neoair-xlite-nxt.
        $sizes=[
            '/p/nemo-tensor-elite'=>['NEMO Tensor Elite',[
                '51 x 160 - zkrácená'=>'Short','51 x 183 cm - standard'=>'Regular','64 x 183 cm - širší'=>'Regular Wide']],
            '/p/nemo-tensor-all-season'=>['NEMO Tensor All-Season',[
                '51 x 183 cm - standard'=>'Regular','51 x 183 cm - mumie'=>'Regular Mummy',
                '64 x 183 cm - širší'=>'Regular Wide','64 x 193 cm - delší, širší'=>'Long Wide']],
            '/p/therm-a-rest-neoair-xlite-nxt'=>['Therm-a-Rest NeoAir XLite NXT',[
                '51 x 168 - zkrácená'=>'Regular Short','51 x 183 cm - standard'=>'Regular',
                '64 x 183 cm - rozšířená'=>'Regular Wide','64 x 196 cm - delší, širší'=>'Large']],
        ];
        if(isset($sizes[$path])){
            [$model,$map]=$sizes[$path];
            if(PriceParser::sameProductName(['product_name'=>$group],$model)){
                foreach($map as$local=>$remote)if(PriceParser::sameProductName(['product_name'=>$name],$model.' — '.$local))return $group.' – '.$remote;
            }
        }
        $aliases=[
            '/p/eva-foam-pad-3-mm'=>['Tenká pěnová podložka 3 mm','EVA Foam Pad 3 mm'],
            '/p/big-agnes-fly-creek-ul-25-sleeping-bag'=>['Big Agnes Fly Creek UL 25°','Big Agnes Fly Creek UL 25 Sleeping Bag'],
            '/p/sea-to-summit-quilt-ember-1'=>['Sea to Summit Ember I','Sea To Summit Quilt Ember I (10 °C)'],
            '/p/enlightened-equipment-enigma-quilt-40f-5c'=>['Enlightened Equipment Enigma Quilt 40°F','Enlightened Equipment Enigma Quilt 40°F (5°C)'],
        ];
        if(isset($aliases[$path])){
            [$local,$remote]=$aliases[$path];
            if(PriceParser::sameProductName(['product_name'=>$group],$remote)&&preg_match('~^'.preg_quote($local,'~').'\s+[–—-]\s+(.+)$~uiD',$name,$match))return $group.' – '.$match[1];
        }
        if($path==='/p/sea-to-summit-frontier-ul-collapsible-bowl'&&
            PriceParser::sameProductName(['product_name'=>$group],'Sea To Summit Frontier UL Collapsible Bowl')){
            foreach(['M'=>'680 ml','L'=>'890 ml']as$size=>$volume)
                if(PriceParser::sameProductName(['product_name'=>$name],$group.' '.$size.' – '.$volume))return $group.' – '.$size;
        }
        return $name;
    }
}
