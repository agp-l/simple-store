<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never{throw new RuntimeException($message);});
$case=$argv[1]??'settings';
if($case==='settings'){
    $partnerAssignmentReady=true;
    $counts=['change'=>2,'kept'=>3,'same'=>0,'no_links'=>1,'no_match'=>1,'conflict'=>1];
    $partner=['id'=>1,'name'=>'Partner <test>','priority'=>10,'confirmed'=>0];
    $product=['name'=>'Ultralehký spacák s různými velikostmi <test>','slug'=>'spacak','language'=>'cs'];
    $items=[['product'=>$product,'state'=>'change','current'=>null,'partner'=>$partner,'link'=>['url'=>'https://example.test/produkt']],
        ['product'=>$product,'state'=>'conflict','names'=>['Pod 7 kilo','HUDY']],['product'=>$product,'state'=>'no_links']];
    $partnerAssignmentPlans=['missing'=>['scope'=>'missing','revision'=>'fixture','counts'=>$counts,'items'=>$items],
        'all'=>['scope'=>'all','revision'=>'fixture','counts'=>array_replace($counts,['change'=>3]),'items'=>$items]];
    require __DIR__.'/product-sales-mass.php';
}else{
    $product=['slug'=>'spacak','product_key'=>str_repeat('b',32),'name'=>'Spacák <test>','brand'=>'Patizon',
        'summary'=>'Na hory','description'=>'','published'=>0,'details_json'=>null,'category'=>'spani','subcategory'=>'',
        'price_czk'=>3990,'image_path'=>'images/product-placeholder.png','sizes'=>'','stock_status'=>'in_stock',
        'admin_partner_id'=>null,'admin_partner_name'=>null,'admin_supplier_count'=>0];
    $data=['basePath'=>'/','language'=>'cs','products'=>[$product],'categoryLabels'=>['spani'=>'Spaní'],
        'managingCatalog'=>$case==='catalog','canManageCatalog'=>$case==='catalog','privatePage'=>$case==='catalog',
        'catalogVisibility'=>'draft','catalogSupplierState'=>'no_partner','searchTerm'=>'spacák','searchAction'=>'/cs',
        'managementCategories'=>[['path'=>'spani','title'=>'Spaní']],
        'nextUrl'=>'/cs?manage=1&visibility=draft&supplier_state=no_partner&search=spacak&offset=12'];
    (new \SimpleStore\Rendering\PageRenderer(dirname(__DIR__,2).'/view'))->render('catalog',$data);
}
