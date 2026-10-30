<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Admin\SettingsArchive as A;
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function reject(callable $f): void { try { $f(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Invalid archive accepted'); }
$old=['secret'=>'keep','empty'=>'','enabled'=>false,'count'=>0,'nested'=>['a'=>'old','b'=>'keep'],'list'=>['one','two']];
$input=['secret'=>'replace','empty'=>'new','enabled'=>true,'count'=>5,'nested'=>['a'=>'new'],'list'=>['new']];
$fill=A::merge($old,$input,false);
check($fill===array_replace($old,['empty'=>'new']),'Fill must preserve nonempty values, false and zero');
$replace=A::merge($old,$input,true);
check($replace['nested']===['a'=>'new','b'=>'keep'] && $replace['list']===['new','two'],'Omitted nested keys and list entries must survive');
foreach (['',null,[]] as $empty) check(A::merge($old,['secret'=>$empty,'nested'=>$empty],true)===$old,'Empty value erased data');
check(A::merge(['enabled'=>true],['enabled'=>false],true)['enabled']===false,'Explicit disable must be supported');
reject(fn()=>A::merge($old,['enabled'=>'false'],true));
reject(fn()=>A::merge($old,['nested'=>'bad'],true));
reject(fn()=>A::merge([],['smtp_password_encrypted'=>'cipher'],true));
reject(fn()=>A::decode('{broken'));
reject(fn()=>A::decode(A::json(['format'=>A::FORMAT,'version'=>2,'data'=>[]])));
$json=A::json(['format'=>A::FORMAT,'version'=>1,'data'=>['test'=>$old],'reference_only'=>['ignored'=>true]]);
check(A::decode($json)===['test'=>$old],'JSON round trip');
check(A::digest(['a'=>1,'b'=>2])===A::digest(['b'=>2,'a'=>1]),'Digest depends on object order');
$archiveError='';$archiveNotice='';$archivePlan=null;$archivePending=null;
$csrf='csrf';$adminUrl='/admin/';$escape=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
ob_start();require dirname(__DIR__).'/view/admin/settings-export.php';$html=ob_get_clean();
check(str_contains($html,'settings-import-preview') && str_contains($html,'name="csrf"'),'Preview and CSRF control missing');
check(!str_contains($html,'value="settings-import-apply"'),'Apply must require staged preview');
$payload='\"><script>alert(1)</script>';
$archivePlan=['changes'=>[['section'=>'google','key'=>'1','before'=>['key'=>$payload],'after'=>['key'=>'new']]]];
$archivePending=['overwrite'=>true,'token'=>'preview-token'];
ob_start();require dirname(__DIR__).'/view/admin/settings-export.php';$html=ob_get_clean();
check(!str_contains($html,'<script>alert(1)</script>'),'Preview must escape secret values');
check(str_contains($html,'settings-import-backup') && str_contains($html,'name="confirm"'),'Backup and confirmation missing');
echo "Settings archive: non-destructive merge, strict types, format and preview controls passed.\n";
