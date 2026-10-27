<?php
declare(strict_types=1);
namespace SimpleStore\Seo;

use SimpleStore\Product\{ProductVariants,VariantImages};

/** A public projection of the already decorated storefront model; no supplier costs. */
final class ProductStructuredData
{
    public static function build(array $product,string $url,string $base): array
    {
        if(empty($product['published'])||$url===''||$base==='')return [];
        $common=['@type'=>'Product','name'=>$product['name'],'description'=>$product['summary']??'',
            'url'=>$url,'@id'=>$url.'#product','productID'=>$product['product_key']];
        if(($product['brand']??'')!=='')$common['brand']=['@type'=>'Brand','name'=>$product['brand']];
        $image=self::image($product['image_path']??'',$base);
        if($image!=='')$common['image']=[$image];
        $inventory=$product['variant_inventory']??[];
        if(empty($inventory['enabled'])){
            $common['offers']=self::offer((int)($product['offer_price_czk']??$product['price_czk']),
                $product['availability_status']??$product['stock_status']??'out_of_stock',$url);
            return ['@context'=>'https://schema.org']+$common;
        }
        $variants=[];$axes=[];
        foreach($inventory['items']as$variant){
            if(empty($variant['enabled']))continue;
            $link=$url.'?variant='.rawurlencode($variant['id']);
            $entry=$common;$entry['@id']=$link.'#product';$entry['url']=$link;$entry['productID']=$variant['id'];
            $entry['name']=$product['name'].' — '.implode(' / ',array_values($variant['choices']));
            if(($variant['sku']??'')!=='')$entry['sku']=$variant['sku'];
            $image=self::image(VariantImages::resolve($variant,$inventory['items'],$product['image_path']??''),$base);
            if($image!=='')$entry['image']=[$image];
            foreach($variant['choices']as$name=>$value){
                $property=match(mb_strtolower(trim($name),'UTF-8')){
                    'barva','color','colour'=>'color','velikost','size'=>'size','materiál','material'=>'material',
                    'vzor','pattern'=>'pattern',default=>null};
                if($property!==null){$entry[$property]=$value;$axes['https://schema.org/'.$property]=true;}
                else{$entry['additionalProperty'][]=['@type'=>'PropertyValue','name'=>$name,'value'=>$value];$axes[$name]=true;}
            }
            $entry['offers']=self::offer((int)($variant['price_czk']??$product['price_czk']),ProductVariants::availability($variant),$link);
            $variants[]=$entry;
        }
        if($variants===[])return ['@context'=>'https://schema.org']+$common;
        $common['@type']='ProductGroup';$common['@id']=$url.'#product-group';
        $common['productGroupID']=$product['product_key'];$common['variesBy']=array_keys($axes);$common['hasVariant']=$variants;
        return ['@context'=>'https://schema.org']+$common;
    }
    private static function offer(int $price,string $status,string $url): array
    {
        return ['@type'=>'Offer','url'=>$url,'priceCurrency'=>'CZK','price'=>number_format($price,2,'.',''),
            'availability'=>'https://schema.org/'.match($status){'in_stock'=>'InStock','on_order'=>'BackOrder',default=>'OutOfStock'}];
    }
    private static function image(string $path,string $base): string
    {
        if(str_starts_with($path,'images/'))return $base.'/'.$path;
        return preg_match('~^https://[^\s]+$~D',$path)?$path:'';
    }
    public static function json(array $data): string
    {
        return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    }
}
