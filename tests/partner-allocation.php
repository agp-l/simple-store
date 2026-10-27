<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Checkout/PartnerAllocation.php';
use SimpleStore\Checkout\PartnerAllocation;
$check=static function(bool $ok,string $why):void { if (!$ok) throw new RuntimeException($why); };
$offer=static fn(string $key,int $cost=100,bool $primary=false):array=>['partner_key'=>$key,'cost'=>$cost,'primary'=>$primary];
$row=static fn(array $offers,int $quantity=1):array=>['quantity'=>$quantity,'offers'=>array_column($offers,null,'partner_key')];
$rows=['x'=>$row([$offer('a',100,true),$offer('c',90)]),'y'=>$row([$offer('b',100,true),$offer('c',95)])];
$plan=PartnerAllocation::choose($rows);
$check(array_column($plan['offers'],'partner_key')===['c','c'] && $plan['complete'],'Did not consolidate the whole basket.');
$rows['own-independent']=$row([$offer('d',200,true)]);
$check(count(array_unique(array_column(PartnerAllocation::choose($rows)['offers'],'partner_key')))===2,'Did not find the minimum feasible partners.');
$check(PartnerAllocation::choose(array_reverse($rows,true))===PartnerAllocation::choose($rows),'Cart order changed the result.');
$check(PartnerAllocation::choose([])===['offers'=>[],'complete'=>true],'Empty basket failed.');
$check(PartnerAllocation::choose(['x'=>$row([])])['offers']===[],'Missing offers accepted.');
$tie=PartnerAllocation::choose(['x'=>$row([$offer('b',100,true),$offer('a')])]);
$check($tie['offers']['x']['partner_key']==='b','Equal offers needlessly changed the primary seller.');
$limited=PartnerAllocation::choose($rows,0);
$check(!$limited['complete'] && count($limited['offers'])===3,'Bounded search lost a feasible basket.');
// Compare the search against exhaustive enumeration on many small, irregular catalogs.
mt_srand(227);
for ($test=0;$test<150;$test++) {
    $rows=[];
    for ($line=0;$line<6;$line++) {
        $offers=[];
        for ($p=0;$p<5;$p++) if (mt_rand(0,2)>0) $offers[]=$offer((string)$p,mt_rand(1,100));
        if ($offers===[]) $offers[]=$offer('0');
        $rows[(string)$line]=$row($offers,mt_rand(1,3));
    }
    $best=[PHP_INT_MAX,PHP_INT_MAX];
    for ($mask=1;$mask<32;$mask++) {
        $cost=0; $valid=true;
        foreach ($rows as $r) {
            $prices=[];
            foreach ($r['offers'] as $p=>$o) if ($mask & (1<<(int)$p)) $prices[]=$o['cost'];
            if ($prices===[]) { $valid=false; break; }
            $cost+=min($prices)*$r['quantity'];
        }
        $score=[substr_count(decbin($mask),'1'),$cost];
        if ($valid && $score<$best) $best=$score;
    }
    $plan=PartnerAllocation::choose($rows); $cost=0;
    foreach ($plan['offers'] as $line=>$o) $cost+=$o['cost']*$rows[$line]['quantity'];
    $check([count(array_unique(array_column($plan['offers'],'partner_key'))),$cost]===$best,'Search disagrees with exhaustive optimum.');
}
echo "Partner allocation: global cover, price/quantity tie-break, determinism and bounded fallback passed.\n";
