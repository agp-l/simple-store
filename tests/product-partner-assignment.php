<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Product\{ProductPartnerAssignment as Assignment, PurchasePartnerRepository as Partners, PurchasePartnerTransfer as Transfer};
$check = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function(callable $run): void { try {$run();} catch (InvalidArgumentException $e) {return;} throw new RuntimeException('Invalid input accepted.'); };
$partners = [
    ['id'=>1,'name'=>'Shop A','website_url'=>'https://www.example.test/','priority'=>20],
    ['id'=>2,'name'=>'Shop B','website_url'=>'https://other.test/','priority'=>10],
    ['id'=>3,'name'=>'No website','website_url'=>'','priority'=>1],
];
$link = static fn(string $url,int $id=1): array => ['id'=>$id,'url'=>$url];
$check(Assignment::host('HTTPS://WWW.Example.Test./product?a=1')==='example.test','Host normalization failed.');
foreach (['https://example.test.attacker.invalid/p','https://attacker.invalid/?url=https://example.test/p',
    'https://shop.example.test/p','https://example.test@attacker.invalid/p','javascript:alert(1)'] as $url) {
    $check(Assignment::match([$link($url)],$partners)['state']==='no_match','Unrelated or unsafe host matched.');
}
$check(Assignment::match([],$partners)['state']==='no_links','Missing links not explained.');
$check(Assignment::match([$link('https://EXAMPLE.test/p')],$partners)['partner']['id']===1,'www alias did not match.');
$links=[$link('https://example.test/a'),$link('https://other.test/b',2),$link('https://other.test/c',3)];
$match=Assignment::match($links,$partners);
$check($match['partner']['id']===2 && $match['link']['id']===2,'Lower priority or stable URL order failed.');
$partners[0]['priority']=10;
$check(Assignment::match($links,$partners)['state']==='conflict','Equal priority must require a decision.');
$check(Assignment::match([$link('https://other.test/a'),$link('https://www.other.test/b',2)],$partners)['state']==='matched','Same shop links falsely conflict.');
foreach ([0,-1,10000,'1.2','0',[],null,true] as $bad) {
    $reject(static fn()=>Partners::values(['priority'=>$bad]));
}
$profile=['key'=>str_repeat('a',32),'name'=>'Priority test','ico'=>'12345678','address'=>'','email'=>'','website_url'=>'https://example.test','terms_url'=>'','confirmed'=>false];
$stored=$profile+['partner_key'=>$profile['key'],'id'=>1,'version'=>1,'priority'=>7];
$doc=['format'=>Transfer::FORMAT,'version'=>1,'partners'=>[$profile]];
$check(Transfer::compare($doc,[$stored])['counts']['same']===1,'Old JSON reset an existing priority.');
$doc['partners'][0]['priority']=3;
$plan=Transfer::compare($doc,[$stored]);
$check($plan['counts']['changed']===1 && $plan['changes'][0]['after']['priority']===3,'Priority import was ignored.');
$doc['partners'][0]['priority']='3';$reject(static fn()=>Transfer::decode(Transfer::json($doc)));
echo "Seller domain matching, aliases, spoofing, priorities, ties and backward-compatible JSON passed.\n";
