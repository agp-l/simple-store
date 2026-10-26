<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

final class PriceEvaluation
{
    public static function mayEnableVariantPricing(array $snapshot,array $pricing):bool
    {
        if(empty($pricing['pricing_enabled'])||empty($snapshot['variantSettings']['enabled'])||($snapshot['variantPriceMode']??null)!==null||
            !empty(json_decode($snapshot['sale']['variant_prices_json']??'{}',true)))return false;
        $active=0;
        foreach($snapshot['variants']??[]as$v)if(!empty($v['enabled'])){if(($v['price_czk']??null)!==null)return false;$active++;}
        return $active>0;
    }
    public static function select(array $document,string $url,array $snapshot,array $pricing=[]):array
    {
        $inherited=self::mayEnableVariantPricing($snapshot,$pricing);$scope=$snapshot;
        if($inherited)$scope['variantPriceMode']=['enabled'=>1];
        $result=StructuredVariantScope::select($document,$url,$scope)??
            GraminoVariantScope::select($document,$url,$scope)??
            PriceParser::select($document,$url,$snapshot['products'][0]['name']??'');
        if($inherited&&!empty($result['variant_offers'])){
            if(empty($result['uniform_variants']))$result['enable_variant_pricing']=true;
            else unset($result['variant_offers']);
        }
        return $result;
    }
    public static function issue(string $status,string $message):string
    {
        if(!in_array($status,['failed','review'],true))return $status;
        foreach([
            'HTTP 403'=>'access_denied','robots.txt'=>'robots_denied','HTTP 429'=>'rate_limited',
            'Chybí přesná nabídka varianty'=>'incomplete_variant_offers','Rozporné partnerské nabídky'=>'conflicting_variant_offers',
            'Uložená nabídka'=>'cache_expired','strukturovanou cenu'=>'missing_offer',
            'není jednoznačná'=>'ambiguous_variant','různé ceny'=>'ambiguous_variant',
            'používá varianty'=>'variant_scope','neshodují'=>'variant_identity',
            'záložního'=>'fallback_seller','30 %'=>'price_jump','není skladem'=>'availability',
            'změnil'=>'stale_product','změnily'=>'stale_product',
        ]as$needle=>$code)if(str_contains($message,$needle))return $code;
        return $status;
    }
    /** Offline replay: no network, database, mutations or manually supplied prices. */
    public static function replay(array $report):array
    {
        if(($report['format']??'')!=='dobrodruzi.supplier-price-diagnostics')throw new \InvalidArgumentException('Neplatný diagnostický export.');
        $items=[];$summary=[];
        foreach($report['items']??[]as$item){
            if(($item['active']??true)===false||!in_array($item['status'],['review','failed','queued'],true))continue;
            $snapshot=$item['snapshot']??['products'=>[['name'=>$item['name']]],'variants'=>$item['local_variants']??[],'variantSettings'=>['enabled'=>$item['variants_enabled']??false]];
            $out=['id'=>$item['id'],'product_key'=>$item['product_key'],'name'=>$item['name'],'previous_issue'=>self::issue($item['status'],$item['message']),'attempts'=>[]];
            if(($snapshot['candidates'][0]['kind']??'')==='variant'){
                $out=self::replayVariants($item,$snapshot,$report,$out);
                $summary[$out['issue']]=($summary[$out['issue']]??0)+1;$items[]=$out;continue;
            }
            foreach($item['sources']as$source){
                $cache=$source['cache']??[];$message=$source['robots']['error']??$cache['error']??'Uložená nabídka chybí nebo vypršela.';
                if(!$cache)foreach($item['trace']??[]as$attempt)if(($attempt['seller']??'')===$source['seller'])$message=$attempt['message'];
                if(isset($cache['document'])){
                    try{
                        $result=self::select($cache['document'],$source['resolved_url'],$snapshot,$report['pricing']??[]);
                        $result['fallback']=!$source['selected'];
                        $message=PriceManager::reviewReason($snapshot,$result);
                        $out['price']=$result['price'];$out['source_url']=$result['corrected_url']??$source['resolved_url'];
                        $out['evidence']=$result['identity_evidence'];$out['availability']=$result['availability'];
                        $out['issue']=$message===''?'ready':self::issue('review',$message);
                        if(($source['expires_at']??0)<strtotime($report['exported_at'])){$out['issue']='cache_expired';$message='Uložená nabídka vypršela.';}
                        $out['message']=$message;break;
                    }catch(\InvalidArgumentException $e){$message=$e->getMessage();}
                }
                $out['attempts'][]=['url'=>$source['resolved_url'],'message'=>$message,'issue'=>self::issue('failed',$message)];
            }
            $out['issue']??=self::issue('failed',$message??'');$out['message']??=$message??'';
            $summary[$out['issue']]=($summary[$out['issue']]??0)+1;$items[]=$out;
        }
        return ['format'=>'dobrodruzi.supplier-price-replay','version'=>1,'evaluator_version'=>PriceManager::EVALUATOR_VERSION,'summary'=>$summary,'items'=>$items];
    }
    private static function replayVariants(array $item,array $snapshot,array $report,array $out):array
    {
        $observations=[];$now=(int)strtotime($report['exported_at']);$sources=$item['sources'];
        foreach($sources as $index=>$source){
            $candidate=$source['candidate'];$cache=$source['cache']??[];
            $message=$source['robots']['error']??$cache['error']??'Uložená nabídka chybí nebo vypršela.';
            if(empty($source['robots']['error'])&&isset($cache['document'])&&($source['expires_at']??0)>$now){
                if(!empty($source['redirects']))$candidate['resolved_url']=$source['resolved_url'];
                $proof=VariantOfferEvaluation::observe($cache['document'],$source['resolved_url'],$snapshot,$candidate,(int)($cache['observed_at']??0));
                $observations=array_replace($observations,$proof['observations']);
                $message=$proof['issues'][0]['message']??'Přesné nabídky variant načteny.';
            }
            $out['attempts'][]=['url'=>$source['url'],'message'=>$message,'issue'=>self::issue('failed',$message)];
            $next=$sources[$index+1]['candidate']??null;
            if($next!==null&&$next['partner_id']===$candidate['partner_id'])continue;
            try{
                $result=VariantOfferEvaluation::assemble($snapshot,$observations,(int)$candidate['partner_id'],$now,$report['pricing']??[]);
                $result['fallback']=!$candidate['selected'];$reason=PriceManager::reviewReason($snapshot,$result);
                return $out+['price'=>$result['price'],'source_url'=>$result['url'],'evidence'=>$result['identity_evidence'],
                    'availability'=>$result['availability'],'issue'=>$reason===''?'ready':self::issue('review',$reason),'message'=>$reason];
            }catch(\InvalidArgumentException $e){$message=$e->getMessage();}
        }
        return $out+['issue'=>self::issue('failed',$message),'message'=>$message];
    }
}
