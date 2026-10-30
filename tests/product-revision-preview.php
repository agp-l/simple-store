<?php
declare(strict_types=1);
// SQL spy: fail if preview accesses live stock/sales or attempts any mutation.
class MeekroDB {
    public array $queries=[];
    public ?array $current;
    public ?array $past;
    public function queryFirstRow(string $sql,...$args): ?array {
        $this->queries[]=[$sql,$args];
        if (!str_starts_with($sql,'SELECT * FROM shop_product_revisions')) throw new RuntimeException('Unexpected preview SQL');
        return str_contains($sql,'active_slug=') ? $this->current : $this->past;
    }
}
require dirname(__DIR__).'/src/bootstrap.php';
$fixtureDataOnly=true;$data=require __DIR__.'/fixtures/product-revision.php';
$past=$data['product'];
$current=array_replace($past,['slug'=>'current','name'=>'Aktuální batoh','revision_number'=>2,'price_czk'=>2222]);
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$run=static function(bool $canEdit,bool $editRequested,mixed $number,?array $current,?array $past) {
    $_GET=['preview_revision'=>$number];
    $site=['languages'=>['cs','en']];$route=['slug'=>'current'];
    $url=new \SimpleStore\Navigation\UrlManager('/shop/cs/produkt/current','/shop/index.php',['cs','en'],'cs');
    $shared=['basePath'=>'/shop/','language'=>'cs','adminCreate'=>['csrf'=>'must-not-leak'],'canManageMenu'=>true];
    $db=new MeekroDB();$db->current=$current;$db->past=$past;
    $renderer=new class {
        public array $result=[];
        public function render(string $page,array $data,int $status=200):void{$this->result=[$page,$data,$status];}
    };
    require dirname(__DIR__).'/src/Admin/product-revision-preview.php';
    return [$renderer->result,$db->queries];
};
foreach([[false,true,'1'],[true,false,'1'],[true,true,'0'],[true,true,'-1'],[true,true,[]],[true,true,'bad']] as [$can,$edit,$number]) {
    [$result,$queries]=$run($can,$edit,$number,$current,$past);
    check($result[2]===404 && $queries===[],'Unauthorized or invalid preview must not query revisions');
}
[$result,$queries]=$run(true,true,'1',$current,$past);
check($result[0]==='product-record' && $result[2]===200 && $result[1]['product']===$past,'Must render the exact historical snapshot');
check(count($queries)===2 && $queries[0][1]===['current','cs'] && $queries[1][1]===[$current['product_key'],'cs',1],'Revision lookup must be scoped to current product identity and language');
check($result[1]['adminCreate']===null && $result[1]['canManageMenu']===false && $result[1]['editMode']===false && $result[1]['privatePage']===true,'Preview must disable all editor capabilities');
check($result[1]['revisionReturnUrl']==='/shop/cs/produkt/current?edit=1&history=1#product-history','Return URL must use current slug and installation prefix');
foreach([[null,$past],[$current,null]] as [$now,$old]) {
    [$missing]=$run(true,true,'1',$now,$old);check($missing[2]===404,'Missing product/revision must not fall back to current content');
}
$renderer=new \SimpleStore\Rendering\PageRenderer(dirname(__DIR__).'/view');
set_error_handler(static function(int $severity,string $message):never{throw new RuntimeException($message);});
ob_start();$renderer->render('product-record',$result[1]);$html=ob_get_clean();
foreach(['Náhled revize 1','Historický batoh','Původní perex','500 g','S, M','1 234','old-address','Skrytý koncept','noindex, nofollow','images/batoh.webp','&lt;script&gt;'] as $needle) check(str_contains($html,$needle),'Missing preview content: '.$needle);
foreach(['contenteditable=','inline-editor-config','data-editor-action','id="detail-add"','id="qty"','value="add"','application/ld+json','<script>alert(1)</script>','must-not-leak'] as $needle) check(!str_contains($html,$needle),'Preview leaked action or unsafe content: '.$needle);
$_GET=['history'=>'1'];
ob_start();$renderer->render('product-record',array_replace($data,['revisionPreview'=>false,'product'=>$current,'editMode'=>true,'canEditProduct'=>true,
    'productHistory'=>[['revision_number'=>1,'saved_at'=>$past['saved_at'],'name'=>$past['name'],'active_product_key'=>null]],'editToken'=>'test-token']));$html=ob_get_clean();
check(str_contains($html,'preview_revision=1') && str_contains($html,'target="_blank" rel="noopener"') && str_contains($html,'data-editor-action="restore"') && str_contains($html,'id="product-history" open'),'History must offer preview separately from existing restore');
restore_error_handler();
echo "Product history preview: access checks, scoped read-only lookup, exact snapshot, escaping and separate restore passed.\n";
