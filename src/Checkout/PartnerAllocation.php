<?php
declare(strict_types=1);
namespace SimpleStore\Checkout;

/** Bounded exact set cover; ties prefer lower purchase cost, then the existing seller. */
final class PartnerAllocation
{
    /** Each row has a quantity and eligible offers indexed by a stable partner key. */
    public static function choose(array $rows, int $maxNodes = 20000): array
    {
        if ($rows === []) return ['offers'=>[], 'complete'=>true];
        foreach ($rows as $row) if ($row['offers'] === []) return ['offers'=>[], 'complete'=>true];
        ksort($rows, SORT_STRING);
        $evaluate = static function(array $partners) use ($rows): ?array {
            $chosen=[]; $cost=0; $changes=0; $used=[];
            foreach ($rows as $key=>$row) {
                $allowed=array_intersect_key($row['offers'],$partners);
                if ($allowed===[]) return null;
                uasort($allowed,static fn(array $a,array $b):int=>
                    [$a['cost'],empty($a['primary']),$a['partner_key']] <=> [$b['cost'],empty($b['primary']),$b['partner_key']]);
                $offer=reset($allowed); $chosen[$key]=$offer;
                $used[$offer['partner_key']]=true; $cost+=$offer['cost']*$row['quantity'];
                $changes+=(int)empty($offer['primary']);
            }
            ksort($used, SORT_STRING);
            return ['offers'=>$chosen,'score'=>[count($used),$cost,$changes,implode(',',array_keys($used))]];
        };
        $all=[];
        foreach ($rows as $row) foreach ($row['offers'] as $key=>$offer) $all[$key]=true;
        // Always retain a valid plan if pathological inputs exhaust the search budget.
        $best=$evaluate($all); $primary=[];
        foreach ($rows as $row) foreach ($row['offers'] as $key=>$offer) if (!empty($offer['primary'])) $primary[$key]=true;
        $baseline=$evaluate($primary);
        if ($baseline!==null && $baseline['score']<$best['score']) $best=$baseline;
        $seen=[]; $nodes=0; $complete=true;
        $search = static function(array $selected) use (&$search,&$best,&$seen,&$nodes,&$complete,$maxNodes,$rows,$evaluate): void {
            ksort($selected, SORT_STRING); $signature=implode(',',array_keys($selected));
            if (isset($seen[$signature])) return;
            if (++$nodes>$maxNodes) { $complete=false; return; }
            $seen[$signature]=true; $uncovered=null;
            foreach ($rows as $row) {
                if (array_intersect_key($row['offers'],$selected)!==[]) continue;
                if ($uncovered===null || count($row['offers'])<count($uncovered)) $uncovered=$row['offers'];
            }
            if ($uncovered===null) {
                $plan=$evaluate($selected);
                if ($plan['score']<$best['score']) $best=$plan;
                return;
            }
            if (count($selected)>=$best['score'][0]) return;
            ksort($uncovered,SORT_STRING);
            foreach ($uncovered as $key=>$offer) {
                $search($selected+[$key=>true]);
                if (!$complete) return;
            }
        };
        $search([]);
        return ['offers'=>$best['offers'],'complete'=>$complete];
    }
}
