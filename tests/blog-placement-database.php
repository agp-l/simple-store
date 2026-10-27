<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use SimpleStore\Content\{BlogPlacement, ContentRepository, ContentInlineEditor, ContentBody};
use SimpleStore\Database\ConnectionFactory;

$db = ConnectionFactory::create(['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),'database'=>'simple_store','port'=>3306]);
$repo = new ContentRepository($db, ['zx','zy']);
$placement = new BlogPlacement($db, ['zx','zy']);
$keys = [];
$check = static function(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); };
$change = static function(string $op,string $key) use($placement):void {
    $placement->change('zx',$op,$key,BlogPlacement::fingerprint($placement->load('zx')));
};
try {
    $db->query(BlogPlacement::SCHEMA);
    $db->query('DELETE FROM shop_blog_placements WHERE language IN (%s,%s)', 'zx','zy');
    for($i=0;$i<9;$i++) {
        $form=ContentInlineEditor::starter('post','zx');
        $form['published']=$i!==8;
        $form['body']=ContentBody::encode([['type'=>'text','heading'=>'','body'=>'Body']],null,$i===2?'spani-venku':'cestopisy');
        $saved=$repo->saveRevision($form); $keys[]=$saved['document_key'];
    }
    $change('pin',$keys[0]); $change('pin',$keys[1]); $change('feature',$keys[2]);
    $state=$placement->load('zx');
    $check($placement->load('zy')===BlogPlacement::EMPTY,'Selection leaked across languages.');
    // Real SQL: feature, pinned, others; pinned rows keep the existing latest-revision order.
    $seen=[]; $offset=0;
    do {
        $page=$repo->publishedPostsPage('zx',$offset,3,null,$state);
        array_push($seen,...array_column($page['items'],'document_key')); $offset=$page['nextOffset'];
    } while($offset!==null);
    $check($seen===[$keys[2],$keys[1],$keys[0],$keys[7],$keys[6],$keys[5],$keys[4],$keys[3]],'Pin order, pagination or draft exclusion failed.');
    $filtered=$repo->publishedPostsPage('zx',0,48,'cestopisy',$state)['items'];
    $check(!in_array($keys[2],array_column($filtered,'document_key'),true) && $filtered[0]['document_key']===$keys[1],'Featured article escaped its category.');
    $change('feature',$keys[3]);
    $check($placement->load('zx')['featured']===$keys[3] && $placement->load('zx')['pinned']===$state['pinned'],'Replacing feature changed pins.');
    try { $placement->change('zx','feature',$keys[4],BlogPlacement::fingerprint($state)); throw new RuntimeException('Stale overwrite accepted.'); } catch(InvalidArgumentException $expected) {}
    try { $change('pin',$keys[8]); throw new RuntimeException('Draft pin accepted.'); } catch(InvalidArgumentException $expected) {}
    $pageForm=ContentInlineEditor::starter('page','zx'); $pageForm['published']=true;
    $saved=$repo->saveRevision($pageForm); $keys[]=$saved['document_key'];
    try { $change('feature',$saved['document_key']); throw new RuntimeException('Page featured as an article.'); } catch(InvalidArgumentException $expected) {}
    $row=$repo->currentDocument($keys[3],'zx');
    $form=ContentInlineEditor::fromRevision($row); $form['title']='Revised featured article';
    $repo->saveRevision(ContentInlineEditor::snapshot($form),$keys[3],(int)$row['revision_number']);
    $check($placement->load('zx')['featured']===$keys[3],'Editing lost featured state.');
    $form['published']=false;
    $repo->saveRevision(ContentInlineEditor::snapshot($form),$keys[3],2);
    $page=$repo->publishedPostsPage('zx',0,48,null,$placement->load('zx'))['items'];
    $check(!in_array($keys[3],array_column($page,'document_key'),true),'Hidden featured article leaked.');
    $repo->deleteDocument($keys[1],'zx','post',1);
    $page=$repo->publishedPostsPage('zx',0,48,null,$placement->load('zx'))['items'];
    $check(!in_array($keys[1],array_column($page,'document_key'),true),'Deleted pinned article leaked.');
    $change('unpin',$keys[0]); $change('unpin',$keys[1]); $change('unfeature',$keys[3]);
    $check($placement->load('zx')===BlogPlacement::EMPTY,'Clearing placement did not restore automatic order.');
    echo "Blog placement: pagination, categories, languages, replacement, stale writes, revisions and hidden/deleted content passed.\n";
} finally {
    $db->query('DELETE FROM shop_blog_placements WHERE language IN (%s,%s)','zx','zy');
    foreach($keys as $key) $db->query('DELETE FROM shop_content_revisions WHERE document_key=%s',$key);
}
