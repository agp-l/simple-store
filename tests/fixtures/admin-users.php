<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/src/bootstrap.php';
set_error_handler(static function (int $severity,string $message): never { throw new RuntimeException($message); });
$mode=$argv[1]??'detail';
$basePath='/'; $adminUrl='/admin.php'; $csrf='test-token'; $error='';
$site=['languages'=>['cs'],'default_language'=>'cs']; $screen='users';
$usersReady=$mode!=='uninstalled'; $usersError=''; $actorId=1; $method='GET'; $addressId=0;
$search=$roleFilter=$activeFilter=''; $offset=0; $showCreate=$mode==='create';
$listed=['id'=>2,'username'=>'eva.nova','display_name'=>'Eva <script>','email'=>'eva@example.test','phone'=>'+420 777','role'=>'admin','is_active'=>1,'created_at'=>'2026-10-24 12:00:00','order_count'=>1,'revision'=>str_repeat('a',64)];
$usersPage=['items'=>[$listed,array_replace($listed,['id'=>3,'role'=>'customer','display_name'=>'Zákazník s dlouhým jménem','email'=>str_repeat('long',15).'@example.test','is_active'=>0])],'nextOffset'=>25];
$account=$showCreate?null:$listed;
$accountForm=$account??['username'=>'','email'=>'','display_name'=>'','phone'=>'','role'=>'customer','is_active'=>'1'];
$accountOrders=[];
$accountAddresses=[['id'=>4,'label'=>'Domů','recipient'=>'Eva Nová','company'=>'','street'=>'Dlouhá 11','city'=>'Praha','postal_code'=>'11000','country'=>'CZ','phone'=>'+420 777']];
$addressForm=array_fill_keys(['label','recipient','company','street','city','postal_code','country','phone'],''); $addressForm['country']='CZ';
require dirname(__DIR__,2).'/view/admin/layout.php';
