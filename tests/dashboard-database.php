<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Admin\StoreDashboard;
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
$db=ConnectionFactory::create(['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306]);
(new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$service=new StoreDashboard($db);$before=$service->load('cs');
$fixture=require __DIR__.'/fixtures/dashboard-data.php';
$map=[];foreach(array_unique(array_column($fixture['products'],'product_key')) as $key)$map[$key]=bin2hex(random_bytes(16));
$keys=array_values($map);$in=implode(',',array_fill(0,count($keys),'%s'));
$tables=['products'=>'shop_product_revisions','inventory'=>'shop_product_inventory','sales'=>'shop_product_sales','settings'=>'shop_product_variant_settings','variants'=>'shop_product_variants'];
try {
    foreach($fixture as $kind=>$rows) foreach($rows as $row) {
        $row['product_key']=$map[$row['product_key']];
        if($kind==='products')$row+=['active_product_key'=>$row['product_key'],'revision_number'=>1,'slug'=>'dashboard-'.$row['product_key'],'active_slug'=>'dashboard-'.$row['product_key'],'name'=>'Dashboard fixture','description'=>'','category'=>'test','image_path'=>'images/product-placeholder.png'];
        if($kind==='variants')$row+=['choices_json'=>'{"Size":"Test"}'];
        $db->insert($tables[$kind],$row);
    }
    $old=$db->queryFirstRow('SELECT * FROM shop_product_revisions WHERE product_key=%s LIMIT 1',$keys[0]);
    unset($old['id']);$old['active_product_key']=null;$old['active_slug']=null;$old['revision_number']=2;$old['price_czk']=999999;
    $db->insert('shop_product_revisions',$old);
    $snapshot=static function()use($db,$tables,$in,$keys):array{
        $rows=[];foreach($tables as $table)$rows[$table]=$db->query('SELECT * FROM '.$table.' WHERE product_key IN ('.$in.') ORDER BY product_key',...$keys);return $rows;
    };
    $saved=$snapshot();$after=$service->load('cs');
    $assert($snapshot()===$saved,'Dashboard changed existing data');
    $delta=$after['groups']['total']['unit_cost']-$before['groups']['total']['unit_cost'];
    $assert($delta===3300,'Database query included historical revisions or lost variant costs');
    $assert($after['groups']['total']['products']-$before['groups']['total']['products']===4,'Database query duplicated translations');
    $assert($after['groups']['own']['quantity']-$before['groups']['own']['quantity']===3,'External stock counted as own');
    $assert($after['groups']['total']['unit_retail']-$before['groups']['total']['unit_retail']===5800,'Wrong published language or disabled variant');
    echo "Dashboard database: consistent read-only totals and history/translation exclusion passed.\n";
} finally {
    foreach(array_reverse($tables) as $table)$db->query('DELETE FROM '.$table.' WHERE product_key IN ('.$in.')',...$keys);
}
