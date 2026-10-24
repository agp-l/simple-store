<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Navigation\PanelUrl;
$assert = static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
foreach (['/', '/shop/'] as $base) {
    foreach (['admin','account'] as $area) {
        $root=$base.$area.'/';
        $assert(PanelUrl::pretty($base.$area.'.php')===$root,'Legacy root URL failed.');
        $token=str_repeat('a',64);
        $old=$base.$area.'.php?mode=reset&token='.$token;
        $assert(PanelUrl::pretty($old)===$root.'reset-password/?token='.$token,'Recovery token lost.');
        $route=PanelUrl::request($area,$base,$root.'reset-password/?token='.$token,['token'=>$token]);
        $assert($route['query']===['token'=>$token,'mode'=>'reset'] && $route['url']===$root.'reset-password/?token='.$token,'Reset routing failed.');
        $assert(PanelUrl::request($area,$base,$base.$area,[])['url']===$root,'Trailing slash not normalized.');
        $assert(PanelUrl::pretty($root.'?section=orders&id=42&history=1#detail')===$root.'orders/?id=42&history=1#detail','Order context lost.');
        foreach (['unknown/','orders/extra/','orders//','%2fusers/','../','register/extra/'] as $bad) {
            try { PanelUrl::request($area,$base,$root.$bad,[]); throw new RuntimeException('Invalid path accepted: '.$bad); }
            catch (InvalidArgumentException) {}
        }
    }
    $assert(PanelUrl::pretty($base.'admin/?section=accounting&tab=funds&mode=assisted')===$base.'admin/accounting/?tab=funds&mode=assisted','Business mode filter was consumed as authentication.');
    $route=PanelUrl::request('admin',$base,$base.'admin/users/?section=orders&mode=forgot',['section'=>'orders','mode'=>'forgot']);
    $assert($route['query']===['section'=>'users'],'Query overrode the path.');
    $assert(PanelUrl::pretty($base.'account.php?mode=register&checkout=1')===$base.'account/register/?checkout=1','Checkout return lost.');
    $assert(PanelUrl::escape($base.'admin.php?section=orders&id=2&q=%22')===$base.'admin/orders/?id=2&amp;q=%22','HTML escaping failed.');
}
$assert(PanelUrl::pretty('https://external.example/admin.php?section=orders')==='https://external.example/admin.php?section=orders','External link was rewritten.');
$assert(PanelUrl::pretty('/cs/produkt/boty')==='/cs/produkt/boty','Storefront route changed.');
foreach (['//external.example/', '/shop/../', "/shop/\r\n"] as $base) {
    try { PanelUrl::path($base,'admin'); throw new RuntimeException('Unsafe base path accepted.'); } catch (InvalidArgumentException) {}
}
echo "Private panel URL routing passed.\n";
