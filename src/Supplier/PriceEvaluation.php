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
}
