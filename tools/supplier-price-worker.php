<?php
declare(strict_types=1);
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Supplier\PriceManager;
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
try{
    if(count($argv)!==1)throw new RuntimeException('No arguments supported.');
    $db=ConnectionFactory::create(require dirname(__DIR__).'/config/database.php');
    $result=(new PriceManager($db))->tick();echo 'Supplier queue: '.$result['state']."\n";
}catch(Throwable $e){fwrite(STDERR,"Supplier check failed. Open the administration for details.\n");exit(1);}
