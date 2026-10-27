<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Counts products once; variants and merchant overlaps have separate denominators. */
final class PartnerCoverage
{
    public static function summarize(array $products,array $partners): array
    {
        $rows=[]; $coverage=[]; $overlaps=[]; $variantCount=0; $multiple=0;
        foreach ($partners as $partner) $rows[$partner['partner_key']]=$partner+['linked_products'=>0,'linked_variants'=>0,'verified_products'=>0,'complete_products'=>0,'available_products'=>0,'verified_variants'=>0,'available_variants'=>0,'errors'=>0,'expired'=>0];
        foreach ($products as $product) {
            $variantCount+=count($product['variants']); $grouped=[];
            foreach ($product['offers'] as $offer) $grouped[$offer['partner_key']][$offer['variant_key']][]=$offer;
            $keys=array_keys($grouped); sort($keys);
            if (count($keys)>1) $multiple++;
            foreach ($keys as $i=>$left) foreach (array_slice($keys,$i+1) as $right) $overlaps[$left.':'.$right]=($overlaps[$left.':'.$right]??0)+1;
            foreach ($grouped as $partner=>$variants) {
                if (!isset($rows[$partner])) continue;
                $row=&$rows[$partner]; $row['linked_products']++; $row['linked_variants']+=count($variants);
                $verified=0; $available=0;
                foreach ($variants as $offers) {
                    $valid=array_values(array_filter($offers,static fn(array $o):bool=>$o['status']==='verified'));
                    // Conflicting duplicate source observations cannot establish a usable offer.
                    $signatures=array_unique(array_map(static fn(array $o):string=>json_encode([$o['amount'],$o['availability'],$o['reported_quantity']]),$valid));
                    if ($valid!==[] && count($signatures)===1) {
                        $verified++;
                        if ((PartnerStockPolicy::limit($valid[0])??0)>0) $available++;
                    }
                    if (count($signatures)>1 || count(array_filter($offers,static fn(array $o):bool=>$o['status']==='error'))>0) $row['errors']++;
                    if (count(array_filter($offers,static fn(array $o):bool=>$o['status']==='expired'))>0) $row['expired']++;
                }
                $row['verified_variants']+=$verified; $row['available_variants']+=$available;
                if ($verified>0) $row['verified_products']++;
                if ($verified===count($product['variants'])) $row['complete_products']++;
                if ($available>0) $row['available_products']++;
                unset($row);
            }
        }
        $pairs=[];
        foreach ($overlaps as $pair=>$count) { [$left,$right]=explode(':',$pair); $pairs[]=['left'=>$left,'right'=>$right,'products'=>$count]; }
        usort($pairs,static fn(array $a,array $b):int=>$b['products']<=>$a['products']);
        uasort($rows,static fn(array $a,array $b):int=>[$b['available_products'],$b['verified_products'],$b['linked_products']]<=>[$a['available_products'],$a['verified_products'],$a['linked_products']]);
        return ['products'=>count($products),'variants'=>$variantCount,'multiple_partners'=>$multiple,'partners'=>array_values($rows),'overlaps'=>$pairs];
    }
}
