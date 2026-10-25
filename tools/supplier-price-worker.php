<?php
declare(strict_types=1);
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Supplier\{PriceManager,PriceEvaluation};
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
try{
    $command=$argv[1]??'--tick';
    if($command==='--help'){
        echo "Usage: php tools/supplier-price-worker.php [--tick | --diagnostics | --retry-cached | --replay export.json]\n";exit;
    }
    if($command==='--replay'&&count($argv)===3){
        $file=$argv[2];
        if(!is_file($file)||filesize($file)>50*1024*1024)throw new InvalidArgumentException('Invalid local diagnostic file.');
        $result=PriceEvaluation::replay(json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR));
    }else{
        if(!in_array($command,['--tick','--diagnostics','--retry-cached'],true)||count($argv)>2)throw new InvalidArgumentException('Unknown command. Use --help.');
        $db=ConnectionFactory::create(require dirname(__DIR__).'/config/database.php');$manager=new PriceManager($db);
        $result=match($command){'--diagnostics'=>$manager->diagnostics(),'--retry-cached'=>$manager->retry('problems','rapid',false),default=>$manager->tick('cli')};
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){fwrite(STDERR,($e instanceof InvalidArgumentException?$e->getMessage():"Supplier check failed. Open the administration for details.")."\n");exit(1);}
