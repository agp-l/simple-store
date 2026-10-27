<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Supplier\{VariantLinkMatcher as Matcher, PriceParser};
$check = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$url = 'https://gramino.cz/p/patizon-d-590';
$offer = static fn(string $id, string $name): array => ['@type'=>'Product', 'name'=>$name,
    'offers'=>['@type'=>'Offer', 'price'=>'10000', 'priceCurrency'=>'CZK', 'url'=>$url.'?v_id='.$id, 'availability'=>'https://schema.org/InStock']];
$group = ['@type'=>'ProductGroup', 'name'=>'Patizon D 590', 'url'=>$url, 'hasVariant'=>[
    $offer('blue-m','Patizon D 590 – Blue, M'), $offer('red-m','Patizon D 590 – Red, M'), $offer('blue-l','Patizon D 590 – Blue, L')]];
$document = static fn(array $group): array => PriceParser::document('<script type="application/ld+json">'.json_encode($group).'</script>', $url);
$variant = ['variant_key'=>str_repeat('a',32), 'enabled'=>1, 'choices_json'=>'{"Barva":"Modrá","Velikost":"M"}'];
$match = Matcher::match($document($group),$url.'?v_id=red-m','Patizon D590',$variant);
$check($match['status']==='exact' && $match['url']===$url.'?v_id=blue-m','Inherited URL did not resolve to the right colour/size.');
$cached = $document($group); $cached['parser_version'] = 3;
$check(Matcher::match($cached,$url,'Patizon D590',$variant)['status']==='exact','Version 3 explicit ProductGroup offers were rejected.');
$variant['choices_json']='{"Velikost":"M"}';
$check(Matcher::match($document($group),$url,'Patizon D590',$variant)['status']==='ambiguous','Equal prices hid an ambiguous colour.');
$variant['choices_json']='{"Barva":"Modrá","Velikost":"S"}';
$check(Matcher::match($document($group),$url,'Patizon D590',$variant)['status']==='no_match','Missing size was invented.');
$variant['choices_json']='{"Barva":"Modrá","Velikost":"M"}';
$check(Matcher::match($document($group),$url,'Patizon D890',$variant)['status']==='no_match','Different model accepted.');
$group['hasVariant'][2]['offers']['url']=$url.'?v_id=blue-m';
$check(Matcher::match($document($group),$url,'Patizon D590',$variant)['status']==='ambiguous','One URL for two different sizes accepted.');
$group['hasVariant'][2]['offers']['url']='https://evil.test/p?size=M';
$check(Matcher::match($document($group),$url,'Patizon D590',$variant)['status']!=='exact','Cross-domain offer accepted.');
// A real structured Pod7kilo group carries explicit names, attributes and URLs.
$f=json_decode(file_get_contents(__DIR__.'/fixtures/supplier-pod-scope-923.json'),true);
$offers=SimpleStore\Supplier\Pod7kiloVariantIdentity::offers('<script>var data = '.json_encode($f['data']).';</script>',$f['url'],$f['offer']);
$doc=['parser_version'=>PriceParser::VERSION,'single_offers'=>true,'scoped_offers'=>$offers];
$v=$f['variants'][0];$v['variant_key']=str_repeat('b',32);
$m=Matcher::match($doc,$f['url'],$f['offer']['product_name'],$v);
$check($m['status']==='exact' && str_starts_with($m['url'],'https://www.pod7kilo.cz/p/'),'Explicit Pod7kilo variant failed.');
$check(Matcher::match($doc,$f['url'],'Gossamer Gear Vagabond Jet',$v)['status']==='exact','Supplier category prefix prevented an otherwise exact model match.');
$check(Matcher::match($doc,$f['url'],'Vagabond Jet',$v)['status']==='no_match','Category normalization removed a manufacturer.');
$check(Matcher::match($doc,$f['url'],'Gossamer Gear Vagabond Jet 2026',$v)['status']==='no_match','Category normalization removed the model year.');
$check(Matcher::match($doc,$f['url'],'Unrelated model',$v)['status']==='no_match','Structured attributes bypassed model check.');
$doc['parser_version']=0;
$check(Matcher::match($doc,$f['url'],$f['offer']['product_name'],$v)['status']==='old_parser','Legacy document treated as current evidence.');
echo "Variant links: exact colour/size, equal-price ambiguity, missing variants, model identity and URL scope passed.\n";

// Legacy local labels and JSON-LD axes are two representations of the same identity.
use SimpleStore\Supplier\VariantAttributes as Attributes;
foreach([
 ['Barva a velikost'=>'Černá / S/M'],['Velikost a barva'=>'S/M – černá'],['Varianta'=>'S/M / černá']
]as$choices){
 $wanted=Attributes::normalize($choices);
 $check($wanted!==null&&Attributes::matches($wanted,['color'=>'black','size'=>'s m']),'Compound label or internal size slash lost.');
 $check(!Attributes::matches($wanted,['color'=>'black','size'=>'m l']),'Different combined size accepted.');
 $check(!Attributes::matches($wanted,['color'=>'dark black','size'=>'s m']),'Named shade qualifier removed.');
}
$check(Attributes::normalize(['Barva a velikost'=>'black / M','Velikost'=>'L'])===null,'Conflicting duplicate axis ignored.');
$check(Attributes::normalize(['Material'=>'Ultra 200X'])===null,'Unknown local dimension ignored.');
$check(Attributes::value('color','Hi-vis žlutá')==='hi vis yellow','Existing high-visibility colour normalization regressed.');
$check(Attributes::value('color','Black Sand (tmavě green)')==='black sand (dark green)','Partly normalized legacy colour not repaired.');
$check(Attributes::value('color','Modrá (blue)')==='blue','Equivalent translated colour repeated.');
$check(Attributes::value('color','Jasper Pro (green)')!=='green','Named fabric/colour qualifier discarded.');
$check(PriceParser::sameProductName(['product_name'=>'Backpack 50 L (2026)'],'Backpack 50L 2026'),'Units and year punctuation failed.');
$check(!PriceParser::sameProductName(['product_name'=>'Backpack 50 L (2025)'],'Backpack 50L 2026'),'Model year discarded.');
$url='https://gramino.cz/p/cumulus-mysterious-traveller-500';
$group=['@type'=>'ProductGroup','name'=>'Cumulus Mysterious Traveller 500','url'=>$url,'hasVariant'=>[
 ['@type'=>'Product','name'=>'Cumulus Mysterious Traveller 500 – Levá strana','color'=>'Crystal Wave','size'=>'M',
 'offers'=>['@type'=>'Offer','url'=>$url.'?v_id=left','price'=>10000,'priceCurrency'=>'CZK']]]];
$v=['variant_key'=>str_repeat('c',32),'enabled'=>1,'choices_json'=>'{"Zip":"Levá strana"}'];
$doc=PriceParser::document('<script type="application/ld+json">'.json_encode($group).'</script>',$url);
$check(Matcher::match($doc,$url,'Cumulus Mysterious Traveller 500 – M',$v)['status']==='exact','Size omitted from title but explicit in JSON-LD lost.');
$check(Matcher::match($doc,$url,'Cumulus Mysterious Traveller 500 – L',$v)['status']==='no_match','Title size ignored.');
$doc['offers'][0]['attributes']['zip']='right';
$check(Matcher::match($doc,$url,'Cumulus Mysterious Traveller 500 – M',$v)['status']==='no_match','Conflicting title and typed zipper side accepted.');
echo "Variant links: composite labels, typed axes, translated colours and conflicting evidence passed.\n";
$labels=json_decode(file_get_contents(__DIR__.'/fixtures/supplier-label-translations.json'),true,64,JSON_THROW_ON_ERROR);
foreach($labels as$case){
 foreach($case['choices']as$choices){
  $v=['variant_key'=>str_repeat('e',32),'enabled'=>1,'choices_json'=>json_encode($choices)];
  $check(Matcher::match($case['document'],$case['source'],$case['name'],$v)['status']==='exact','Verified catalog convention failed: '.$case['name'].' '.json_encode($choices));
  $check(Matcher::match($case['document'],$case['source'],$case['name'].' 2030',$v)['status']!=='exact','Translation discarded a new model year.');
 }
 if($case['name']==='Aevor Trip Pack Proof'){
  $doc=$case['document'];foreach($doc['offers']as&$o){$o['attributes']['color']='tropical lily';$o['product_name']=$o['group_name'].' Tropical Lily';}unset($o);
  $v['choices_json']='{"Barva":"tropical lily"}';
  $check(Matcher::match($doc,$case['source'],$case['name'],$v)['status']!=='exact','Proof construction accepted without variant evidence.');
 }
 if(str_contains($case['name'],'HyperBead')){
  $doc=$case['document'];foreach($doc['offers']as&$o)$o['model_features']=[];unset($o);
  $check(Matcher::match($doc,$case['source'],$case['name'],$v)['status']!=='exact','Tangelo alias erased HyperBead construction evidence.');
 }
}
echo "Variant links: supplier catalog conventions preserve model years and construction evidence.\n";
