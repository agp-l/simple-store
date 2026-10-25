<?php
declare(strict_types=1);
use SimpleStore\Supplier\PriceManager;

$screen='supplier-prices';$priceManager=new PriceManager($db);$pricesReady=$priceManager->installed();
$pricesAutoStart=false;$pricesError='';$pricesNotice='';$cronKey='';
if($method==='POST'&&$pricesReady){
    try{
        if($action==='supplier-price-start'){
            $result=$priceManager->enqueue(is_string($_POST['scope']??null)?$_POST['scope']:'',is_string($_POST['speed']??null)?$_POST['speed']:'',is_string($_POST['product_key']??null)?$_POST['product_key']:'');
            $pricesAutoStart=$result['added']>0;
            $pricesNotice='Do fronty přidáno: '.$result['added'].'. Již ve frontě nebo kontrolováno za posledních 24 hodin: '.$result['daily'].'. Bez použitelného odkazu/prodávajícího: '.$result['unconfigured'].'.';
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
$pricesSettings=$pricesReady?$priceManager->settings():[];
$pricesDashboard=$pricesReady?$priceManager->dashboard($pricesFilter,$pricesQuery,$pricesPage):[];
