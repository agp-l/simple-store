<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Content\{BlogTopics,ContentBody,ContentImport,ContentInlineEditor};
$check=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);};
$row=ContentInlineEditor::starter('post','cs')+['document_key'=>str_repeat('a',32),'revision_number'=>1];
$form=ContentInlineEditor::fromRevision($row);
$row=array_replace($row,ContentInlineEditor::snapshot(ContentInlineEditor::change($form,'set','topic','spani-venku')));
$check(ContentBody::topic($row['body'])==='spani-venku','Editor did not save category.');
$data=ContentImport::decode(ContentImport::export($row));
$copy=ContentImport::fields($data,['cs'],'update',$row);
$check($copy['body']===$row['body'],'JSON roundtrip changed category.');
$data['content']=['body'=>'Nový text'];
$copy=ContentImport::fields($data,['cs'],'update',$row);
$check(ContentBody::topic($copy['body'])==='spani-venku','Partial text import discarded category.');
$form=ContentInlineEditor::fromRevision($row);
$copy=ContentInlineEditor::snapshot(ContentInlineEditor::change($form,'set','cover_path',''));
$check(ContentBody::topic($copy['body'])==='spani-venku','Cover change discarded category.');
$check(ContentBody::topic('old plain text')==='zapisnik' && ContentBody::topic('{"format":"simple-store-blocks-v1","topic":[]}')==='zapisnik','Legacy/invalid metadata must have a safe fallback.');
foreach (['bad', [], ''] as $bad) {
    try {BlogTopics::validate($bad);throw new RuntimeException('Invalid category accepted.');} catch(InvalidArgumentException){}
}
$check(BlogTopics::minutes(ContentBody::encode([['type'=>'text','heading'=>'','body'=>str_repeat('slovo ',441)]]))===3,'Reading estimate must include actual text.');
echo "Blog topics: editor, JSON, partial edits, legacy fallback and reading estimate passed.\n";
