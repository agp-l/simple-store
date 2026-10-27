<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Explicit catalog translations, scoped to a verified supplier model. Never a price lookup. */
final class SupplierCatalogAliases
{
    public static function name(string $name,string $group,string $url,array $evidence=[]):string
    {
        $host=preg_replace('/^www\./','',strtolower((string)parse_url($url,PHP_URL_HOST)));
        if(in_array($host,['pod7kilo.cz','hudy.cz'],true)){
            // A category prefix is not part of the manufacturer's model name. Do not remove
            // brands, years, gender, capacities or any other suffix/word from the model.
            $prefix='/^(?:batoh(?:\/(?:taška|kabelka))?|(?:sbalitelný|kabinový|cestovatelský) batoh|spací pytel|bivakovací vak|spacák|ponožky|boty|přikrývka|karimatka|polštář)\s+/ui';
            $local=preg_replace($prefix,'',$name);$remote=preg_replace($prefix,'',$group);
            if(($local!==$name||$remote!==$group)&&PriceParser::sameProductName(['product_name'=>$remote],$local))return $group;
        }
        $path=rtrim((string)parse_url($url,PHP_URL_PATH),'/');
        $models=[
            '4camping.cz'=>[
                '/p/filtracni-lahev-katadyn-befree-ac-0-7-l-cyklo'=>['Katadyn BeFree AC Bottle 0,7 l','Filtrační láhev Katadyn BeFree AC 0,7 l cyklo'],
            ],
            'hudy.cz'=>[
                '/wide-mouth-flex-cap-32-oz'=>['Hydro Flask Wide Mouth Flex Cap 946 ml','32 OZ WIDE FLEX CAP'],
                '/wide-mouth-sustain-1000-ml'=>['Nalgene Wide Mouth Sustain 1 l','Wide Mouth Sustain 1000 ml'],
                '/boty-altra-timp-6-gtx-men'=>['Altra Timp 6 Gore-Tex','Timp 6 GTX Men'],
            ],
            'pod7kilo.cz'=>[
                '/p/polstar-nemo-fillo-elite'=>['NEMO Fillo Elite Luxury','Polštář NEMO Fillo Elite'],
                '/p/prikryvka-cumulus-roamer-150'=>['Cumulus Roamer 150 – M','Přikrývka Cumulus Roamer 150'],
                '/p/prikryvka-nemo-pulse-20-30-endless-promise-ul-quilt'=>['NEMO Pulse 20/30 Endless Promise UL Quilt – L','Přikrývka NEMO Pulse 20/30 Endless Promise UL Quilt'],
                '/p/detsky-spaci-pytel-cumulus-junior-300'=>['Cumulus Junior 300 – M','Dětský spací pytel Cumulus Junior 300'],
            ],
            'gramino.cz'=>[
                '/p/enlightened-equipment-enigma-quilt-40f-5c'=>['Enlightened Equipment Enigma Quilt 40°F – Long/Wide','Enlightened Equipment Enigma Quilt 40°F (5°C)'],
                '/p/cumulus-junior-300'=>['Cumulus Junior 300 – M','Cumulus Junior 300'],
            ],
        ];
        foreach($models[$host]??[]as$modelPath=>[$local,$remote]){
            if(($path===$modelPath||($host==='pod7kilo.cz'&&str_starts_with($path,$modelPath.'/')))&&
                PriceParser::sameProductName(['product_name'=>$name],$local)&&PriceParser::sameProductName(['product_name'=>$group],$remote))return $group;
        }
        if($host==='gramino.cz'&&preg_match('~^/p/big-agnes-(?:tiger-wall-ul[23]|copper-spur-ul[1-4](?:-xl)?)$~D',$path)&&
            in_array('HyperBead',$evidence['model_features']??[],true)&&PriceParser::sameProductName(['product_name'=>$name],$group.' HyperBead'))return $group;
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
    /** Verified supplier naming conventions; scoped aliases never merge arbitrary colours. */
    public static function variantAttributes(array $attributes,string $url,string $name,bool $local):array
    {
        $host=preg_replace('/^www\./','',strtolower((string)parse_url($url,PHP_URL_HOST)));
        $path=rtrim((string)parse_url($url,PHP_URL_PATH),'/');
        if($host==='pod7kilo.cz'&&($path==='/p/polstar-nemo-fillo-elite'||str_starts_with($path,'/p/polstar-nemo-fillo-elite/'))&&
            $local&&PriceParser::sameProductName(['product_name'=>$name],'NEMO Fillo Elite Luxury'))$attributes['version']='rozšířený';
        // Pod7kilo spells out Cumulus' height bands as well as the manufacturer's
        // size letter. This is a closed size table, never a generic suffix removal.
        if($host==='pod7kilo.cz'&&preg_match('~^/p/spaci-pytel-cumulus-~',$path)&&isset($attributes['size'])){
            $sizes=['do 170 cm - zkrácený (s)'=>'s','do 183 cm - standardní (m)'=>'m','do 196 cm - prodloužený (l)'=>'l'];
            $attributes['size']=$sizes[$attributes['size']]??$attributes['size'];
        }
        if(!$local&&isset($attributes['color'])){
            $maps=[
                'hudy.cz'=>[
                    '/wide-mouth-flex-cap-32-oz'=>['374 agave'=>'agave'],
                    '/wide-mouth-sustain-1000-ml'=>['slate blue sustain/2020-1732'=>'slate blue'],
                    '/boty-altra-timp-6-gtx-men'=>['black/black'=>'black','88m iguana/silver/tarmac'=>'green'],
                ],
                'gramino.cz'=>[
                    '/p/big-agnes-tiger-wall-ul2'=>['grey / green'=>'green'],
                    '/p/big-agnes-tiger-wall-ul3'=>['grey / green'=>'green'],
                ],
            ];
            foreach($maps[$host][$path]??[]as$from=>$to)if(VariantAttributes::value('color',$from)===$attributes['color']){
                $attributes['color']=VariantAttributes::value('color',$to);break;
            }
        }
        return $attributes;
    }

}
