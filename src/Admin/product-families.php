<?php
declare(strict_types=1);
$familyStore=new \SimpleStore\Product\ProductFamilyMerge($db);
$familyMessages=[];$familyError='';
try{
    if(!$familyStore->installed())throw new InvalidArgumentException('Nejdřív aktualizuj tabulky v sekci Databáze.');
    if($method==='POST'&&$action==='product-family-preview'){
        $plans=[];$blocked=[];
        foreach($familyStore->candidates($language)as$group){
            try{$plans[]=$familyStore->preview($group[0]['product_key'],$language);}
            catch(InvalidArgumentException $e){$blocked[]=['name'=>\SimpleStore\Product\ProductFamilyMerge::family($group[0]['name'])['name'],'message'=>$e->getMessage()];}
        }
        $_SESSION['product_family_preview']=['language'=>$language,'plans'=>$plans,'blocked'=>$blocked];
    }
    if($method==='POST'&&$action==='product-family-apply'){
        if(($_POST['confirm']??'')!=='1')throw new InvalidArgumentException('Potvrď sloučení vybraných skupin z náhledu.');
        $selected=$_POST['families']??[];if(!is_array($selected)||$selected===[]||count($selected)>30)throw new InvalidArgumentException('Vyber 1 až 30 skupin najednou.');
        $saved=$_SESSION['product_family_preview']??[];
        if(($saved['language']??'')!==$language)throw new InvalidArgumentException('Nejdřív připrav aktuální náhled.');
        foreach($saved['plans']??[]as$i=>$plan){
            if(!in_array($plan['target'],$selected,true))continue;
            try{$slug=$familyStore->apply($plan['target'],$language,$plan['expected']);$familyMessages[]=$plan['name'].': sloučeno '.count($plan['members']).' produktů do '.count($plan['variants']).' variant.';unset($_SESSION['product_family_preview']['plans'][$i]);}
            catch(InvalidArgumentException $e){$familyMessages[]=$plan['name'].': beze změny. '.$e->getMessage();}
        }
    }
    if($method==='POST'&&$action==='product-family-undo'){
        $familyStore->undo((int)($_POST['merge_id']??0));$familyMessages[]='Sloučení vráceno. Původní produkty a jejich aktivní varianty jsou obnovené.';
    }
}catch(InvalidArgumentException $e){$familyError=$e->getMessage();}
$familyPreview=$_SESSION['product_family_preview']??['plans'=>[],'blocked'=>[]];
$familyHistory=$familyStore->history();
