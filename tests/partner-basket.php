<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Checkout\{PartnerBasketOptimizer as Optimizer,CartPartnerSelection,CheckoutReview};
use SimpleStore\Product\PartnerDeliveryCosts;
$check=static function(bool $ok,string $why):void { if (!$ok) throw new RuntimeException($why); };
$offer=static fn(int $cost):array=>['cost'=>$cost,'priority'=>100];
$groups=['x'=>['quantity'=>1,'primary'=>'a','offers'=>['a'=>$offer(100),'b'=>$offer(95)]],
         'y'=>['quantity'=>1,'primary'=>'b','offers'=>['a'=>$offer(95),'b'=>$offer(100)]]];
$r=Optimizer::choose($groups,[]);
$check(count(array_unique($r['choices']))===1 && !$r['priced'],'Common partner was not selected.');
$rates=['a'=>['shipping'=>60,'free_from'=>null],'b'=>['shipping'=>60,'free_from'=>null]];
$r=Optimizer::choose($groups,$rates);$check(count(array_unique($r['choices']))===1 && $r['priced'],'Freight not charged once.');
$groups['x']['offers']['b']=$offer(300);$groups['y']['offers']['a']=$offer(300);
$r=Optimizer::choose($groups,$rates);$check($r['choices']===['x'=>'a','y'=>'b'],'More expensive common merchant incorrectly preferred.');
$rates['a']=['shipping'=>350,'free_from'=>400];
$r=Optimizer::choose($groups,$rates);$check($r['choices']===['x'=>'a','y'=>'a'],'Free delivery threshold ignored.');
$limited=Optimizer::choose($groups,$rates,1);$check(!$limited['complete'] && count($limited['choices'])===2,'Bounded search lost complete solution.');
$fractional=['x'=>['quantity'=>2,'primary'=>'a','offers'=>[
 'a'=>['cost'=>100,'cost_minor'=>9901], 'b'=>['cost'=>110,'cost_minor'=>11000]]]];
$r=Optimizer::choose($fractional,['a'=>['shipping'=>100,'free_from'=>200],'b'=>['shipping'=>0,'free_from'=>null]]);
$check($r['choices']['x']==='b','Rounded purchase price incorrectly triggered free delivery.');
// Independent exhaustive oracle for random small baskets (shipping thresholds make greedy selection incorrect).
mt_srand(71);
for ($case=0;$case<80;$case++) {
 $g=[];$rates=[];
 foreach(['a','b','c'] as $p)$rates[$p]=['shipping'=>mt_rand(0,150),'free_from'=>mt_rand(0,1)?mt_rand(100,800):null];
 for($i=0;$i<4;$i++){ $offers=[];foreach(['a','b','c'] as $p)if(mt_rand(0,3)>0)$offers[$p]=$offer(mt_rand(20,250));if(!$offers)$offers['a']=$offer(40);$g[(string)$i]=['quantity'=>mt_rand(1,3),'primary'=>array_key_first($offers),'offers'=>$offers]; }
 $price=static function(array $selection)use($g,$rates):int{$totals=[];foreach($selection as $k=>$p)$totals[$p]=($totals[$p]??0)+$g[$k]['quantity']*$g[$k]['offers'][$p]['cost'];$sum=array_sum($totals);foreach($totals as $p=>$v)if($rates[$p]['free_from']===null||$v<$rates[$p]['free_from'])$sum+=$rates[$p]['shipping'];return $sum;};
 $best=PHP_INT_MAX;$walk=function(int $i,array $c)use(&$walk,&$best,$g,$price):void{if($i===4){$best=min($best,$price($c));return;}foreach($g[$i]['offers'] as $p=>$o)$walk($i+1,$c+[$i=>$p]);};$walk(0,[]);
 $r=Optimizer::choose($g,$rates);$check($r['complete']&&$price($r['choices'])===$best,'Optimizer differs from exhaustive oracle.');
}
$partner=['name'=>'Partner','ico'=>'12345678','address'=>'Praha','email'=>'partner@example.cz','terms_url'=>'https://example.cz/terms','confirmed'=>1];
$row=['variant_key'=>'v','partner_key'=>'p','status'=>'verified','amount'=>'101.50','availability'=>'in_stock','reported_quantity'=>null,'source_url'=>'https://example.cz/p','resolved_url'=>'','observed_at'=>1800000000];
$find=static fn(array $rows,int $q=1):array=>CartPartnerSelection::offers($rows,['p'=>$partner],'v',$q,200);
$check($find([$row])['p']['cost']===102,'Purchase amount incorrectly uses retail rounding.');
$check($find([$row],4)===[],'Unknown stock accepted above 3.');
$check($find([array_replace($row,['reported_quantity'=>1])])===[],'Last unit accepted.');
$check($find([array_replace($row,['reported_quantity'=>2])],3)===[],'Quantity limit exceeded.');
foreach(['expired','unverified','error'] as $status)$check($find([array_replace($row,['status'=>$status])])===[],'Unverified evidence accepted.');
$check($find([array_replace($row,['variant_key'=>'other'])])===[],'Wrong size accepted.');
$check($find([$row,array_replace($row,['amount'=>'103.00'])])===[],'Conflicting prices accepted.');
$check(CartPartnerSelection::offers([$row],['p'=>array_replace($partner,['confirmed'=>0])],'v',1,200)===[],'Unconfirmed partner accepted.');
$check(PartnerDeliveryCosts::values(['partner_shipping_czk'=>'0'])===['shipping_czk'=>0,'free_from_czk'=>null],'Zero freight lost.');
$before=['items'=>[['seller'=>['name'=>'A'],'procurement'=>['partner_key'=>'a']]]];$after=['items'=>[['seller'=>['name'=>'B'],'procurement'=>['partner_key'=>'b']]]];
$check(CheckoutReview::token($before,[],0,'bank_transfer','','secret')!==CheckoutReview::token($after,[],0,'bank_transfer','','secret'),'Seller change not bound to review.');
echo "Partner basket optimizer and exact-offer eligibility passed.\n";
