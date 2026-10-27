<?php
declare(strict_types=1);

use SimpleStore\Product\ProductRemoval;
use SimpleStore\Media\ProductMediaCleanup;

// The shared admin entry point has checked administrator authentication and POST CSRF.
$screen='product-removal'; $removalError=''; $removalPlan=null; $removalResult=null; $removalJobs=[]; $removalPreviewId='';
$removal=new ProductRemoval($db,dirname(__DIR__,2),$site['languages']);
$cleanup=new ProductMediaCleanup($db,dirname(__DIR__,2));
try {
    if (!$removal->installed()) throw new InvalidArgumentException('Nejdřív aktualizuj tabulky v sekci Databáze.');
    if ($method==='POST' && ($_POST['phase']??'preview')==='confirm') {
        $previewId=$_POST['preview_id']??'';
        if (($_POST['confirm']??'')!=='1' || !is_string($previewId) || !isset($_SESSION['product_removal_previews'][$previewId])) throw new InvalidArgumentException('Nejdřív připrav náhled a potvrď úplné smazání produktu.');
        $plan=$_SESSION['product_removal_previews'][$previewId];
        $removalPlan=$plan; $removalPreviewId=$previewId;
        $removalResult=$removal->delete($plan); unset($_SESSION['product_removal_previews'][$previewId]);
        // Return to a fresh catalog GET; refreshing must never repeat the deletion POST.
        header('Location: '.$basePath.$plan['language'].'?manage=1&deleted=1#produkty',true,303); exit;
    } elseif ($method==='POST' && ($_POST['phase']??'')==='cleanup') {
        $key=$_POST['key']??null;
        if (!is_string($key)) throw new InvalidArgumentException('Neplatný úklid produktu.');
        $removalResult=$cleanup->run($key);
    } elseif ($method==='POST') {
        $key=$_POST['key']??null; $lang=$_POST['language']??null;
        $revision=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT);
        if (!is_string($key) || !is_string($lang) || !is_int($revision)) throw new InvalidArgumentException('Otevři aktuální produkt a připrav smazání znovu.');
        $removalPlan=$removal->preview($key,$lang,$revision);
        $removalPreviewId=bin2hex(random_bytes(16));
        $_SESSION['product_removal_previews']??=[];
        if (count($_SESSION['product_removal_previews'])>=10) array_shift($_SESSION['product_removal_previews']);
        $_SESSION['product_removal_previews'][$removalPreviewId]=$removalPlan;
    } elseif (isset($_GET['key'])) {
        if (!is_string($_GET['key'])) throw new InvalidArgumentException('Neplatný produkt.');
        $removalResult=$cleanup->state($_GET['key']);
    }
    foreach ($db->query('SELECT product_key FROM shop_product_media_cleanup ORDER BY updated_at DESC LIMIT 100') as $job) $removalJobs[]=$cleanup->state($job['product_key']);
} catch (InvalidArgumentException $e) { $removalError=$e->getMessage(); http_response_code(422); }
