<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Seo\{PublicSite,ProductStructuredData as Product,Sitemap};
use SimpleStore\Rendering\PageRenderer;
$check=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$base=PublicSite::base('https://shop.test/store/','/store/');
$check($base==='https://shop.test/store','Configured subdirectory invalid.');
foreach(['https://evil.test/wrong','https://user:pass@shop.test/store','https://shop.test/store?query=1','javascript:alert(1)']as$invalid)$check(PublicSite::base($invalid,'/store/')==='','Invalid origin accepted.');
$check(PublicSite::absolute($base,'/store/cs/produkt/test','/store/')===$base.'/cs/produkt/test','Subdirectory doubled.');
$robots=PublicSite::robots($base,'/store/',['cs']);
$check(str_contains($robots,'Sitemap: '.$base.'/sitemap.xml')&&str_contains($robots,'Disallow: /store/admin')&&!str_contains($robots,'Disallow: /store/cs/produkt'),'Robots blocks public products.');
$p=['product_key'=>str_repeat('a',32),'slug'=>'test','name'=>'Tent </script><script>alert(1)</script>','brand'=>'Brand','summary'=>'Public description','description'=>'','published'=>1,'price_czk'=>4990,
 'revision_number'=>1,'image_path'=>'images/tent.jpg','stock_status'=>'in_stock','availability_status'=>'out_of_stock','sale_mode'=>'assisted',
 'supplier_price_czk'=>1234,'partner_id'=>99,'private_note'=>'do not publish','sizes'=>'','category'=>'stany','subcategory'=>'','details_json'=>null];
$url=$base.'/cs/produkt/test';$data=Product::build($p,$url,$base);
$check($data['offers']['price']==='4990.00'&&$data['offers']['availability']==='https://schema.org/OutOfStock','Public price/real availability differ.');
$json=Product::json($data);$check(!str_contains($json,'</script>')&&!str_contains($json,'1234')&&!str_contains($json,'private_note'),'Script injection or private cost leaked.');
$check(Product::build(array_replace($p,['published'=>0]),$url,$base)===[],'Draft exposed.');
$make=static fn(string $id,string $color,bool $enabled,int $quantity,?int $price,string $image):array=>['id'=>str_repeat($id,32),'enabled'=>$enabled,'choices'=>['Barva'=>$color,'Velikost'=>'M'],'sku'=>'SKU-'.$id,'quantity'=>$quantity,'price_czk'=>$price,'image_path'=>$image,'stock_status'=>'in_stock'];
$p['variant_inventory']=['enabled'=>true,'items'=>[$make('b','Modrá',true,3,5200,'images/blue.jpg'),$make('c','Červená',true,0,null,''),$make('d','Zelená',false,5,1,'')]];
$data=Product::build($p,$url,$base);
$check($data['@type']==='ProductGroup'&&count($data['hasVariant'])===2,'Disabled variant indexed.');
$check($data['hasVariant'][0]['offers']['price']==='5200.00'&&$data['hasVariant'][1]['offers']['price']==='4990.00','Variant price inheritance wrong.');
$check($data['hasVariant'][0]['image'][0]===$base.'/images/blue.jpg'&&$data['hasVariant'][1]['offers']['availability']==='https://schema.org/OutOfStock','Variant image/availability wrong.');
$check($data['hasVariant'][0]['url']===$url.'?variant='.str_repeat('b',32),'Variant cannot be selected directly.');
$check(!str_contains(Product::json($data),'inventoryLevel'),'Internal assisted quantity cap published as physical stock.');
$xml=Sitemap::xml(['https://shop.test/cs?a=1&b=2']);$check(str_contains($xml,'a=1&amp;b=2')&&!str_contains($xml,'lastmod'),'Invalid XML or fabricated modification date.');
$renderer=new PageRenderer(dirname(__DIR__).'/view');
$args=['product'=>$p,'basePath'=>'/store/','publicBaseUrl'=>$base,'canonicalUrl'=>$url];
ob_start();$renderer->render('product-record',$args);$html=ob_get_clean();
$check(str_contains($html,'application/ld+json')&&str_contains($html,'rel="canonical"'),'Metadata missing from rendered head.');
foreach([['privatePage'=>true],['editMode'=>true],['noIndex'=>true]]as$mode){
 ob_start();$renderer->render('product-record',$mode+$args);$html=ob_get_clean();
 $check(!str_contains($html,'application/ld+json')&&!str_contains($html,'rel="canonical"')&&str_contains($html,'noindex'),'Private/filtered page leaked metadata.');
}
ob_start();$renderer->render('not-found',['canonicalUrl'=>$url],404);$html=ob_get_clean();
$check(str_contains($html,'noindex')&&!str_contains($html,'rel="canonical"'),'404 indexed.');
echo "Public discovery: public prices, exact variant links, stock, privacy, XSS, canonical URLs and sitemap XML passed.\n";
