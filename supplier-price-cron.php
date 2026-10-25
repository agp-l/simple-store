<?php
declare(strict_types=1);
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Supplier\PriceManager;
header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header("Content-Security-Policy: default-src 'none'");
$method=$_SERVER['REQUEST_METHOD']??'';
if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);exit;}
$key=$_GET['key']??null;
if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)){http_response_code(403);exit;}
require __DIR__.'/src/bootstrap.php';
try{
    $db=ConnectionFactory::create(require __DIR__.'/config/database.php');$manager=new PriceManager($db);
    $settings=$manager->settings();
    if(strlen($settings['cron_hash'])!==64||!hash_equals($settings['cron_hash'],hash('sha256',$key))){http_response_code(403);exit;}
    if($method==='HEAD')exit;
    $result=$manager->tick();echo 'Supplier queue: '.$result['state']."\n";
}catch(Throwable $error){http_response_code(503);echo "Supplier check failed. Open the administration for details.\n";}
