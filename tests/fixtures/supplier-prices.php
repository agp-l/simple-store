<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never{throw new RuntimeException($message);});
$basePath='/';$adminUrl='/admin.php';$csrf='test-token';$error='';$screen='supplier-prices';
$site=['languages'=>['cs'],'default_language'=>'cs'];$pricesReady=true;$pricesError='';$pricesNotice='';$cronKey='';$pricesFilter='';$pricesQuery='';
$pricesSettings=\SimpleStore\Supplier\PricingPolicy::DEFAULTS+['last_tick'=>1792920000,'paused'=>0,'weekly_enabled'=>0,'next_weekly'=>0,'cron_hash'=>''];
$products=[['name'=>'Péřový spacák – velmi dlouhý název <bezpečný>','product_key'=>str_repeat('a',32),'price'=>1000]];
$snapshot=['products'=>[['language'=>'cs','slug'=>'spacak']], 'variantSettings'=>['enabled'=>1]];
$result=['source_url'=>'https://example.test/p','partner_name'=>'Záložní prodávající','price'=>1200,'old_price'=>1000,'observed_at'=>1792920000,'availability'=>'in_stock','fallback'=>true];
$rows=[];foreach(['review','applied','failed','queued']as$i=>$state)$rows[]=['id'=>$i+1,'product_name'=>$products[0]['name'],'status'=>$state,'created_at'=>1792910000,'checked_at'=>$state==='queued'?0:1792920000,'applied_at'=>$state==='applied'?1792920000:0,'next_at'=>1792920000,'mode'=>'normal','message'=>'Cena vyžaduje ověření konkrétní varianty.','data_json'=>json_encode(['snapshot'=>$snapshot,'trace'=>[['seller'=>'Původní obchod','time'=>1792920000,'message'=>'HTTP 404. Cena zůstala.']]]),'result_json'=>in_array($state,['review','applied'])?json_encode($result):null];
$rows[0]['image_path']='images/test.webp';
$rows[2]['current_product']=['language'=>'cs','slug'=>'spacak'];
$rows[2]['sources_changed']=true;$rows[2]['stale_result']=true;
$rows[2]['current_sources']=[['name'=>'Nový partner 4camping','url'=>'https://www.4camping.cz/p/test/#oranzova','selected'=>true]];
$old=json_decode($rows[2]['data_json'],true);$old['trace'][0]['url']='https://www.alza.cz/old.htm';$rows[2]['data_json']=json_encode($old);
$pricesDashboard=['products'=>$products,'rows'=>$rows,'counts'=>['queued'=>8,'review'=>2,'failed'=>1,'applied'=>17],'page'=>1,'total'=>4];
require dirname(__DIR__,2).'/view/admin/layout.php';
