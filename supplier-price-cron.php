<?php
declare(strict_types=1);
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Supplier\PriceManager;
header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header("Content-Security-Policy: default-src 'none'");
$method=$_SERVER['REQUEST_METHOD']??'';
if(!in_array($method,['GET','HEAD','POST'],true)){http_response_code(405);exit;}
$key=$method==='POST'?($_SERVER['HTTP_X_SUPPLIER_WORKER_KEY']??null):($_GET['key']??null);
if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)){http_response_code(403);exit;}
require __DIR__.'/src/bootstrap.php';
try{
    $db=ConnectionFactory::create(require __DIR__.'/config/database.php');$manager=new PriceManager($db);
    $settings=$manager->settings();
    $valid=false;
    if($method==='POST'&&is_file(__DIR__.'/config/supplier-worker.php')){
        $workerKey=require __DIR__.'/config/supplier-worker.php';
        $valid=is_string($workerKey)&&preg_match('/^[a-f0-9]{64}$/D',$workerKey)&&hash_equals($workerKey,$key);
    }elseif($method!=='POST')$valid=strlen($settings['cron_hash'])===64&&hash_equals($settings['cron_hash'],hash('sha256',$key));
    if(!$valid){http_response_code(403);exit;}
    if($method==='HEAD')exit;
    $result=$manager->tick('cron');
    if($method==='POST'){header('Content-Type: application/json; charset=utf-8');echo json_encode($result,JSON_THROW_ON_ERROR);}
    else echo 'Supplier queue: '.$result['state']."\n";
}catch(Throwable $error){http_response_code(503);echo "Supplier check failed. Open the administration for details.\n";}
