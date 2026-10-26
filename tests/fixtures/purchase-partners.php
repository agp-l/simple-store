<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never{throw new RuntimeException($message);});
use SimpleStore\Product\{PurchasePartnerRepository as Partners, PurchasePartnerTransfer as Transfer};
$mode=$argv[1]??'list';$basePath='/';$adminUrl='/admin.php';$csrf='test-token';$error='';$screen='purchase-partners';
$site=['languages'=>['cs'],'default_language'=>'cs'];$partnersReady=true;$partnerError='';$partnerQuery='';
$data=Transfer::decode(file_get_contents(dirname(__DIR__,2).'/docs/examples/prodavajici-pod7kilo-hudy-alza.json'));
$partnerRows=[];
foreach($data['partners'] as $i=>$partner)$partnerRows[]=$partner+['id'=>$i+1,'version'=>1,'product_count'=>$i===0?3:0];
$partnerRows[0]['name'].=' <safe>';
$partnerUsage=[1=>['a'=>true,'b'=>true,'c'=>true],2=>[],3=>[]];
$partnerForm=$partnerRows[$mode==='delete'?1:0];$partnerMissing=Partners::missing($partnerForm);
$partnerBackupPlan=$mode==='preview'?Transfer::compare($data,[]):null;
$partnerBackupJson=$mode==='error'?'{broken <script>':Transfer::json($data);
$partnerBackupToken='preview-token';$partnerBackupError=$mode==='error'?'Soubor neobsahuje platný JSON.':'';
require dirname(__DIR__,2).'/view/admin/layout.php';
