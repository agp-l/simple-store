<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__,2).'/src/bootstrap.php';
$product=['product_key'=>str_repeat('a',32),'language'=>'cs','revision_number'=>1,
    'slug'=>'old-address','name'=>'Historický batoh','brand'=>'Původní značka',
    'summary'=>'Původní perex','description'=>'Historický popis <script>alert(1)</script>',
    'price_czk'=>1234,'image_path'=>'images/product-placeholder.png','stock_status'=>'on_order',
    'category'=>'batohy','subcategory'=>'','published'=>false,'sizes'=>'',
    'saved_at'=>'2026-10-29 12:00:00','details_json'=>json_encode([
        'gallery'=>['images/batoh.webp'], 'options'=>[['name'=>'Velikost','values'=>['S','M']]],
        'specifications'=>[['name'=>'Hmotnost','value'=>'500 g']],
        'sections'=>[['type'=>'list','heading'=>'Původní vlastnosti','body'=>"Lehký\nOdolný"]],
    ],JSON_THROW_ON_ERROR)];
$data=['basePath'=>'/','language'=>'cs','product'=>$product,'revisionPreview'=>true,'privatePage'=>true,
    'revisionReturnUrl'=>'/cs/produkt/current?edit=1&history=1#product-history',
    'categoryLabels'=>['batohy'=>'Batohy'],'categoryTrail'=>[]];
if (!empty($fixtureDataOnly)) return $data;
if (in_array('--editor',$argv,true)) {
    $_GET['history']='1';
    $data['product']=array_replace($product,['slug'=>'current','name'=>'Aktuální batoh','revision_number'=>2,'price_czk'=>2222]);
    $data=array_replace($data,['revisionPreview'=>false,'canEditProduct'=>true,'editMode'=>true,'editToken'=>'fixture-token',
        'productHistory'=>[['revision_number'=>1,'saved_at'=>$product['saved_at'],'name'=>$product['name'],'active_product_key'=>null]],
        'editorCategories'=>[['path'=>'batohy','title'=>'Batohy']]]);
}
(new \SimpleStore\Rendering\PageRenderer(dirname(__DIR__,2).'/view'))->render('product-record',$data);
