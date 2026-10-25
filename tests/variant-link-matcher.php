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
$check(Matcher::match($doc,$f['url'],'Unrelated model',$v)['status']==='no_match','Structured attributes bypassed model check.');
$doc['parser_version']=0;
$check(Matcher::match($doc,$f['url'],$f['offer']['product_name'],$v)['status']==='old_parser','Legacy document treated as current evidence.');
echo "Variant links: exact colour/size, equal-price ambiguity, missing variants, model identity and URL scope passed.\n";
