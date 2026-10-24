<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/src/bootstrap.php';
set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
$basePath='/'; $adminUrl='/admin.php'; $csrf='fixture-token'; $site=['default_language'=>'cs','languages'=>['cs','en']];
$screen=$argv[1] ?? 'categories'; $language='cs'; $categoryReady=$menuReady=true; $categoryError=$menuError='';
$categoryRows=[];
foreach ([['spani','Spaní',0],['spani/spacaky','Spacáky',1],['spani/spacaky/zimni','Zimní spacáky do mrazu',2],['spani/stany','Stany',1],['spani/karimatky','Karimatky',1],['batohy','Batohy',0],['batohy/male','Batohy do 25 litrů',1],['batohy/velke','Batohy nad 50 litrů',1],['obleceni','Oblečení',0],['obleceni/bundy','Nepromokavé bundy s dlouhým názvem a dalšími podrobnostmi',1]] as $i=>[$path,$title,$depth]) $categoryRows[]=['path'=>$path,'title'=>$title,'depth'=>$depth,'sort_order'=>($i+1)*10,'enabled'=>1];
$categories = new class($categoryRows) {
    public function __construct(private array $rows) {}
    public function visibility(string $language,string $path): string { return str_contains($path,'karimatky')?'empty':(str_contains($path,'velke')?'hidden':'visible'); }
    public function productCount(string $language,string $path): int { return $this->visibility($language,$path)==='empty'?0:14; }
    public function find(string $language,string $path): ?array { foreach($this->rows as$row)if($row['path']===$path)return $row;return null; }
};
$selectedCategory=$categoryRows[1]; $newParent='spani';
$backupKind='navigation'; $backupReturn=['section'=>$screen,'language'=>'cs']; $backupUrl=$adminUrl.'?'.http_build_query($backupReturn);
$backupPreview=null;$backupError='';$backupJson='';$backupToken='';
if (($argv[2] ?? '') === 'backup') {
    $backupPreview=['counts'=>['new'=>1,'changed'=>1,'same'=>18],'changes'=>[
        ['state'=>'new','type'=>'category','language'=>'cs','key'=>'spani/quilty','label'=>'Quilty · spani/quilty','before'=>null,'after'=>['title'=>'Quilty','sort_order'=>30,'enabled'=>true]],
        ['state'=>'changed','type'=>'menu','language'=>'cs','key'=>'footer','label'=>'Patička · 1 vlastní odkaz','before'=>['source'=>'manual','items'=>[]], 'after'=>['source'=>'manual','items'=>[['label'=>'Kontakt','target'=>'kontakt']]]],
    ]];
    $backupJson='{"example":"<script>alert(1)</script>"}';$backupToken='fixture-preview-token';
}
if($screen==='menus') {
    $slot=($argv[2]??'')==='content'?'utility':'primary';$menuSlots=require dirname(__DIR__,2).'/config/menus.php';$activeSlot=$menuSlots[$slot];
    $menuItems=[['id'=>str_repeat('a',16),'parent_id'=>'','label'=>'O nás','target_type'=>'path','target'=>'o-nas','sort_order'=>10,'depth'=>0],
        ['id'=>str_repeat('b',16),'parent_id'=>str_repeat('a',16),'label'=>'Kontakt','target_type'=>'path','target'=>'kontakt','sort_order'=>20,'depth'=>1],
        ['id'=>str_repeat('c',16),'parent_id'=>'','label'=>'Partnerský obchod','target_type'=>'external','target'=>'https://example.com/outdoor','sort_order'=>30,'depth'=>0]];
    $selectedItem=$menuItems[0];$categoryOptions=$categoryRows;
    $pageRows=[['document_key'=>str_repeat('1',32),'revision_number'=>1,'title'=>'O nás','slug'=>'o-nas','published'=>1,'visible_in_menu'=>1,'menu_order'=>10],
        ['document_key'=>str_repeat('2',32),'revision_number'=>1,'title'=>'Kontakt','slug'=>'kontakt','published'=>0,'visible_in_menu'=>0,'menu_order'=>20]];
    $menuPreviewLinks=[['label'=>'Spaní','href'=>'/cs/kategorie-produktu/spani','children'=>[['label'=>'Spacáky','href'=>'/cs/kategorie-produktu/spani/spacaky','children'=>[]]]],['label'=>'Batohy','href'=>'/cs/kategorie-produktu/batohy','children'=>[]]];
}
require dirname(__DIR__,2).'/view/admin/layout.php';
