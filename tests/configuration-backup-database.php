<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use SimpleStore\Admin\ConfigurationBackup as B;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Database\{ConnectionFactory,SchemaUpdater};
use SimpleStore\Accounting\MailSettingsRepository;
$db=ConnectionFactory::create(['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306]);
(new SchemaUpdater($db,dirname(__DIR__).'/database/schema.sql'))->apply();
$suffix=bin2hex(random_bytes(5));$root='backup-'.$suffix;$child=$root.'/child';$slot='backup_'.$suffix;$slug='backup-page-'.$suffix;$product=bin2hex(random_bytes(16));$pageKey='';
$defaults=require dirname(__DIR__).'/config/menus.php';$defaults[$slot]=['source'=>'manual','title'=>'Fixture','items'=>[]];
$service=new B($db,['cs','en'],$defaults);$csrf='test-csrf';$trigger='backup_fail_'.$suffix;
$settingsBefore=$db->query('SELECT * FROM shop_mail_settings');$templatesBefore=$db->query('SELECT * FROM shop_mail_templates');$copyBefore=$db->query('SELECT * FROM shop_site_copy');
$outboxBefore=(int)$db->queryFirstField('SELECT COUNT(*) FROM shop_mail_outbox');
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $fn):void{try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid import accepted.');};
$apply=static function(array $backup)use($service,$csrf):array{$valid=$service->decode(B::json($backup),$backup['kind']);$plan=$service->plan($valid);return $service->apply($valid,B::token($valid,$plan['version'],$csrf),$csrf);};
try {
    $categories=new CategoryRepository($db);$categories->create('cs','',$root,'Backup root',10);$categories->create('cs',$root,'child','Backup child',10);
    $saved=(new ContentRepository($db,['cs','en']))->saveRevision(['type'=>'page','language'=>'cs','slug'=>$slug,'title'=>'Backup page','summary'=>'Keep this summary','body'=>'Keep this exact content','published'=>true,'visible_in_menu'=>false,'menu_order'=>10]);$pageKey=$saved['document_key'];
    $db->insert('shop_product_revisions',['product_key'=>$product,'active_product_key'=>$product,'language'=>'cs','revision_number'=>1,'slug'=>'product-'.$suffix,'active_slug'=>'product-'.$suffix,'name'=>'Unchanged product','category'=>$root,'subcategory'=>'child','price_czk'=>100,'description'=>'Keep product content','image_path'=>'','stock_status'=>'in_stock','published'=>1]);
    $productBefore=$db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s',$product);
    $nav=$service->snapshot('navigation');
    $nav['data']=['cs'=>['categories'=>[$root=>['title'=>'Root restored','sort_order'=>30,'enabled'=>true],$child=>['title'=>'Child restored','sort_order'=>20,'enabled'=>false],$root.'/new'=>['title'=>'New category','sort_order'=>10,'enabled'=>true]],
        'menus'=>[$slot=>['source'=>'categories','parent'=>$root,'include_blog'=>false,'title'=>'Backup menu','items'=>[
            ['id'=>str_repeat('a',16),'parent_id'=>'','label'=>'Home','target_type'=>'path','target'=>'','sort_order'=>10],
            ['id'=>str_repeat('b',16),'parent_id'=>str_repeat('a',16),'label'=>'Child','target_type'=>'category','target'=>$child,'sort_order'=>20],
        ]]],'pages'=>[$slug=>['visible'=>true,'sort_order'=>30]]]];
    $counts=$apply($nav);$assert($counts['new']===1&&$counts['changed']===4,'Import plan count mismatch.');
    $after=$service->snapshot('navigation');$assert(count($after['data']['cs']['menus'][$slot]['items'])===2,'Nested links were lost.');
    $assert($after['data']['cs']['menus'][$slot]['items'][1]['parent_id']===str_repeat('a',16),'Menu parent ID changed.');
    $assert($db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s',$product)===$productBefore,'Import changed product category, variants or history.');
    $page=(new ContentRepository($db))->currentDocument($pageKey,'cs');
    $assert((int)$page['revision_number']===2&&$page['body']==='Keep this exact content'&&(int)$page['published']===1,'Page content/publication lost in menu revision.');
    $counts=$apply($nav);$assert($counts['new']===0&&$counts['changed']===0,'Repeated import is not idempotent.');
    $assert((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_content_revisions WHERE document_key=%s',$pageKey)===2,'Repeated import created a page revision.');
    $assert((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_navigation_menus WHERE slot=%s',$slot)===1,'Repeated import duplicated a menu.');
    $assert((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_catalog_categories WHERE path LIKE %s',$root.'%')===3,'Repeated import duplicated a category.');
    // An older preview must not overwrite an intervening edit.
    $valid=$service->decode(B::json($nav),'navigation');$plan=$service->plan($valid);$token=B::token($valid,$plan['version'],$csrf);
    $categories->update('cs',$root,'More recent title',31,true);
    $reject(fn()=>$service->apply($valid,$token,$csrf));
    $assert($categories->findForAdmin('cs',$root)['title']==='More recent title','A stale preview overwrote the title.');
    $changed=$nav;$changed['data']['cs']['categories'][$root]['title']='Tampered after preview';
    $reject(fn()=>$service->apply($changed,$token,$csrf));
    // A database failure in a later operation rolls back earlier category writes.
    $failed=$nav;$failed['data']['cs']['categories'][$root.'/rollback']=['title'=>'Rollback only','sort_order'=>1,'enabled'=>true];$failed['data']['cs']['menus'][$slot]['title']='Trigger a failure';
    $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON shop_navigation_menus FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'Intentional backup test failure\'');
    try {$apply($failed);throw new RuntimeException('Database failure was ignored.');}catch(\MeekroDBException $expected){}
    $db->query('DROP TRIGGER '.$trigger);
    $assert($categories->findForAdmin('cs',$root.'/rollback')===null,'Failed import left a partial category.');
    $assert($categories->findForAdmin('cs',$root)['title']==='More recent title','Failed import partially changed an existing category.');
    // Sorting keeps every path/product reference and rejects stale pages.
    $rows=$categories->allForAdmin('cs');$positions=array_column($rows,'sort_order','path');$version=CategoryRepository::orderVersion($rows);$positions[$child]=55;
    $categories->saveOrder('cs',$positions,$version);$reject(fn()=>$categories->saveOrder('cs',$positions,$version));
    $assert($db->query('SELECT * FROM shop_product_revisions WHERE product_key=%s',$product)===$productBefore,'Sort changed products.');
    // Partial storefront text import affects only included keys/languages.
    $original=$service->snapshot('site-texts');$text=$original;$text['data']=['cs'=>['hero_title'=>'Text from backup']];$apply($text);
    $current=$service->snapshot('site-texts');$assert($current['data']['cs']['hero_title']==='Text from backup'&&$current['data']['en']===$original['data']['en']&&$current['data']['cs']['footer_intro']===$original['data']['cs']['footer_intro'],'Text scope changed.');
    $counts=$apply($text);$assert($counts['changed']===0,'Text repeated import changed rows.');
    // Text imports never copy credentials, identities or any sending flags.
    $mailSettings=['smtp_password_encrypted'=>'opaque-private-secret','smtp_username'=>'private-login','from_email'=>'preserve@example.test','from_name'=>'Keep brand','automatic_enabled'=>false,'unknown_future_setting'=>'preserve'];
    $db->query('INSERT INTO shop_mail_settings (id,settings_json) VALUES (1,%s) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)',json_encode($mailSettings));
    $db->query('INSERT INTO shop_mail_templates (event_code,enabled,subject,message_text) VALUES (%s,0,%s,%s) ON DUPLICATE KEY UPDATE enabled=0','order','Existing subject','Existing body');
    $mail=$service->snapshot('email-texts');$assert(!str_contains(B::json($mail),'opaque-private-secret')&&!str_contains(B::json($mail),'private-login'),'Credentials in export.');
    $mail['data']=['templates'=>['order'=>['subject'=>'Restored {order_number}','message'=>'Děkujeme za nákup.']],'copy'=>['footer'=>'Imported footer']];$apply($mail);
    $raw=json_decode($db->queryFirstField('SELECT settings_json FROM shop_mail_settings WHERE id=1'),true);$savedCopy=$raw['copy'];unset($raw['copy']);
    $assert($raw===$mailSettings&&$savedCopy['footer']==='Imported footer','Mail text restore changed transport settings.');
    $assert((int)$db->queryFirstField('SELECT enabled FROM shop_mail_templates WHERE event_code=%s','order')===0,'Text import enabled delivery.');
    $counts=$apply($mail);$assert($counts['changed']===0,'Repeated email import is not idempotent.');
    $assert((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_mail_outbox')===$outboxBefore,'Import queued a customer message.');
} finally {
    $db->query('DROP TRIGGER IF EXISTS '.$trigger);
    $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s',$product);
    $db->query('DELETE FROM shop_content_revisions WHERE document_key=%s',$pageKey);
    $db->query('DELETE FROM shop_navigation_menus WHERE slot=%s',$slot);
    $db->query('DELETE FROM shop_catalog_categories WHERE path LIKE %s',$root.'%');
    foreach(['shop_mail_settings'=>$settingsBefore,'shop_mail_templates'=>$templatesBefore,'shop_site_copy'=>$copyBefore]as$table=>$rows){$db->query('DELETE FROM '.$table);foreach($rows as$row)$db->insert($table,$row);}
}
echo "Configuration backup database: repeat import, stale preview, rollback, stable products/pages and mail secrets OK\n";
