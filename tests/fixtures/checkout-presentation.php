<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use SimpleStore\Checkout\AssistedPurchase;
use SimpleStore\Pricing\BitcoinPriceDisplay;
use SimpleStore\Rendering\PageRenderer;

$page = $argv[1] ?? 'review';
$seller = ['name'=>'Gramino s.r.o.', 'ico'=>'05769884', 'address'=>'Frýdecká 424, 739 61 Třinec',
    'email'=>'info@example.test', 'terms_url'=>'https://example.test/podminky'];
$items = [];
foreach ([['Boty na dlouhé cesty',1800,1678,2], ['Drobný doplněk',5,4,1], ['Ponožky na výlety',200,null,1]] as $i=>$values) {
    [$name,$price,$cost,$quantity] = $values;
    $items[] = ['name'=>$name,'slug'=>'produkt-'.$i,'line_id'=>str_repeat((string)($i+1),64),
        'product_key'=>str_repeat((string)($i+1),32),'quantity'=>$quantity,'unit_price_czk'=>$price,
        'line_total_czk'=>$quantity*$price,'image_path'=>'images/product-placeholder.png','options'=>[],'issue'=>''] +
        ($cost === null ? [] : AssistedPurchase::snapshot(['mode'=>'assisted','supplier_price_czk'=>$cost,'seller'=>$seller],$price));
}
if (in_array('--legacy', $argv, true)) {
    $items = [$items[0]];
    $items[0]['delivery_note'] = 'Původně sjednané dodání do 5 pracovních dnů.';
}
$priceDisplay = in_array('--no-btc', $argv, true) ? new BitcoinPriceDisplay(null) :
    new BitcoinPriceDisplay(['rate'=>2000000.0,'updated_at'=>'2026-10-20 12:00:00']);
$delivery = ['method'=>'gls_home','name'=>'Eva Nováková','email'=>'eva@example.test','phone'=>'123456789',
    'street'=>'Ukázková 12','city'=>'Praha','postal_code'=>'11000','country'=>'CZ'];
$order = ['id'=>1,'order_number'=>'202600123','created_at'=>'2026-10-20 12:00:00','payment_status'=>'paid',
    'payment_method'=>'btcpay','status'=>'processing','items'=>$items,'subtotal_czk'=>3805,'shipping_czk'=>79,
    'total_czk'=>3884,'shipping'=>$delivery + ['label'=>'GLS domů']];
if ($page === 'account') {
    $site=['default_language'=>'cs']; $basePath='/'; $accountUrl='/account.php'; $screen='account';
    $registrationAllowed=true; $error=''; $csrf='test-token'; $chrome=[];
    $user=['id'=>1,'email'=>'eva@example.test','display_name'=>'Eva','phone'=>'']; $section='orders';
    $addresses=[]; $orders=[$order]; $orderDetail=$order; $orderHistory=false; $orderTrackingUrl=null;
    $orderOffset=0; $orderPage=['nextOffset'=>null];
    require dirname(__DIR__,2).'/view/account/layout.php';
} else {
    (new PageRenderer(dirname(__DIR__,2).'/view'))->render($page, [
        'basePath'=>'/','language'=>'cs','cartUrl'=>'/cs/kosik','checkoutUrl'=>'/cs/pokladna','cartToken'=>'test-token',
        'shippingOptions'=>[['code'=>'gls_home','label'=>'GLS domů','group'=>'home','price_czk'=>79,'locator_url'=>'']],
        'selectedShippingPrice'=>79,'shippingConfigured'=>true,'delivery'=>$delivery,'step'=>$page,
        'checkoutReady'=>true,'bankConfigured'=>true,'paymentMethod'=>'bank_transfer','paymentStepReady'=>true,
        'termsUrl'=>'/cs/obchodni-podminky','assistedTermsUrl'=>'/cs/obchodni-podminky-asistovaneho-nakupu',
        'hasAssisted'=>true,'hasOwnSale'=>true,'reviewToken'=>'test-review','order'=>$order,'priceDisplay'=>$priceDisplay,
        'checkout'=>['items'=>$items,'count'=>4,'subtotal_czk'=>3805,'issues'=>[],'can_continue'=>true],
    ]);
}
