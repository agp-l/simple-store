<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Supplier\{PriceParser as Parser,Robots,HttpClient as Http,PriceManager as Manager};
use SimpleStore\Navigation\PanelUrl;
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $run):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Ambiguous/unsafe input accepted.');};
$wrap=static fn(array $node):string=>'<script type="application/ld+json">'.json_encode($node).'</script>';
$offer=static fn(string $url,mixed $price=1000,string $currency='CZK'):array=>['@type'=>'Product','offers'=>['@type'=>'Offer','price'=>$price,'priceCurrency'=>$currency,'url'=>$url,'availability'=>'https://schema.org/InStock']];
$url='https://a.test/p';
$p=$offer($url);$p['isSimilarTo']=[$offer('https://a.test/other',1)];
$check(Parser::read($wrap($p),$url)['price']===1000,'Recommendation contaminated main price.');
$check(Parser::read($wrap($offer($url,'1000.01')),$url)['price']===1001,'Whole-CZK cost must round up.');
foreach([0,-10,'1 000','1,000','1e3',10000000,[],null]as$value)$check(Parser::amount($value)===null,'Bad amount accepted.');
foreach(['EUR','USD']as$currency)$reject(fn()=>Parser::read($wrap($offer($url,1000,$currency)),$url));
$reject(fn()=>Parser::read('<p>Akce 100 Kč; stará cena 999 Kč</p>',$url));
$reject(fn()=>Parser::read($wrap(['@type'=>'Product','offers'=>['@type'=>'AggregateOffer','lowPrice'=>100,'priceCurrency'=>'CZK']]),$url));
$group=['@context'=>'https://schema.org','@type'=>'ProductGroup','hasVariant'=>[$offer($url.'?v_id=small',100),$offer($url.'?v_id=large',200)]];
$check(Parser::read($wrap($group),$url.'?v_id=large')['price']===200,'Specific variant not selected.');
$check(Parser::read("```json\n".json_encode($group)."\n```",$url.'?v_id=large')['price']===200,'Markdown structured offer failed.');
$reject(fn()=>Parser::read($wrap($group),$url));
$check(Parser::read($wrap($offer($url)),$url.'?variant=large')['uncertain'],'Unmatched variant must require review.');
$p=$offer($url);$p['offers']['validForMemberTier']='club';$reject(fn()=>Parser::read($wrap($p),$url));
$p=$offer($url);$p['offers']['priceValidUntil']='2000-01-01';$reject(fn()=>Parser::read($wrap($p),$url));
$reject(fn()=>Parser::read($wrap([$offer($url,100),$offer($url,200)]),$url));
$p=$offer($url);$p['offers']['availability']='https://schema.org/OutOfStock';$check(Parser::read($wrap($p),$url)['availability']==='out_of_stock','Availability failed.');
foreach(['http://a.test/p','https://127.0.0.1/x','https://localhost/x','https://a.test:444/p','https://u:p@a.test/p','https://[::1]/','https://a.test/p#fragment','https://a.test\\@b.test/']as$bad)$reject(fn()=>Http::url($bad));
foreach(['127.0.0.1','10.0.0.1','169.254.169.254','192.168.1.1','172.16.0.1','100.64.0.1','198.18.0.1','224.0.0.1','::1','::ffff:127.0.0.1','fc00::1','fe80::1','2001:db8::1','2002:7f00:1::1']as$ip)$check(!Http::publicIp($ip),'Private/reserved address allowed: '.$ip);
$check(Http::publicIp('8.8.8.8')&&Http::publicIp('2606:4700:4700::1111'),'Public DNS address rejected.');
$check(Http::redirect($url,'/new')==='https://a.test/new','Relative redirect failed.');
$reject(fn()=>Http::redirect($url,'https://b.test/p'));$reject(fn()=>Http::redirect($url,'//b.test/p'));
$rules="User-agent: BlockedBot\nDisallow: /\nUser-agent: *\nDisallow: /private\nAllow: /private/public\nDisallow: /*?*do=\nCrawl-delay: 75\n";
$check(!Robots::policy($rules,'https://a.test/private/1')['allowed'],'Robots disallow ignored.');
$check(Robots::policy($rules,'https://a.test/private/public')['allowed'],'Longest allow ignored.');
$check(!Robots::policy($rules,'https://a.test/p?do=x')['allowed'],'Robots wildcard query ignored.');
$check(Robots::policy($rules,$url)['delay']===75,'Crawl delay ignored.');
$check(!Robots::policy("User-agent: *\nAllow: /\nUser-agent: DobrodruziSupplierCheck\nDisallow: /",$url)['allowed'],'Specific robot group ignored.');
$partners=[['id'=>1,'website_url'=>'https://a.test','priority'=>100,'name'=>'A'],['id'=>2,'website_url'=>'https://b.test','priority'=>10,'name'=>'B']];
$links=[['id'=>1,'url'=>'https://b.test/p'],['id'=>2,'url'=>$url],['id'=>3,'url'=>'https://a.test.evil.test/p']];
$items=Manager::candidates($links,$partners,1);$check(array_column($items,'partner_id')===[1,2],'Selected seller must precede priority; suffix host must not match.');
$partners[]=['id'=>3,'website_url'=>'https://www.a.test','priority'=>1,'name'=>'Duplicate'];$check(count(Manager::candidates($links,$partners,1))===1,'Ambiguous host mapped to arbitrary seller.');
// Regression: Pod 7 kilo's selected XL is 1099 CZK; its Offer points to the parent model.
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/pod7kilo-variant.json'),true,32,JSON_THROW_ON_ERROR);
$variantPage=static function(array $f)use($wrap):string{return '<html><head><link href="'.htmlspecialchars($f['url'],ENT_QUOTES).'" rel="canonical">'.
    '<script>//<!--' . "\nvar data = ".json_encode($f['data']).'; //-->'."\n</script>".$wrap($f['product']).'</head></html>';};
$parsed=Parser::read($variantPage($fixture),$fixture['url']);
$check(!$parsed['uncertain']&&$parsed['price']===1099&&$parsed['identity_evidence']['variant_id']==='7475','Selected XL with matching EAN, MPN, price and canonical was not recognized.');
$check(Manager::reviewReason(['variantSettings'=>['enabled'=>0],'sale'=>['supplier_price_czk'=>null]],$parsed+['fallback'=>false])==='','Verified single variant still needs URL review.');
$mutations=[
 static function(array &$f):void{$f['data']['selectedVariantId']='7472';},
 static function(array &$f):void{$f['data']['variantsInfo']['7475']['ean']='8051411206163';},
 static function(array &$f):void{$f['data']['variantsInfo']['7475']['code']='930319MNN';},
 static function(array &$f):void{$f['data']['variantsInfo']['7475']['price']=949;},
 static function(array &$f):void{$f['data']['variantsInfo']['7475']['price']='1099.01';$f['product']['offers']['price']='1099.99';},
 static function(array &$f):void{$f['data']['currency']='EUR';},
 static function(array &$f):void{$f['data']['variantsInfo']['7475']['url']=$f['data']['variantsInfo']['7472']['url'];},
 static function(array &$f):void{$f['data']['variantsInfo']['7475']['url']='https://elsewhere.test/p/xl';},
 static function(array &$f):void{$f['data']['variantsInfo']['7475']['productId']=999;},
 static function(array &$f):void{unset($f['product']['gtin13']);},
 static function(array &$f):void{$f['data']['variantsUrlEnabled']=false;},
 static function(array &$f):void{$f['url']=$f['data']['variantsInfo']['7472']['url'];},
];
foreach($mutations as $mutate){$f=$fixture;$mutate($f);$check(Parser::read($variantPage($f),$fixture['url'])['uncertain'],'Conflicting/missing variant evidence was accepted.');}
$f=$fixture;$f['url']=str_replace('www.pod7kilo.cz','another.test',$f['url']);
$check(Parser::read($variantPage($f),$f['url'])['uncertain'],'Site-specific identity rule leaked to another shop.');
$reject(fn()=>Parser::read($variantPage($fixture).$wrap($offer('https://www.pod7kilo.cz/recommended',1)),$fixture['url']));
$check(PanelUrl::pretty('/admin.php?section=supplier-prices')==='/admin/supplier-prices/','Route missing.');
$check(SimpleStore\Admin\AdminActionPolicy::allows('supplier-price-tick'),'Action missing.');
echo "Supplier prices: explicit offers, variants, currencies, conditions, robots, SSRF rules and ordering passed.\n";

$pricing=SimpleStore\Supplier\PricingPolicy::DEFAULTS;
foreach([30=>100,99=>200,1130=>1300,1210=>1400,1000=>1100]as$cost=>$retail)$check(SimpleStore\Supplier\PricingPolicy::price($cost,$pricing)===$retail,'Incorrect transparent rounding.');
$custom=SimpleStore\Supplier\PricingPolicy::settings(['pricing_enabled'=>'1','pricing_small_limit'=>'500','pricing_small_margin'=>'20','pricing_margin'=>'200','pricing_step'=>'50']);
$check(SimpleStore\Supplier\PricingPolicy::price(1210,$custom)===1450,'Custom pricing ignored.');
$reject(fn()=>SimpleStore\Supplier\PricingPolicy::settings(['pricing_step'=>'0']));
$reject(fn()=>SimpleStore\Supplier\PricingPolicy::price(9999999,$pricing));
foreach(['normal'=>[60,90],'fast'=>[15,23],'rapid'=>[1,2]]as$mode=>[$min,$max])for($i=0;$i<20;$i++){
 $delay=Manager::delay($mode);$check($delay>=$min&&$delay<=$max,'Jitter out of bounds.');
 $check(Manager::delay($mode,120)>=120,'Diagnostic speed ignored Crawl-delay.');
}
$group=['@type'=>'ProductGroup','url'=>'https://gramino.cz/p/mat','hasVariant'=>[
 $offer('https://gramino.cz/p/mat?v_id=small',3040)+['name'=>'Sea To Summit Mat – Small'],
 $offer('https://gramino.cz/p/mat?v_id=large',3110)+['name'=>'Sea To Summit Mat – Large']]];
$parsed=Parser::read($wrap($group),'https://gramino.cz/p/mat?v_id=old','Sea to Summit Mat — Small');
$check($parsed['price']===3040&&!$parsed['uncertain']&&str_ends_with($parsed['corrected_url'],'v_id=small'),'Exact variant name did not repair stale link.');
$reject(fn()=>Parser::read($wrap($group),'https://gramino.cz/p/mat?v_id=old','Sea to Summit Mat'));
$reject(fn()=>Parser::read($wrap($group),'https://gramino.cz/p/other?v_id=old','Sea to Summit Mat — Small'));
$reject(fn()=>Parser::read($wrap($group),'https://gramino.cz/p/mat?v_id=old&size=large','Sea to Summit Mat — Small'));
$group['hasVariant'][1]['offers']['price']=3040;
$parsed=Parser::read($wrap($group),'https://gramino.cz/p/mat');
$check($parsed['price']===3040&&$parsed['uniform_variants']&&!$parsed['uncertain'],'Uniform group unnecessarily rejected.');
$check(Manager::reviewReason(['variantSettings'=>['enabled'=>1]],$parsed+['fallback'=>false])==='','Uniform variant prices needlessly reviewed.');
$group['hasVariant'][1]['offers']['validForMemberTier']='club';
$reject(fn()=>Parser::read($wrap($group),'https://gramino.cz/p/mat'));
$f=$fixture;$f['data']['variantsInfo']['7472']['price']=1099;
$check(Parser::read($variantPage($f),$f['url'])['uniform_variants'],'Identical Pod 7 kilo variant prices not recognized.');
$check(!Parser::read($variantPage($fixture),$fixture['url'])['uniform_variants'],'Different Pod 7 kilo variant prices treated as uniform.');
echo "Supplier prices: transparent pricing, jitter, exact-name repairs and uniform variant groups passed.\n";

foreach(['supplier-price-pricing','supplier-price-retry','supplier-price-tempo','supplier-price-export']as$action){
 $check(in_array($action,SimpleStore\Admin\AdminActionPolicy::SUPPLIER_PRICES,true)&&SimpleStore\Admin\AdminActionPolicy::allows($action),'Supplier action is not in the shared authorization/routing policy.');
}
$check(!SimpleStore\Admin\AdminActionPolicy::allows('supplier-price-unknown'),'Unknown supplier action authorized.');

// Gramino lists colour before size and spaces D 890, while the local product uses D890 – S.
$scope=static fn(string $name,array $choices):array=>['products'=>[['name'=>$name]],'variantSettings'=>['enabled'=>1],
 'variants'=>array_map(static fn(array $c):array=>['enabled'=>1,'choices_json'=>json_encode($c)],$choices)];
$scopeGroup=['@type'=>'ProductGroup','name'=>'Patizon D 890','url'=>'https://gramino.cz/p/patizon-d-890','hasVariant'=>[
 $offer('https://gramino.cz/p/patizon-d-890?v_id=red-s',11990)+['name'=>'Patizon D 890 – Red, S'],
 $offer('https://gramino.cz/p/patizon-d-890?v_id=blue-s','11990.00')+['name'=>'Patizon D 890 – Blue, S'],
 $offer('https://gramino.cz/p/patizon-d-890?v_id=red-l',12490)+['name'=>'Patizon D 890 – Red, L'],
 $offer('https://gramino.cz/p/patizon-d-890?v_id=green-m',11391)+['name'=>'Patizon D 890 – Green, M'],
 $offer('https://gramino.cz/p/patizon-d-890?v_id=blue-m',11391)+['name'=>'Patizon D 890 – Blue, M']]];
$doc=Parser::document($wrap($scopeGroup),$scopeGroup['url']);
$local=$scope('Patizon D890 – S',[['Barva'=>'Červená'],['Barva'=>'Modrá']]);
$resolved=SimpleStore\Supplier\GraminoVariantScope::select($doc,$scopeGroup['url'],$local);
$check($resolved!==null&&$resolved['price']===11990&&$resolved['uniform_variants']&&!$resolved['uncertain'],'Exact local colour/size scope not resolved.');
$check(count($resolved['identity_evidence']['matched_offers'])===2&&$resolved['corrected_url']===$scopeGroup['url'],'Common price was tied to an arbitrary colour.');
$local=$scope('Patizon D890 – M Modrá, Zelená',[['Barva'=>'Modrá'],['Barva'=>'Zelená']]);
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$scopeGroup['url'],$local)['price']===11391,'Explicit local colour list failed.');
foreach([
 $scope('Patizon D890 – S',[['Barva'=>'Červená'],['Barva'=>'Žlutá']]),
 $scope('Patizon D890 – S',[['Velikost'=>'L']]),
 $scope('Patizon D890',[['Velikost'=>'S'],['Velikost'=>'L']]),
 $scope('Patizon D890 – M Šedozelená',[['Barva'=>'Zelená']]),
 $scope('Patizon D890 – S',[['Zip'=>'Levý']]),
 $scope('Patizon D290 – S',[['Barva'=>'Červená']]),
 ]as$local)$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$scopeGroup['url'],$local)===null,'Missing, conflicting or differently priced variant accepted.');
$local=$scope('Patizon D890 – L Červená',[['Barva'=>'Červená']]);
$resolved=SimpleStore\Supplier\GraminoVariantScope::select($doc,$scopeGroup['url'],$local);
$check($resolved!==null&&str_ends_with($resolved['corrected_url'],'v_id=red-l'),'Single proven variant did not repair its link.');
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,'https://another.test/p/patizon-d-890',$local)===null,'Gramino scope leaked to a different supplier.');
$doc['single_offers']=false;$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$scopeGroup['url'],$local)===null,'Conditional/extra offers ignored.');
echo "Supplier prices: explicit local model, colour and size scope passed.\n";
