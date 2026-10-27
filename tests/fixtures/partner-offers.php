<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
$offersTab=true;
$partners=[['partner_key'=>str_repeat('a',32),'name'=>'Gramino','website_url'=>'https://gramino.cz'],['partner_key'=>str_repeat('b',32),'name'=>'4camping','website_url'=>'https://www.4camping.cz']];
$p=['product_key'=>str_repeat('c',32),'name'=>'Péřový spacák – velmi dlouhý název <bezpečný>','slug'=>'spacak','language'=>'cs','image_path'=>'images/kresadlo.webp',
    'variants'=>[['variant_key'=>'','choices'=>(object)[]]],'offers'=>[]];
foreach($partners as$i=>$partner)$p['offers'][]=['partner_key'=>$partner['partner_key'],'partner_name'=>$partner['name'],'variant_key'=>'',
    'source_url'=>$partner['website_url'].'/p/example','resolved_url'=>'','status'=>$i?'error':'verified','price_czk'=>$i?null:1000,'amount'=>$i?null:'1000.00','availability'=>$i?'unknown':'in_stock','reported_quantity'=>null,'observed_at'=>$i?0:1800000000,'checked_at'=>1800000000,'message'=>$i?'Přesnou barvu zatím nelze potvrdit.':''];
$offersReport=['summary'=>\SimpleStore\Supplier\PartnerCoverage::summarize([$p],$partners),'products'=>[$p],'queued_products'=>3];
require __DIR__.'/supplier-prices.php';
