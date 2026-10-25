<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never{throw new RuntimeException($message);});
$basePath='/';$adminUrl='/admin.php';$csrf='test-token';$error='';$screen='product-import';
$site=['languages'=>['cs'],'default_language'=>'cs'];$language='cs';$importTab='families';$importError=$transferError=$familyError='';$familyMessages=[];
$familyPreview=['plans'=>[['target'=>str_repeat('a',32),'name'=>'Warmpeace Viking 300','managed_prices'=>true,'slug'=>'warmpeace-viking-300','members'=>[['name'=>'Warmpeace Viking 300 – 170 cm'],['name'=>'Warmpeace Viking 300 – 195 cm']],
 'variants'=>[['id'=>'first','choices'=>['Barva'=>'Modrá','Velikost'=>'170 cm','Zip'=>'Levá strana'],'quantity'=>10,'price_czk'=>4800],['id'=>'second','choices'=>['Barva'=>'Zelená','Velikost'=>'195 cm','Zip'=>'Pravá strana'],'quantity'=>10,'price_czk'=>5300]],'costs'=>['first'=>4690,'second'=>5190],'previous_prices'=>['first'=>4800,'second'=>5300]]],
 'blocked'=>[['name'=>'Jiný model <bezpečný>','message'=>'Nelze doložit přesnou variantu.']]];
$familyHistory=[['id'=>1,'created_at'=>'2026-10-25 08:00:00','undone'=>0]];
require dirname(__DIR__,2).'/view/admin/layout.php';
