<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** HUDY's single-SKU pages publish a model Offer URL and selected SKU in detail data. */
final class HudyVariantIdentity
{
    public static function verify(string $body,string $url,array $product,array $offer):?array
    {
        if(!in_array(strtolower((string)parse_url($url,PHP_URL_HOST)),['hudy.cz','www.hudy.cz'],true))return null;
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        if(array_diff(array_keys($query),['variant','size'])||!isset($query['size'])||!ctype_digit((string)$query['size']))return null;
        $model=preg_replace('/\?.*$/','',$url);
        if(!PriceParser::sameOfferUrl((string)($offer['url']??''),$model))return null;
        $barcode=$product['gtin']??$product['gtin13']??$product['gtin12']??null;
        if(!is_scalar($barcode)||preg_match('/^\d{12,14}$/D',(string)$barcode)!==1)return null;
        preg_match_all('~\bvar push\s*=\s*(\{.*?\});\s*dataLayer\.push\(push\)~s',$body,$pushes);
        $matches=[];
        foreach($pushes[1]as$json){
            $data=json_decode($json,true,32);
            $items=$data['ecommerce']['detail']['products']??[];
            if(($data['ecommerce']['currencyCode']??'')!=='CZK'||count($items)!==1)continue;
            $item=$items[0];
            if((string)($item['id']??'')!==(string)$query['size']||(string)($item['variant_id']??'')!==(string)$barcode||
                !PriceParser::sameProductName(['product_name'=>$product['name']??''],(string)($item['name']??'')))continue;
            $matches[]=$item;
        }
        if(count($matches)!==1)return null;
        $dom=new \DOMDocument();$previous=libxml_use_internal_errors(true);
        try{$dom->loadHTML($body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
        $xpath=new \DOMXPath($dom);$og=$xpath->query('//meta[@property="og:url"]');
        if($og->length!==1||!PriceParser::sameOfferUrl($og->item(0)->getAttribute('content'),$url))return null;
        $confirmed=false;
        foreach($xpath->query('//a[contains(@href,"productInfo-buyBox-add")]')as$link){
            $href=$link->getAttribute('href');parse_str((string)parse_url($href,PHP_URL_QUERY),$cart);
            if(parse_url($href,PHP_URL_PATH)!==parse_url($url,PHP_URL_PATH)||($cart['do']??'')!=='productInfo-buyBox-add')continue;
            if(($cart['productInfo-buyBox-size']??null)!==$query['size']||($cart['size']??null)!==$query['size']||($cart['variant']??null)!==($query['variant']??null))continue;
            $confirmed=true;
        }
        if(!$confirmed)return null;
        return ['method'=>'hudy_selected_sku','verified_url'=>$url,'sku_id'=>$query['size'],'ean'=>(string)$barcode];
    }
}
