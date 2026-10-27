<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never{throw new RuntimeException($message);});
use SimpleStore\Content\{ContentBody,ContentImport};
$document=json_decode(file_get_contents(dirname(__DIR__,2).'/content/posts/dva-roky-ve-stanu.cs.json'),true);
$fields=ContentImport::fields($document,['cs'],'new');
$fields['published']=true;
// Stable local assets keep layout tests independent of the live shop.
$sections=ContentBody::decode($fields['body']);
foreach($sections as &$section) if($section['type']==='image') $section['body']='images/stan.webp';
unset($section);
$fields['body']=ContentBody::encode($sections,null,'spani-venku');
$row=$fields+['document_key'=>$document['document_key'],'revision_number'=>28,'saved_at'=>'2026-10-27 12:00:00'];
$mode=$argv[1]??'index';
$renderer=new SimpleStore\Rendering\PageRenderer(dirname(__DIR__,2).'/view');
$data=['basePath'=>'/','language'=>'cs','privatePage'=>true];
if (in_array($mode,['index','manage'],true)) {
    $posts=[];foreach (['Dva roky ve stanu','Pěšky přes hory','Ráno u jezera','Batoh na celý svět','Toulání bez mapy','Déšť patří k cestě'] as $i=>$title) {
        $post=$row; $post['document_key']=sprintf('%032x',$i+1); $post['title']=$title; $post['slug']='pribeh-'.$i;
        if($i===3)$post['body']=ContentBody::encode([['type'=>'text','heading'=>'','body'=>'Článek bez fotografie.']], '');
        $posts[]=$post;
    }
    $renderer->render('blog',$data+['posts'=>$posts,'blogTopicCounts'=>['spani-venku'=>4,'cestopisy'=>2], 'canManageContent'=>$mode==='manage', 'adminCsrf'=>'test-token', 'blogPlacement'=>['featured'=>sprintf('%032x',1),'pinned'=>[sprintf('%032x',2)]]]);
} else {
    $renderer->render('post',$data+['content'=>$row,'backLink'=>'/cs/blog', 'canEditContent'=>$mode==='edit','contentEditMode'=>$mode==='edit','editToken'=>'test-token']);
}
