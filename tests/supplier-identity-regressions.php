<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Supplier\{PriceParser,VariantLinkMatcher,VariantOfferEvaluation,Pod7kiloVariantIdentity};
$assertIdentity=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$cases=json_decode(file_get_contents(__DIR__.'/fixtures/supplier-identity-regressions.json'),true,64,JSON_THROW_ON_ERROR);
foreach($cases as$case){
    $doc=PriceParser::document($case['body'],$case['url']);
    if(!$case['variants']){
        $result=PriceParser::select($doc,$case['url'],$case['name']);
        $assertIdentity(!$result['uncertain']&&$result['price']===$case['expected'],'Single SKU rejected: '.$case['name']);
        $wrong=preg_replace('/size=\d+/','size=999999',$case['url']);
        $assertIdentity(PriceParser::read($case['body'],$wrong)['uncertain'],'HUDY accepted a different selected SKU.');
        $bad=str_replace('"variant_id":','"unverified_barcode":',$case['body']);
        $assertIdentity(PriceParser::read($bad,$case['url'])['uncertain'],'HUDY accepted missing barcode evidence.');
        continue;
    }
    foreach($case['variants']as$v){
        $match=VariantLinkMatcher::match($doc,$case['url'],$case['name'],$v,$case['url']);
        if($match['status']!=='exact')$match=VariantLinkMatcher::match($doc,$case['url'],$case['name'],$v);
        $assertIdentity($match['status']==='exact'&&$match['offer']['price']===$case['expected'],'Variant rejected: '.$case['name'].' '.$v['choices_json']);
        $bad=$v;$bad['choices_json']=json_encode(['Barva'=>'unrelated colour']);
        $assertIdentity(VariantLinkMatcher::match($doc,$case['url'],$case['name'],$bad)['status']!=='exact','Different colour accepted.');
        $assertIdentity(VariantLinkMatcher::match($doc,$case['url'],'A different model',$v)['status']!=='exact','Different model accepted.');
        if($case['id']===1116)$assertIdentity(str_contains($match['url'],'wide-'),'Luxury incorrectly matched cheaper standard version.');
        if(in_array($case['id'],[1123,1124],true)){
            $badDoc=$doc;foreach($badDoc['offers']as&$offer)$offer['model_features']=[];unset($offer);
            $assertIdentity(VariantLinkMatcher::match($badDoc,$case['url'],$case['name'],$v)['status']!=='exact','HyperBead accepted without model evidence.');
        }
    }
}
$case=array_values(array_filter($cases,static fn(array $c):bool=>$c['id']===1113))[0];
$doc=PriceParser::document($case['body'],$case['url']);$links=[];
foreach($case['variants']as$v)$links[]=['link_key'=>$v['variant_key'],'variant_key'=>$v['variant_key'],'origin'=>'inherited'];
$result=VariantOfferEvaluation::observe($doc,$case['url'],['variants'=>$case['variants'],'variantSettings'=>['enabled'=>1],'products'=>[['name'=>$case['name']]]],
    ['links'=>$links,'url'=>$case['url'],'partner_id'=>1,'partner_key'=>'test'],time());
$assertIdentity(count($result['observations'])===2&&$result['issues']===[],'Copied exact L URL failed L or contaminated XL.');
$assertIdentity(Pod7kiloVariantIdentity::specifications('<p>Kapacita 10000mAh</p>','https://www.pod7kilo.cz/p/model/')===[],'Arbitrary visible capacity accepted.');
echo "Supplier identities: model, SKU, colour, size, version, technical capacity and conflicting evidence passed.\n";
