<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Supplier\PartnerCoverage;
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$offer=static fn(string $p,string $v,string $status='verified',?int $quantity=null):array=>['partner_key'=>$p,'variant_key'=>$v,'status'=>$status,'amount'=>'100.00','availability'=>'in_stock','reported_quantity'=>$quantity];
$products=[['variants'=>[['variant_key'=>'small'],['variant_key'=>'large']],'offers'=>[$offer('a','small'),$offer('a','large','verified',1),$offer('b','small','expired')]],['variants'=>[['variant_key'=>'']],'offers'=>[$offer('b','')]]];
$report=PartnerCoverage::summarize($products,[['partner_key'=>'a','name'=>'A'],['partner_key'=>'b','name'=>'B']]);$rows=array_column($report['partners'],null,'partner_key');
$check($report['products']===2 && $report['variants']===3 && $report['multiple_partners']===1,'Product/variant overlap counted incorrectly.');
$check($rows['a']['linked_products']===1 && $rows['a']['complete_products']===1 && $rows['a']['available_variants']===1,'Last piece available or product multiplied.');
$check($rows['b']['linked_products']===2 && $rows['b']['verified_products']===1 && $rows['b']['available_variants']===1,'Expired offer treated as current.');
$products[1]['offers'][]=array_replace($offer('b',''),['amount'=>'200.00']);
$rows=array_column(PartnerCoverage::summarize($products,[['partner_key'=>'b','name'=>'B']])['partners'],null,'partner_key');
$check($rows['b']['verified_variants']===0 && $rows['b']['errors']===1,'Conflicting duplicate source is a usable offer.');
echo "Partner coverage: distinct products, default variants, overlap, freshness, last piece and conflicts passed.\n";
