<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never{throw new RuntimeException($message);});
$basePath='/';$adminUrl='/admin/';$csrf='fixture';$error='';$screen='settings';$settingsTab='notifications';$settingsError='';$form=[];
$site=['languages'=>['cs'],'default_language'=>'cs'];
$ntfy=['enabled'=>true,'topic'=>'dobrodruzi-fixture-'.str_repeat('a',40),'token'=>'tk_'.str_repeat('b',100),'priority'=>3,'include_contact'=>false];
$ntfyNotice='Testovací zpráva přijata serverem.';
$ntfyRows=[['order_id'=>42,'order_number'=>'DB-26-1234567890','created_at'=>'2026-11-06 12:30:00','state'=>'uncertain','attempts'=>1,'last_error'=>'Spojení skončilo bez potvrzení. Před opakováním zkontroluj telefon.']];
require dirname(__DIR__,2).'/view/admin/layout.php';
