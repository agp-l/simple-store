<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Seo\Sitemap;
use SimpleStore\Navigation\UrlManager;
$config=['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306];
$admin=ConnectionFactory::create($config);$database='simple_store_sitemap_'.bin2hex(random_bytes(4));
$admin->query('CREATE DATABASE %b CHARACTER SET utf8mb4',$database);$db=ConnectionFactory::create(array_replace($config,['database'=>$database]));
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
try{
 (new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
 foreach([['public','cs',1,true],['draft','cs',0,true],['old','cs',1,false],['foreign','en',1,true]]as[$slug,$language,$published,$active]){
  $key=bin2hex(random_bytes(16));
  $db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$active?$key:null,'language'=>$language,'revision_number'=>1,'slug'=>$slug,'active_slug'=>$active?$slug:null,'name'=>$slug,'category'=>'stany','price_czk'=>1000,'description'=>'','image_path'=>'','stock_status'=>'in_stock','published'=>$published]);
 }
 foreach([['terms','page',1],['private-page','page',0],['kosik','page',1],['story','post',1]]as[$slug,$type,$published]){
  $key=bin2hex(random_bytes(16));
  $db->insert('shop_content_revisions',['document_key'=>$key,'active_document_key'=>$key,'language'=>'cs','revision_number'=>1,'slug'=>$slug,'active_slug'=>$slug,'type'=>$type,'title'=>$slug,'body'=>'','summary'=>'','published'=>$published]);
 }
 $map=new Sitemap($db,['cs']);$url=new UrlManager('/shop/cs','/shop/index.php');$base='https://shop.test/shop';
 $urls=$map->urls(1,$url,$base);sort($urls);
 $expected=[$base.'/cs',$base.'/cs/produkt/public',$base.'/cs/terms',$base.'/cs/blog/story'];sort($expected);
 $check($urls===$expected,'Sitemap leaked draft/history/unsupported language or a private checkout route.');
 $check($map->pages()===1&&$map->urls(2,$url,$base)===[],'Sitemap paging duplicates rows.');
 echo "Sitemap database: published current revisions, language filter, private routes and paging passed.\n";
}finally{$db->disconnect();$admin->query('DROP DATABASE %b',$database);}
