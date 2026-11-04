<?php
declare(strict_types=1);
$a = str_repeat('a',32); $b = str_repeat('b',32); $c = str_repeat('c',32); $d = str_repeat('d',32); $e = str_repeat('e',32);
$v1 = str_repeat('1',32); $v2 = str_repeat('2',32); $v3 = str_repeat('3',32);
$product = static fn(string $key, int $price, string $language = 'cs', int $published = 1): array =>
    ['product_key'=>$key,'language'=>$language,'published'=>$published,'price_czk'=>$price,'stock_status'=>'in_stock'];
return [
    'products'=>[$product($a,1000),$product($a,9000,'en'),$product($b,2000,'en'),$product($b,500,'cs',0),$product($c,300),$product($d,900,'cs',0),$product($e,999)],
    'inventory'=>[['product_key'=>$a,'available_quantity'=>3],['product_key'=>$b,'available_quantity'=>999]],
    'sales'=>[['product_key'=>$a,'mode'=>'own','supplier_price_czk'=>600,'variant_prices_json'=>'{}'],
        ['product_key'=>$b,'mode'=>'assisted','supplier_price_czk'=>1200,'variant_prices_json'=>json_encode([$v2=>1500])]],
    'settings'=>[['product_key'=>$b,'enabled'=>1],['product_key'=>$e,'enabled'=>1]],
    'variants'=>[
        ['product_key'=>$b,'variant_key'=>$v1,'enabled'=>1,'price_czk'=>null,'available_quantity'=>8,'stock_status'=>'in_stock'],
        ['product_key'=>$b,'variant_key'=>$v2,'enabled'=>1,'price_czk'=>2500,'available_quantity'=>0,'stock_status'=>'on_order'],
        ['product_key'=>$b,'variant_key'=>$v3,'enabled'=>0,'price_czk'=>1,'available_quantity'=>999,'stock_status'=>'in_stock'],
    ],
];
