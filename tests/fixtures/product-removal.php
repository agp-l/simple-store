<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never { throw new RuntimeException($message); });
$mode=$argv[1]??'preview'; $basePath='/'; $adminUrl='/admin.php'; $csrf='test-token'; $error=''; $screen='product-removal';
$site=['languages'=>['cs','en'],'default_language'=>'cs'];
$removalError=''; $removalPlan=null; $removalResult=null; $removalJobs=[]; $removalPreviewId='preview-token';
$name='Dlouhý název odstraňovaného produktu <bezpečný>'; $key=str_repeat('a',32);
if ($mode==='preview' || $mode==='default') {
    $removalPlan=['key'=>$key,'language'=>'cs','revision'=>2,'name'=>$name,
        'current'=>['cs'=>['name'=>$name,'slug'=>'produkt'], 'en'=>['name'=>'Product','slug'=>'product']],
        'counts'=>['shop_product_revisions'=>5,'shop_product_variants'=>$mode==='default'?0:12,
            'shop_product_supplier_links'=>1,'shop_product_variant_supplier_links'=>24,'shop_supplier_price_jobs'=>34],
        'photos'=>['delete'=>['one','two'],'retain'=>['history']],'orders'=>3];
} else {
    $removalResult=['product_key'=>$key,'name'=>$name,'counts'=>['deleted'=>2,'retained'=>1,'pending'=>$mode==='pending'?1:0]];
    $removalJobs=[$removalResult];
}
require dirname(__DIR__,2).'/view/admin/layout.php';
