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
foreach(['http://a.test/p','https://127.0.0.1/x','https://localhost/x','https://a.test:444/p','https://u:p@a.test/p','https://[::1]/','https://a.test\\@b.test/']as$bad)$reject(fn()=>Http::url($bad));
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

$sizedGroup=['@type'=>'ProductGroup','name'=>'Warmpeace Viking 300','url'=>'https://gramino.cz/p/viking','hasVariant'=>[
 $offer('https://gramino.cz/p/viking?v_id=blue170',4690)+['name'=>'Warmpeace Viking 300 – Blue, 170 cm, Levá strana'],
 $offer('https://gramino.cz/p/viking?v_id=green170',4690)+['name'=>'Warmpeace Viking 300 – Green, 170 cm, Levá strana'],
 $offer('https://gramino.cz/p/viking?v_id=blue180',4990)+['name'=>'Warmpeace Viking 300 – Blue, 180 cm, Levá strana']]];
$doc=Parser::document($wrap($sizedGroup),$sizedGroup['url']);
$local=$scope('Warmpeace Viking 300 – 170 cm',[['Barva'=>'Modrá','Zip'=>'Levý'],['Barva'=>'Zelená','Zip'=>'Levý']]);
$resolved=SimpleStore\Supplier\GraminoVariantScope::select($doc,$sizedGroup['url'],$local);
$check($resolved!==null&&$resolved['price']===4690,'Explicit centimetres and zipper sides not resolved.');
$local['variants'][1]['choices_json']=json_encode(['Barva'=>'Zelená','Zip'=>'Pravý']);
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$sizedGroup['url'],$local)===null,'Missing right zipper accepted.');
$sizedGroup=['@type'=>'ProductGroup','name'=>'Hyperlite Food Bag','url'=>'https://gramino.cz/p/food-bag','hasVariant'=>[
 $offer('https://gramino.cz/p/food-bag?v_id=10',1590)+['name'=>'Hyperlite Food Bag – 10 l'],
 $offer('https://gramino.cz/p/food-bag?v_id=15',1890)+['name'=>'Hyperlite Food Bag – 15 l']]];
$doc=Parser::document($wrap($sizedGroup),$sizedGroup['url']);
$local=['products'=>[['name'=>'Hyperlite Food Bag 10 l']],'variantSettings'=>['enabled'=>0]];
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$sizedGroup['url'],$local)['price']===1590,'Explicit volume without dash not resolved.');
$local['products'][0]['name']='Hyperlite Food Bag 100 l';
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$sizedGroup['url'],$local)===null,'Volume prefix matched a different volume.');
$sizedGroup['name']='Cumulus Lite Line 200';
$sizedGroup['hasVariant'][0]['name']='Cumulus Lite Line 200 – S, Levá strana';
$sizedGroup['hasVariant'][1]['name']='Cumulus Lite Line 200 – L, Levá strana';
$doc=Parser::document($wrap($sizedGroup),$sizedGroup['url']);
$local=$scope('Cumulus Lite Line 200 – S',[['Zip'=>'Levý']]);
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$sizedGroup['url'],$local)['price']===1590,'Size and zipper without colour not resolved.');
echo "Supplier prices: explicit volume, dimensions, model boundaries and zipper sides passed.\n";

$typed=['@type'=>'ProductGroup','name'=>"Sea To Summit Ultralight™ Insulated Women's",'url'=>'https://gramino.cz/p/womens','hasVariant'=>[
 $offer('https://gramino.cz/p/womens?v_id=regular',3400)+['name'=>"Sea To Summit Ultralight™ Insulated Women's – Regular"],
 $offer('https://gramino.cz/p/womens?v_id=large',3700)+['name'=>"Sea To Summit Ultralight™ Insulated Women's – Large"]]];
$doc=Parser::document($wrap($typed),$typed['url']);$local=['products'=>[['name'=>'Sea to Summit Ultralight Insulated Women’s — Regular']],'variantSettings'=>['enabled'=>0]];
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$typed['url'],$local)['price']===3400,'Trademark or apostrophe prevented an exact variant match.');
$check(Parser::read($wrap($typed),$typed['url'],$local['products'][0]['name'])['price']===3400,'Exact-name repair failed on typography.');
$local['products'][0]['name']='Sea to Summit Ultralight Insulated Men’s — Regular';
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$typed['url'],$local)===null,'Meaningful gender difference was normalized away.');
$typed['name']='Therm-a-Rest Z Seat';$typed['hasVariant'][0]['name']='Therm-a-Rest Z Seat – Yellow / Silver';$typed['hasVariant'][1]['name']='Therm-a-Rest Z Seat – Blue / Silver';
$typed['hasVariant'][1]['offers']['price']=3400;$doc=Parser::document($wrap($typed),$typed['url']);
$local=$scope('Therm-a-Rest Z Seat — Modrá/stříbrná, Žlutá/stříbrná',[['Barva'=>'Modrá/stříbrná'],['Barva'=>'Žlutá/stříbrná']]);
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$typed['url'],$local)['price']===3400,'Explicit two-colour combination not recognized.');
$typed['hasVariant'][1]['name']='Therm-a-Rest Z Seat – Green / Silver';$doc=Parser::document($wrap($typed),$typed['url']);
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$typed['url'],$local)===null,'Different two-colour combination accepted.');
echo "Supplier prices: typography and exact two-colour combinations passed.\n";

// Opted-in merged families retain a separate, fully proven cost for every exact combination.
$doc=Parser::document($wrap($scopeGroup),$scopeGroup['url']);
$local=$scope('Patizon D890',[['Barva'=>'Červená','Velikost'=>'S'],['Barva'=>'Červená','Velikost'=>'L']]);
$local['variantPriceMode']=['enabled'=>1];foreach($local['variants']as$i=>&$v)$v['variant_key']=str_repeat((string)($i+1),32);unset($v);
$result=SimpleStore\Supplier\GraminoVariantScope::select($doc,$scopeGroup['url'],$local);
$check($result!==null&&array_column($result['variant_offers'],'price')===[11990,12490]&&!$result['uniform_variants'],'Distinct exact variant prices collapsed.');
$local['variants'][1]['choices_json']=json_encode(['Barva'=>'Modrá','Velikost'=>'L']);
$check(SimpleStore\Supplier\GraminoVariantScope::select($doc,$scopeGroup['url'],$local)===null,'Invented size/colour combination priced.');
$family=SimpleStore\Product\ProductFamilyMerge::family('Warmpeace Viking 300 – 195 cm');
$check($family['name']==='Warmpeace Viking 300'&&$family['size']==='195 cm','Family boundary wrong.');
$check(SimpleStore\Product\ProductFamilyMerge::family('Warmpeace Viking 600')===null,'Model number treated as a size.');
$choices=SimpleStore\Product\ProductFamilyMerge::choices(['Varianta'=>'silver pine / levý (L)'],'M');
$check($choices===['Barva'=>'silver pine','Velikost'=>'M','Zip'=>'Levá strana'],'Explicit zipper/colour lost.');
foreach(['product-family-preview','product-family-apply','product-family-undo']as$action)$check(SimpleStore\Admin\AdminActionPolicy::allows($action),'Family action not authorized by shared policy.');
$longChoice=SimpleStore\Product\ProductFamilyMerge::choices(['Barva'=>'sunburn','Velikost'=>'do 196 cm - prodloužený (L)'],'L');
$check($longChoice['Velikost']==='do 196 cm - prodloužený (L)','Explicit size description was lost.');
echo "Supplier prices: distinct merged variant prices and missing-combination guards passed.\n";

$check(SimpleStore\Product\ProductFamilyMerge::family('Big Agnes Rapide SL Insulated — Long Wide')['size']==='Long Wide','Mat size not detected.');
$check(SimpleStore\Product\ProductFamilyMerge::family('Sea to Summit Hydraulic Packing Cube XS')['name']==='Sea to Summit Hydraulic Packing Cube','Bare size suffix not detected.');
$check(SimpleStore\Product\ProductFamilyMerge::choices([],'15 l')===['Objem'=>'15 l'],'Volume was stored as an unrelated size.');
$copy=SimpleStore\Product\ProductFamilyMerge::combinedCopy([
 ['name'=>'Mat — Regular','summary'=>'Mat, Regular. Hmotnost 510 g.','description'=>'Tloušťka 7,6 cm. Provedení Regular má plochu 183 × 51 cm.'],
 ['name'=>'Mat — Long Wide','summary'=>'Mat, Long Wide. Hmotnost 709 g.','description'=>'Tloušťka 7,6 cm. Provedení Long Wide má plochu 198 × 64 cm.'],
]);
$check($copy['description']==='Tloušťka 7,6 cm.'&&!str_contains($copy['summary'],'510')&&count($copy['sections'])===2,'Size-specific copy leaked into the common description.');
$check(str_contains($copy['sections'][0]['body'],'510 g.')&&str_contains($copy['sections'][0]['body'],'183 × 51 cm.')&&$copy['sections'][1]['heading']==='Provedení Long Wide','Differing copy was discarded or assigned to the wrong size.');

// Regression data from the 2026-10-25 diagnostic export: full variant scope, never cheapest offer.
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/supplier-scope-regressions.json'),true,64,JSON_THROW_ON_ERROR);
foreach($fixtures as$f){
 $local=['products'=>[['name'=>$f['name']]],'variants'=>$f['variants'],'variantSettings'=>['enabled'=>$f['enabled']]];
 $result=SimpleStore\Supplier\PriceEvaluation::select($f['document'],$f['url'],$local);
 $check(!$result['uncertain']&&$result['uniform_variants']&&$result['identity_evidence']['method']==='exact_local_variant_scope','Exact legacy variant not resolved: '.$f['name']);
 $check(Manager::reviewReason($local,$result+['fallback'=>false])==='','Resolved price still requires review: '.$f['name']);
 $local['variantSettings']['enabled']=1;$local['variants']=[['enabled'=>1,'choices_json'=>'{"Neznámá vlastnost":"jiná"}']];
 $check(SimpleStore\Supplier\GraminoVariantScope::select($f['document'],$f['url'],$local)===null,'Unknown dimension ignored.');
}
foreach([923=>3690,925=>3190]as$id=>$price){
 $f=json_decode(file_get_contents(__DIR__.'/fixtures/supplier-pod-scope-'.$id.'.json'),true,64,JSON_THROW_ON_ERROR);
 $body='<script>var data = '.json_encode($f['data']).';</script>';
 $offers=SimpleStore\Supplier\Pod7kiloVariantIdentity::offers($body,$f['url'],$f['offer']);
 $doc=['single_offers'=>true,'scoped_offers'=>$offers];$local=['variantSettings'=>['enabled'=>1],'variants'=>$f['variants']];
 $r=SimpleStore\Supplier\StructuredVariantScope::select($doc,$f['url'],$local);
 $check($r!==null&&$r['price']===$price,'Pod7kilo exact local colour price failed.');
 $local['variants'][]=['enabled'=>1,'choices_json'=>'{"Barva":"invented colour"}'];
 $check(SimpleStore\Supplier\StructuredVariantScope::select($doc,$f['url'],$local)===null,'Missing Pod7kilo colour accepted.');
 $bad=$f['data'];$bad['variantsInfo'][array_key_first($bad['variantsInfo'])]['productId']=99999;
 $check(SimpleStore\Supplier\Pod7kiloVariantIdentity::offers('<script>var data = '.json_encode($bad).';</script>',$f['url'],$f['offer'])===[],'Foreign Pod7kilo product accepted.');
}
$md="## Specifikace\n\n- Barva: Lichen Green\n- Kapacita baterie: 20000 mAh\n\n## Související\n\n- Barva: Black\n";
$attrs=SimpleStore\Supplier\StructuredVariantScope::productAttributes(['url'=>'https://gramino.cz/p/test'],$md,'https://gramino.cz/p/test',true);
$check($attrs===['color'=>'lichen green','capacity'=>'20000mah'],'Markdown product specifications mixed with recommendations.');
$check(SimpleStore\Supplier\StructuredVariantScope::productAttributes(['url'=>'https://gramino.cz/p/other'],$md,'https://gramino.cz/p/test',true)===[],'Foreign markdown identity accepted.');
$check(Parser::requestUrl('https://gramino.cz/p/test?v_id=abc')==='https://gramino.cz/api/md/p/test?v_id=abc','Documented markdown mirror lost variant query.');
$check(Parser::requestUrl('https://other.test/p/test')==='https://other.test/p/test','Supplier mirror leaked to another host.');
$check(Manager::reviewReason([],['fallback'=>false,'uncertain'=>false,'price'=>100,'availability'=>'out_of_stock'])==='','Valid price was confused with availability.');
$check(Manager::reviewReason(['sale'=>['supplier_price_czk'=>100]],['fallback'=>false,'uncertain'=>false,'price'=>200,'availability'=>'out_of_stock'])!=='','Out-of-stock price bypassed price-jump check.');
echo "Supplier prices: real variant aliases, scoped attributes, mirror identity and out-of-stock pricing passed.\n";
$doc=Parser::document($wrap($scopeGroup),$scopeGroup['url']);
$local=$scope('Patizon D890',[['Barva'=>'Červená','Velikost'=>'S'],['Barva'=>'Červená','Velikost'=>'L']]);
foreach($local['variants']as$i=>&$v){$v['variant_key']=str_repeat((string)($i+1),32);$v['price_czk']=null;}unset($v);
$r=SimpleStore\Supplier\PriceEvaluation::select($doc,$scopeGroup['url'],$local,['pricing_enabled'=>1]);
$check(!empty($r['enable_variant_pricing'])&&array_column($r['variant_offers'],'price')===[11990,12490],'Inherited variants lost their separate exact costs.');
$local['variantPriceMode']=['enabled'=>0];
$check(!SimpleStore\Supplier\PriceEvaluation::mayEnableVariantPricing($local,['pricing_enabled'=>1]),'Explicitly disabled automation was enabled.');
unset($local['variantPriceMode']);$local['variants'][0]['price_czk']=15000;
$check(!SimpleStore\Supplier\PriceEvaluation::mayEnableVariantPricing($local,['pricing_enabled'=>1]),'Manual variant retail price was claimed by automation.');
$local['variants'][0]['price_czk']=null;$local['sale']=['variant_prices_json'=>'{"manual":100}'];
$check(!SimpleStore\Supplier\PriceEvaluation::mayEnableVariantPricing($local,['pricing_enabled'=>1]),'Manual supplier overrides were claimed by automation.');
$keyedCopy=SimpleStore\Product\ProductFamilyMerge::combinedCopy(['key-a'=>['name'=>'Mat — Regular','summary'=>'Shared','description'=>'Common. Width 51 cm.'],'key-b'=>['name'=>'Mat — Long Wide','summary'=>'Shared','description'=>'Common. Width 64 cm.']]);
$check($keyedCopy['summary']==='Shared'&&$keyedCopy['description']==='Common.'&&$keyedCopy['sections'][1]['heading']==='Provedení Long Wide','Product-keyed snapshots changed copy extraction.');

// Unavailable robots is distinct from an actual product denial. Authentication and backoffs stay closed.
foreach([403,404,410] as $code)$check(!isset(SimpleStore\Supplier\Robots::cachedResponse(['http_code'=>$code,'error'=>'old parser denial'])['error']),'Unavailable robots cache not reinterpreted.');
foreach([401,407,408,429,500,503,0] as $code)$check(isset(SimpleStore\Supplier\Robots::cachedResponse(['http_code'=>$code,'error'=>'keep'])['error']),'Robots authentication, network or rate denial bypassed.');
$check(SimpleStore\Admin\AdminActionPolicy::allows('supplier-price-probe'),'Diagnostic action not covered by admin policy.');

// Fragment selectors remain part of offer identity, never the transport/cache identity.
$check(Http::url('https://a.test/p#orange')==='a.test','A valid colour fragment was silently rejected.');
$check(Parser::requestUrl('https://a.test/p#orange')==='https://a.test/p','Fragment sent to HTTP.');
$check(Http::cacheKey('product','https://a.test/p#orange')===Http::cacheKey('product','https://a.test/p#blue'),'Colours bypassed shared document cache.');
$check(!Parser::sameOfferUrl('https://a.test/p#orange','https://a.test/p#blue'),'Different colour selectors matched.');
$check(Http::redirect('https://a.test/old#orange','/new')==='https://a.test/new#orange','Redirect discarded selected colour.');
$check(Parser::read($wrap($offer('https://a.test/p')),'https://a.test/p#unknown')['uncertain'],'Unproven fragment accepted as base price.');
$f4=json_decode(file_get_contents(__DIR__.'/fixtures/4camping-opinel.json'),true,32,JSON_THROW_ON_ERROR);
$html4=static fn(array $f):string=>'<script>var data = '.json_encode($f['data']).';</script>'.$wrap($f['product']);
$doc4=Parser::document($html4($f4),$f4['url']);$orange=$f4['url'].'#oranzova';
$r4=Parser::select($doc4,$orange);
$check($r4['price']===689&&$r4['attributes']['color']==='orange'&&$r4['identity_evidence']['variant_id']==='45483'&&!$r4['uncertain'],'Opinel orange was not identified exactly.');
$check($r4['availability']==='in_stock','4camping stock label not read.');
$reject(fn()=>Parser::select($doc4,$f4['url'].'#neznama-barva'));
$reject(fn()=>Parser::select($doc4,$f4['url']));
$different=$f4;$different['data']['variantsInfo']['45483']['price']=799;
$check(Parser::read($html4($different),$orange)['price']===799,'Lowest model price replaced orange SKU price.');
foreach(['product','currency','url','price','duplicate','script','selected-type','id-type'] as $bad){
 $broken=$f4;
 if($bad==='product')$broken['data']['variantsInfo']['45483']['productId']=999;
 if($bad==='currency')$broken['data']['currency']='eur';
 if($bad==='selected-type')$broken['data']['selectedVariantId']=[];
 if($bad==='id-type')$broken['data']['variantsInfo']['45483']['productId']=[];
 if($bad==='url')$broken['data']['variantsInfo']['45483']['url']='https://other.test/p/item/oranzova/';
 if($bad==='price')$broken['product']['offers']['price']=1;
 if($bad==='duplicate')$broken['data']['variantsInfo']['45480']['url']=$broken['data']['variantsInfo']['45483']['url'];
 $body=$html4($broken);if($bad==='script')$body.='<script>var data = '.json_encode($broken['data']).';</script>';
 $reject(fn()=>Parser::read($body,$orange));
}
$v4=['variant_key'=>str_repeat('4',32),'enabled'=>1,'choices_json'=>'{"Barva":"Oranžová"}'];
$match4=SimpleStore\Supplier\VariantLinkMatcher::match($doc4,$f4['url'],$f4['product']['name'],$v4,$orange);
$check($match4['status']==='exact'&&$match4['url']===$orange,'4camping exact links do not work with local variants.');
$check(SimpleStore\Supplier\VariantLinkMatcher::match($doc4,$f4['url'],$f4['product']['name'],$v4,$f4['url'].'#modra')['status']!=='exact','Wrong colour accepted for a local variant.');

require __DIR__."/supplier-identity-regressions.php";

// A single SKU may repeat colour/size in JSON-LD and in its specification table.
$attributes=SimpleStore\Supplier\StructuredVariantScope::productAttributes(
 ['url'=>'https://gramino.cz/p/sleeping-bag?v_id=sku','color'=>'Blue','size'=>'M'],
 "## Specifikace\n- Barva: Modrá\n- Velikost produktu: M\n- Strana zipu: Levá strana\n",'https://gramino.cz/p/sleeping-bag?v_id=sku',true);
$check($attributes===['color'=>'blue','size'=>'m','zip'=>'left'],'Equivalent typed/specification axes erased single-SKU identity.');
$conflict=SimpleStore\Supplier\StructuredVariantScope::productAttributes(
 ['url'=>'https://gramino.cz/p/sleeping-bag?v_id=sku','color'=>'Blue'],"## Specifikace\n- Barva: Červená\n",'https://gramino.cz/p/sleeping-bag?v_id=sku',true);
$check($conflict===[],'Conflicting structured/specification colour accepted.');
echo "Supplier identities: equivalent repeated single-SKU properties retained, conflicts rejected.\n";
