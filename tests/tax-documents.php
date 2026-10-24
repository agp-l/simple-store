<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never {throw new RuntimeException($message);});
$invoice=['document_number'=>'F2026-1','order_number'=>'DB-1','issue_date'=>'2026-10-01',
    'seller_json'=>'{"name":"Original <seller>","ico":"12345678","street":"Ulice 1","postal_code":"11000","city":"Praha"}',
    'buyer_json'=>'{"name":"Eva & syn","email":"buyer@example.test"}'];
$selectedCorrection=['document_number'=>'O-1-1','issue_date'=>'2026-10-24','amount_czk'=>'12.25','reason'=>'Vrácení služby <test>','invoice_json'=>json_encode($invoice,JSON_THROW_ON_ERROR),'voided_at'=>null,'void_reason'=>''];
foreach ([null,'2026-10-25 10:00:00'] as $voided) {
    $selectedCorrection['voided_at']=$voided;$selectedCorrection['void_reason']='Oprava <test>';
    ob_start();require dirname(__DIR__).'/view/admin/correction-print.php';$html=ob_get_clean();
    if (!str_contains($html,'−12,25 Kč') || !str_contains($html,'Original &lt;seller&gt;') || !str_contains($html,'Eva &amp; syn') || !str_contains($html,'F2026-1') ||
        (str_contains($html,'VYŘAZENÝ DOKLAD') !== ($voided!==null))) throw new RuntimeException('Credit document lost snapshot, cents or void marker.');
}
$taxSettings=['name'=>'Shop','ico'=>'12345678','street'=>'Ulice','postal_code'=>'11000','city'=>'Praha'];
$fundsOrder=['order_number'=>'DB-1','customer_email'=>'buyer@example.test','total_czk'=>1800,'principal_czk'=>1678];
$fundsEntries=[['entry_date'=>'2026-10-24','purpose'=>'principal_return','counterparty'=>'<customer>','reference'=>'BANK<&>','direction'=>'expense','amount_czk'=>'12.25']];
$fundsTotals=['held'=>'-12.25'];
ob_start();require dirname(__DIR__).'/view/admin/funds-print.php';$html=ob_get_clean();
if (!str_contains($html,'12,25 Kč') || !str_contains($html,'&lt;customer&gt;') || !str_contains($html,'Průběžné vyúčtování')) throw new RuntimeException('Settlement print lost cents or escaping.');
restore_error_handler();
echo "Tax document rendering passed.\n";
