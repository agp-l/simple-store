<?php
declare(strict_types=1);
use SimpleStore\Supplier\PriceManager;

$screen='supplier-prices';$priceManager=new PriceManager($db);$pricesReady=$priceManager->installed();
$pricesAutoStart=false;$pricesError='';$pricesNotice='';$cronKey='';
if ($method === 'GET' && $pricesReady && ($_GET['format'] ?? '') === 'variant-link-plan') {
    $key = is_string($_GET['product_key'] ?? null) ? $_GET['product_key'] : '';
    if ($key !== '' && !preg_match('/^[a-f0-9]{32}$/D', $key)) { http_response_code(400); exit; }
    $report = (new \SimpleStore\Supplier\VariantLinkDiscovery($db, time()))->report($key);
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}
if ($method === 'GET' && ($_GET['format'] ?? '') === 'variant-links') {
    $products = new \SimpleStore\Product\ProductRepository($db, $site['languages']);
    $rows = $db->query('SELECT product_key,language,name FROM shop_product_revisions WHERE active_product_key IS NOT NULL ORDER BY product_key,language');
    $body = (new \SimpleStore\Product\ProductVariantSupplierTransfer($db, $products, $site['languages']))->export($rows);
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo $body; exit;
}
if($method==='GET'&&$pricesReady&&($_GET['format']??'')==='json'){
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    echo json_encode($priceManager->diagnostics(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
if($method==='POST'&&$pricesReady){
    try{
        if($action==='supplier-variant-links-settings'){
            $enabled=($_POST['enabled']??'')==='1';$priceManager->configureVariantLinks($enabled);$pricesAutoStart=$enabled;
            $pricesNotice=$enabled?'Automatické párování přesných odkazů je zapnuté. Výsledky budou u jednotlivých variant.':'Automatické párování odkazů je vypnuté.';
        }elseif($action==='supplier-variant-links-export'){
            $report=(new \SimpleStore\Supplier\VariantLinkDiscovery($db,time()))->report();
            header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
            header('Content-Disposition: attachment; filename="variant-link-plan.json"');
            echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
        }elseif($action==='supplier-price-start'){
            $scope=is_string($_POST['scope']??null)?$_POST['scope']:'';$speed=is_string($_POST['speed']??null)?$_POST['speed']:'';
            $result=in_array($scope,['review','failed','problems'],true)?$priceManager->retry($scope,$speed,($_POST['fresh']??'')==='1'):
                $priceManager->enqueue($scope,$speed,is_string($_POST['product_key']??null)?$_POST['product_key']:'');
            $pricesAutoStart=$result['added']>0;
            $pricesNotice='Do fronty přidáno: '.$result['added'].'. Již ve frontě nebo vyčerpaný denní limit: '.$result['daily'].'. Bez použitelného odkazu/prodávajícího: '.$result['unconfigured'].'.';
        }elseif($action==='supplier-price-probe'){
            $id=filter_var($_POST['job_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new InvalidArgumentException('Neplatná kontrola.');
            $result=$priceManager->probe($id);
            if(($_POST['format']??'')==='json'){
                header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
                echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
            }
            if($result['ok']===false)$pricesError=$result['message'];else $pricesNotice=$result['message'];
        }elseif($action==='supplier-price-retry'){
            $id=filter_var($_POST['job_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new InvalidArgumentException('Neplatná kontrola.');
            $result=$priceManager->retry('one','rapid',($_POST['fresh']??'')==='1',$id);$pricesAutoStart=$result['added']>0;
            $pricesNotice='K opakování přidáno: '.$result['added'].'. Vyčerpaný limit dodatečných načtení: '.$result['daily'].'.';
        }elseif($action==='supplier-price-pricing'){
            $priceManager->configurePricing($_POST);$pricesNotice='Cenotvorba uložena. Použije se při příštím převzetí nebo ověření dodavatelské ceny.';
        }elseif($action==='supplier-price-tempo'){
            $count=$priceManager->queueMode(is_string($_POST['speed']??null)?$_POST['speed']:'');$pricesAutoStart=$count>0;
            $pricesNotice='Tempo upraveno pro '.$count.' čekajících kontrol. Prodlevy a limity dodavatelů zůstávají v platnosti.';
        }elseif($action==='supplier-price-export'){
            $report=$priceManager->diagnostics();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
            header('Content-Disposition: attachment; filename="supplier-price-diagnostics.json"');echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
        }elseif($action==='supplier-price-tick'){
            if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
            $result=$priceManager->tick();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($result,JSON_THROW_ON_ERROR);exit;
        }elseif($action==='supplier-price-settings'){
            $priceManager->configure(($_POST['weekly']??'')==='1',($_POST['paused']??'')==='1');$pricesNotice='Plánování a stav fronty byly uloženy.';
        }elseif($action==='supplier-price-key'){$cronKey=$priceManager->rotateKey();$pricesNotice='Nový klíč cronu je zobrazen pouze nyní. Předchozí adresa přestala platit.';
        }elseif(in_array($action,['supplier-price-apply','supplier-price-dismiss'],true)){
            $id=filter_var($_POST['job_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new InvalidArgumentException('Neplatná kontrola.');
            if($action==='supplier-price-apply'){$priceManager->apply($id,($_POST['variants']??'')==='1');$pricesNotice='Nabídka byla zpracována. Výsledek najdeš v historii.';}
            else{$priceManager->dismiss($id);$pricesNotice='Dosavadní cena zůstala.';}
        }
    }catch(Throwable $exception){
        $pricesError=$exception instanceof InvalidArgumentException?$exception->getMessage():'Operace se nezdařila. Zkus obnovit stránku; uložená fronta zůstává.';
        if($action==='supplier-price-tick'){http_response_code($exception instanceof InvalidArgumentException?409:503);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['error'=>$pricesError]);exit;}
    }
}
$pricesFilter=is_string($_GET['status']??null)?$_GET['status']:'';
$pricesQuery=is_string($_GET['q']??null)?substr(trim($_GET['q']),0,200):'';
$pricesPage=max(1,(int)($_GET['page']??1));
$pricesHistory=($_GET['history']??'')==='1';
$pricesSettings=$pricesReady?$priceManager->settings():[];
$pricesDashboard=$pricesReady?$priceManager->dashboard($pricesFilter,$pricesQuery,$pricesPage,$pricesHistory):[];
