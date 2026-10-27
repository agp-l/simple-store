<?php
declare(strict_types=1);

use SimpleStore\Seo\{PublicSite,Sitemap};
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Database\ConnectionFactory;

$site=require __DIR__.'/src/bootstrap.php';
$folder=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/discovery.php'));
$basePath=$folder==='/'||$folder==='.'?'/':'/'.trim($folder,'/').'/';
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
$base=PublicSite::base($site['public_base_url']??'',$basePath);
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'&&($_SERVER['REQUEST_METHOD']??'')!=='HEAD'){
    http_response_code(405);header('Allow: GET, HEAD');exit;
}
if($path===$basePath.'robots.txt'){
    header('Content-Type: text/plain; charset=UTF-8');header('Cache-Control: public, max-age=300');
    echo PublicSite::robots($base,$basePath,$site['languages']);exit;
}
if(!is_string($path)||!preg_match('~^'.preg_quote($basePath,'~').'sitemap(?:-([1-9][0-9]{0,5}))?\.xml$~D',$path,$match)){
    http_response_code(404);exit;
}
try{
    if($base==='')throw new RuntimeException('Missing public URL.');
    $db=ConnectionFactory::create(require __DIR__.'/config/database.php');
    $sitemap=new Sitemap($db,$site['languages']);$pages=$sitemap->pages();
    $url=new UrlManager($basePath,$_SERVER['SCRIPT_NAME']??'/discovery.php',$site['languages'],$site['default_language']);
    if(isset($match[1])){
        $page=(int)$match[1];if($page>$pages){http_response_code(404);exit;}
        $body=Sitemap::xml($sitemap->urls($page,$url,$base));
    }else{
        $links=[];for($page=1;$page<=$pages;$page++)$links[]=$base.'/sitemap-'.$page.'.xml';
        $body=Sitemap::xml($links,true);
    }
    header('Content-Type: application/xml; charset=UTF-8');header('Cache-Control: public, max-age=300');echo $body;
}catch(Throwable $error){
    error_log('Public sitemap unavailable: '.get_class($error));
    http_response_code(503);header('Retry-After: 300');header('Cache-Control: no-store');
}
