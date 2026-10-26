<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Supplier\{VariantOfferPlan as Plan, VariantOfferEvaluation as Evaluation, PriceParser};
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $run):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe offer set accepted.');};
$url='https://gramino.cz/p/patizon-d-590';$now=1800000000;
$snapshot=['products'=>[['name'=>'Patizon D590']], 'sale'=>['partner_id'=>1,'variant_prices_json'=>'{}'],
 'variantSettings'=>['enabled'=>1], 'variantPriceMode'=>null,
 'partners'=>[['id'=>1,'partner_key'=>'p','name'=>'Gramino','website_url'=>'https://gramino.cz','priority'=>10]],
 'variants'=>[
  ['variant_key'=>'blue','enabled'=>1,'price_czk'=>null,'choices_json'=>'{"Barva":"Modrá","Velikost":"M"}'],
  ['variant_key'=>'red','enabled'=>1,'price_czk'=>null,'choices_json'=>'{"Barva":"Červená","Velikost":"M"}']],
 'variantLinks'=>[
  ['link_key'=>'old','variant_key'=>'blue','partner_key'=>'p','url'=>$url,'origin'=>'inherited','removed'=>0],
  ['link_key'=>'b','variant_key'=>'blue','partner_key'=>'p','url'=>$url.'?v_id=blue','origin'=>'manual','removed'=>0],
  ['link_key'=>'r','variant_key'=>'red','partner_key'=>'p','url'=>$url.'?v_id=red','origin'=>'discovered','removed'=>0]]];
$plan=Plan::candidates($snapshot);
$check(count($plan)===2 && $plan[0]['links'][0]['link_key']==='b','Specific links must replace inherited duplicates.');
$products=[];
foreach(['blue'=>1000,'red'=>1200] as $color=>$price)$products[]=['@type'=>'Product','name'=>'Patizon D 590 – '.ucfirst($color).', M','offers'=>['@type'=>'Offer','price'=>$price,'priceCurrency'=>'CZK','url'=>$url.'?v_id='.$color,'availability'=>'https://schema.org/InStock']];
$doc=PriceParser::document('<script type="application/ld+json">'.json_encode(['@type'=>'ProductGroup','name'=>'Patizon D 590','url'=>$url,'hasVariant'=>$products]).'</script>',$url);
$observations=[];
foreach($plan as $source){$out=Evaluation::observe($doc,$url,$snapshot,$source,$now);$check($out['issues']===[],'Exact mapped offer rejected.');$observations+=$out['observations'];}
$result=Evaluation::assemble($snapshot,$observations,1,$now,['pricing_enabled'=>1]);
$check($result['price']===1000 && $result['variant_offers']['red']['price']===1200 && $result['enable_variant_pricing'],'Variant amounts collapsed into one product price.');
$check($result['identity_evidence']['local_variants']===2,'Missing coverage evidence.');
$reject(fn()=>Evaluation::assemble($snapshot,[$observations['b']],1,$now,[]));
$reject(fn()=>Evaluation::assemble($snapshot,$observations,2,$now,[]));
$reject(fn()=>Evaluation::assemble($snapshot,$observations,1,$now+86400,[]));
$conflicting=$observations;$conflicting['duplicate']=array_replace($observations['r'],['amount'=>'1300.00']);
$reject(fn()=>Evaluation::assemble($snapshot,$conflicting,1,$now,[]));
$wrong=$plan[0];$wrong['url']=$url.'?v_id=red';
$check(Evaluation::observe($doc,$url.'?v_id=blue',$snapshot,$wrong,$now)['observations']===[],'A model cache repaired an explicitly wrong manual URL.');
$duplicate=$doc;$extra=$duplicate['offers'][0];$extra['url']=$url.'?v_id=another-blue';$duplicate['offers'][]=$extra;$duplicate['product_count']++;
$check(count(Evaluation::observe($duplicate,$url,$snapshot,$plan[0],$now)['observations'])===1,'Exact saved SKU lost priority to another SKU with the same attributes.');
$inherited=$plan[0];$inherited['links'][0]['origin']='inherited';
$check(Evaluation::observe($duplicate,$url,$snapshot,$inherited,$now)['observations']===[],'Inherited model URL silently selected one of two matching SKUs.');
$removed=$snapshot;foreach($removed['variantLinks'] as &$link)$link['removed']=1;unset($link);
$check(Plan::candidates($removed)===[],'Removed links fell back to legacy product sources.');
$legacy=$snapshot;$legacy['variantLinks']=[];$check(Plan::candidates($legacy)===null,'Unmigrated product lost legacy support.');
$disabled=$snapshot;$disabled['variants'][1]['enabled']=0;
$check(count(Plan::candidates($disabled))===1,'Disabled size entered price queue.');
echo "Variant offer planning: exact links, coverage, conflicts, freshness, merchant isolation and tombstones passed.\n";
