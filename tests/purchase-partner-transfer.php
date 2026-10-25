<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Product\{PurchasePartnerTransfer as Transfer, PurchasePartnerRepository as Partners};
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $run): void { try { $run(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Unsafe import accepted.'); };
$profile = ['name'=>'Obchod <safe>', 'ico'=>'12345678', 'address'=>'Test 1, Praha', 'email'=>'test@example.test',
    'website_url'=>'https://example.test/', 'terms_url'=>'https://example.test/terms', 'confirmed'=>false];
$doc = ['format'=>Transfer::FORMAT, 'version'=>1, 'partners'=>[$profile]];
$data = Transfer::decode(Transfer::json($doc));
$check($data['partners'][0]['key'] === '', 'ICO-only imports must not require local IDs.');
$stored = $profile + ['id'=>7, 'version'=>3, 'partner_key'=>str_repeat('a',32)];
$same = Transfer::compare($data, [$stored]);
$check($same['counts'] === ['new'=>0,'changed'=>0,'same'=>1] && $same['changes'][0]['id']===7, 'ICO matching replaced a local identity.');
$otherKey = $data; $otherKey['partners'][0]['key'] = str_repeat('b',32);
$check(Transfer::compare($otherKey,[$stored])['changes'][0]['key']===str_repeat('a',32), 'Imported keys replaced local identities on ICO match.');
$changed = $data; $changed['partners'][0]['website_url']='https://new.example.test/';
$check(Transfer::compare($changed,[$stored])['counts']['changed']===1, 'Website change was missed.');
$check(Transfer::token($data,$same['revision'],'csrf')!==Transfer::token($changed,$same['revision'],'csrf'), 'Preview token does not bind data.');
$conflict=$data;$conflict['partners'][0]['key']=str_repeat('a',32);$conflict['partners'][0]['ico']='87654321';
$reject(static fn()=>Transfer::compare($conflict,[$stored]));
$reject(static fn()=>Transfer::compare($data,[$stored,array_replace($stored,['id'=>8,'partner_key'=>str_repeat('b',32)])]));
$duplicate=$data;$duplicate['partners'][]=$duplicate['partners'][0];
$reject(static fn()=>Transfer::compare($duplicate,[]));
$reject(static fn()=>Transfer::compare($duplicate,[$stored]));
$draft=$data;$draft['partners'][0]['ico']='';$reject(static fn()=>Transfer::decode(Transfer::json($draft)));
$draft['partners'][0]['key']=str_repeat('a',32);
$check(Transfer::compare($draft,[array_replace($stored,['ico'=>''])])['counts']['same']===1,'Draft round-trip failed.');
foreach (['javascript:alert(1)','data:text/html,hello','https://user:secret@example.test/','https://'] as $bad) {
    $invalid=$data;$invalid['partners'][0]['website_url']=$bad;
    $reject(static fn()=>Transfer::decode(Transfer::json($invalid)));
}
foreach ([['id'=>4],['version'=>8],['product_count'=>1],['confirmed'=>'1']] as $extra) {
    $invalid=$data;$invalid['partners'][0]=array_replace($invalid['partners'][0],$extra);
    $reject(static fn()=>Transfer::decode(Transfer::json($invalid)));
}
$missing=$data;unset($missing['partners'][0]['email']);$reject(static fn()=>Transfer::decode(Transfer::json($missing)));
$reject(static fn()=>Transfer::decode('{broken'));
$reject(static fn()=>Transfer::decode(str_repeat('x',Transfer::LIMIT+1)));
$full=array_replace($profile,['confirmed'=>'1']);
$check(Partners::missing($full)===[] && Partners::missing(array_replace($full,['website_url'=>'']))===[], 'Optional website disabled existing valid sellers.');
$example=Transfer::decode(file_get_contents(__DIR__.'/../docs/examples/prodavajici-pod7kilo-hudy-alza.json'));
$check(count($example['partners'])===3 && array_filter($example['partners'],static fn($p)=>$p['confirmed'])===[], 'Example falsely confirms partnerships.');
echo "Partner JSON validation, matching, preview binding and optional websites passed.\n";
