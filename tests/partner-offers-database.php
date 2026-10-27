<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Product\{ProductRepository,ProductImport,ProductBulkTransfer,PartnerSourceTransfer,PurchasePartnerRepository,ProductSupplierLinkRepository,ProductVariantSupplierRepository};
use SimpleStore\Supplier\{PriceManager,PartnerOfferCatalog};
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);$database='simple_store_offer_catalog_'.bin2hex(random_bytes(5));$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);
$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $run):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe import accepted.');};
try {
    (new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
    $products=new ProductRepository($db,['cs'],new \SimpleStore\Category\CategoryRepository($db),50,new \SimpleStore\Product\ProductStockRepository($db));$partners=new PurchasePartnerRepository($db);$common=new ProductSupplierLinkRepository($db);$variantLinks=new ProductVariantSupplierRepository($db);
    foreach (['Gramino'=>'https://gramino.cz','Pod 7 kilo'=>'https://www.pod7kilo.cz'] as $name=>$url) $partners->save(0,['name'=>$name,'website_url'=>$url]);
    $make=static function(string $slug,bool $variant)use($products):string {
        $data=['format'=>ProductImport::FORMAT,'language'=>'cs','product'=>['name'=>'Patizon D590','slug'=>$slug,'category_path'=>'batohy','price_czk'=>3000]];
        if($variant)$data['product']['variants']=['enabled'=>true,'items'=>[['choices'=>['Barva'=>'Blue','Velikost'=>'M']],['choices'=>['Barva'=>'Red','Velikost'=>'M']]]];
        return $products->saveRevision(ProductImport::fields($data))['product_key'];
    };
    $simple=$make('default-offer',false);$variant=$make('variant-offer',true);
    $transfer=new ProductBulkTransfer($db,$products,['cs']);
    $data=['format'=>PartnerSourceTransfer::FORMAT,'products'=>[]];
    foreach([$simple,$variant]as$key)$data['products'][]=['product_key'=>$key,'language'=>'cs','sources'=>[
        ['label'=>'Gramino','url'=>'https://gramino.cz/p/'.$key],['label'=>'Pod 7 kilo','url'=>'https://www.pod7kilo.cz/p/'.$key]]];
    $plan=$transfer->prepare(json_encode($data),false,false);
    foreach($plan as $item){$check($item['status']==='pending',$item['message']);$transfer->import($item);}
    $check(count($common->forProduct($simple))===2 && count($variantLinks->forProduct($variant))===4,'Default or variant sources missing.');
    $before=$variantLinks->forProduct($variant);
    foreach($transfer->prepare(json_encode($data),false,false)as$item)$transfer->import($item);
    $check($before===$variantLinks->forProduct($variant) && count($common->forProduct($simple))===2,'Repeated import duplicated or edited sources.');
    $bad=$data;$bad['products'][0]['sources'][0]['price_czk']=1;
    $check($transfer->prepare(json_encode($bad),false,false)[0]['status']==='error','Imported price was trusted.');
    $bad=$data;$bad['products'][0]['sources'][0]['url']='https://gramino.cz.evil.test/p/x';
    $check($transfer->prepare(json_encode($bad),false,false)[0]['status']==='error','Suffix host accepted.');
    $bad=$data;$bad['products'][0]['sources'][0]['url']='https://127.0.0.1/private';
    $check($transfer->prepare(json_encode($bad),false,false)[0]['status']==='error','Private URL accepted.');
    $v=$products->current($variant,'cs')['variant_inventory']['items'][0];
    $exact=['format'=>PartnerSourceTransfer::FORMAT,'products'=>[['product_key'=>$variant,'sources'=>[['label'=>'Exact','url'=>'https://gramino.cz/p/exact?v_id=x','variant_key'=>$v['id'],'choices'=>$v['choices']]]]]];
    $pending=$transfer->prepare(json_encode($exact),false,false)[0];
    $db->update('shop_product_variants',['enabled'=>0],'product_key=%s AND variant_key=%s',$variant,$v['id']);
    $reject(fn()=>$transfer->import($pending));
    $db->update('shop_product_variants',['enabled'=>1],'product_key=%s AND variant_key=%s',$variant,$v['id']);
    $check(count($variantLinks->forProduct($variant))===4,'Stale import left partial sources.');
    $now=1800000000;$requests=[];
    $transport=static function(string $url)use(&$requests,&$now,$variant):array {
        $requests[]=[$url,$now];
        if(str_ends_with($url,'/robots.txt'))return ['code'=>200,'headers'=>[],'body'=>"User-agent: *\nCrawl-delay: 10\n"];
        $base=preg_replace('~^https://gramino.cz/api/md/p/~','https://gramino.cz/p/',$url);
        $node=['@type'=>'Product','name'=>'Patizon D590','offers'=>['@type'=>'Offer','url'=>$base,'price'=>str_contains($base,'gramino')?1000:1200,'priceCurrency'=>'CZK','availability'=>'https://schema.org/InStock']];
        if(str_contains($base,$variant)) {
            $items=[];
            foreach(['Blue','Red']as$i=>$color)$items[]=['@type'=>'Product','name'=>'Patizon D590 – '.$color.', M','color'=>$color,'size'=>'M','offers'=>['@type'=>'Offer','url'=>$base.'?v_id='.strtolower($color),'price'=>1000+$i*200+(str_contains($base,'pod7kilo')?50:0),'priceCurrency'=>'CZK','availability'=>'https://schema.org/InStock']];
            $node=['@type'=>'ProductGroup','name'=>'Patizon D590','url'=>$base,'hasVariant'=>$items];
        }
        return ['code'=>200,'headers'=>[],'body'=>'<script type="application/ld+json">'.json_encode($node).'</script>'];
    };
    $manager=new PriceManager($db,$transport,static function()use(&$now):int{return $now;});
    $productsBefore=[$products->current($simple,'cs'),$products->current($variant,'cs')];
    $check($manager->enqueueOffers()===2,'Not all products queued.');
    for($i=0;$i<50;$i++){ $before=count($requests);$manager->tick();$check(count($requests)-$before<=1,'More than one request in a tick.');$now+=25; }
    $catalog=new PartnerOfferCatalog($db,$now);$report=$catalog->report();
    $check($report['queued_products']===0,'Offer scan did not complete.');
    $check(count($db->query('SELECT * FROM shop_supplier_offers WHERE status=%s','verified'))===6,'All exact alternative offers were not persisted: '.json_encode($report));
    $check($productsBefore===[$products->current($simple,'cs'),$products->current($variant,'cs')],'Offer comparison changed product price, stock or seller.');
    $check($report['summary']['multiple_partners']===2 && $report['summary']['variants']===3,'Product and variant denominators mixed.');
    foreach($report['summary']['partners']as$row)$check($row['verified_products']===2 && $row['complete_products']===2 && $row['verified_variants']===3,'Coverage report discarded/multiplied a product.');
    $check($manager->enqueueOffers()===0,'Repeated scan bypassed daily freshness.');
    $db->update('shop_supplier_offer_scans',['checked_at'=>$now+86000],'1=1'); // a later local cache replay
    $check((new PartnerOfferCatalog($db,$now+86401))->schedule()===2,'Expired observations were not queued according to their original fetch time.');
    $counts=array_count_values(array_column($requests,0));foreach($counts as$count)$check($count===1,'Shared URL fetched more than once in 24 hours.');
    $expired=(new PartnerOfferCatalog($db,$now+86401))->report();
    foreach($expired['summary']['partners']as$row)$check($row['available_variants']===0,'Expired stock counted as available.');
    $old=$common->forProduct($simple)[0];$common->remove($simple,(int)$old['id']);
    $after=(new PartnerOfferCatalog($db,$now))->report();
    $p=array_values(array_filter($after['products'],static fn(array $p):bool=>$p['product_key']===$simple))[0];
    $check(count($p['offers'])===1 && $p['offers'][0]['status']==='unverified','Removed or changed source retained valid evidence.');
    echo "Partner offers: additive imports, exact variants, alternatives, daily shared request budget, freshness, removal and stock/seller isolation passed.\n";
} finally { $db->disconnect();$admin->query('DROP DATABASE %b',$database); }
