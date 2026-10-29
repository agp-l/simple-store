<?php
declare(strict_types=1);
namespace SimpleStore\Checkout;

/** Pure, bounded facility assignment. All money is integer CZK; freight is paid once per seller. */
final class PartnerBasketOptimizer
{
    public static function choose(array $groups, array $rates, int $budget = 100000): array
    {
        if ($groups === []) return ['choices'=>[], 'complete'=>true, 'priced'=>false];
        ksort($groups);
        $priced = true;
        foreach ($groups as $group) {
            if ($group['offers'] === []) return ['choices'=>[], 'complete'=>true, 'priced'=>false];
            foreach ($group['offers'] as $partner => $offer) if (!isset($rates[$partner])) $priced=false;
        }
        $score = static function(array $choices) use ($groups,$rates,$priced): array {
            $totals=[]; $priority=0;
            foreach ($choices as $key=>$partner) {
                $offer=$groups[$key]['offers'][$partner];
                $totals[$partner]=($totals[$partner]??0)+$offer['cost']*$groups[$key]['quantity'];
                $priority+=(int)($offer['priority']??100);
            }
            $cost=array_sum($totals);
            if ($priced) foreach ($totals as $partner=>$total) {
                $rate=$rates[$partner];
                if ($rate['free_from']===null || $total<$rate['free_from']) $cost+=$rate['shipping'];
            }
            ksort($choices);
            return [$priced?$cost:count($totals),$priced?count($totals):$cost,$priority,json_encode($choices)];
        };
        $best=[]; $bestScore=null;
        $consider=static function(array $choices) use (&$best,&$bestScore,$score):void {
            $s=$score($choices);
            if ($bestScore===null || $s<$bestScore) { $best=$choices; $bestScore=$s; }
        };
        $baseline=[]; $all=[];
        foreach ($groups as $key=>$group) {
            $baseline[$key]=isset($group['offers'][$group['primary']])?$group['primary']:array_key_first($group['offers']);
            foreach ($group['offers'] as $partner=>$offer) $all[$partner]=true;
        }
        $consider($baseline);
        // Seed complete single-seller solutions before the bounded search.
        foreach (array_keys($all) as $partner) {
            $choices=[];
            foreach ($groups as $key=>$group) { if (!isset($group['offers'][$partner])) continue 2; $choices[$key]=$partner; }
            $consider($choices);
        }
        uasort($groups,static fn(array $a,array $b):int=>count($a['offers'])<=>count($b['offers']));
        $keys=array_keys($groups); $nodes=0; $complete=true;
        $search=function(int $index,array $choices,array $used,int $purchase) use (&$search,&$nodes,&$complete,$budget,$keys,$groups,$priced,&$bestScore,$consider):void {
            if (++$nodes>$budget) { $complete=false; return; }
            if ($bestScore!==null && (($priced && $purchase>$bestScore[0]) || (!$priced && count($used)>$bestScore[0]))) return;
            if ($index===count($keys)) { $consider($choices); return; }
            $key=$keys[$index]; $group=$groups[$key];
            $offers=$group['offers'];
            uksort($offers,static fn(string $a,string $b):int=>[isset($used[$a])?0:1,$offers[$a]['cost'],$a]<=>[isset($used[$b])?0:1,$offers[$b]['cost'],$b]);
            foreach ($offers as $partner=>$offer) {
                $search($index+1,$choices+[$key=>$partner],$used+[$partner=>true],$purchase+$offer['cost']*$group['quantity']);
                if (!$complete) return;
            }
        };
        $search(0,[],[],0);
        return ['choices'=>$best,'complete'=>$complete,'priced'=>$priced];
    }
}
