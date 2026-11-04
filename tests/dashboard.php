<?php
declare(strict_types=1);
// SQL spy also ensures dashboard loading cannot mutate catalogue, stock or settings.
class MeekroDB {
    public array $data; public array $queries=[]; public bool $transaction=false;
    public function queryFirstField(string $sql,...$args):int { $this->queries[]=$sql; return 5; }
    public function query(string $sql):array {
        $this->queries[]=$sql;
        if (!str_starts_with($sql,'SELECT ')) throw new RuntimeException('Dashboard attempted a write');
        foreach (['shop_product_revisions'=>'products','shop_product_inventory'=>'inventory','shop_product_sales'=>'sales','shop_product_variant_settings'=>'settings','shop_product_variants'=>'variants'] as $table=>$data) {
            if (str_contains($sql,'FROM '.$table)) return $this->data[$data];
        }
        throw new RuntimeException('Unexpected query');
    }
    public function startTransaction():void {$this->transaction=true;}
    public function commit():void {$this->transaction=false;}
    public function rollback():void {$this->transaction=false;}
}
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Admin\StoreDashboard as D;
$assert=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);};
$data=require __DIR__.'/fixtures/dashboard-data.php';
$db=new MeekroDB();$db->data=$data;
$report=(new D($db))->load('cs');$t=$report['groups']['total'];$own=$report['groups']['own'];
$assert(count($db->queries)===6 && !$db->transaction,'Dashboard must use bounded bulk queries in a snapshot');
$assert($report['published']===4 && $report['drafts']===1 && $t['products']===4,'Translations or drafts counted twice');
$assert($t['units']===4 && $t['empty_variants']===1,'Disabled variants or empty variant products were priced');
$assert($t['product_cost']===1800 && $t['product_retail']===3300 && $t['product_missing']===2,'One-per-product scenario incorrect');
$assert($t['unit_cost']===3300 && $t['unit_retail']===5800 && $t['unit_missing']===1,'Variant price override/fallback incorrect');
$assert($own['quantity']===3 && $own['stock_cost']===1800 && $own['stock_retail']===3000,'Supplier counts leaked into owned inventory');
$assert($t['in_stock']===2 && $t['unavailable']===2 && $t['available_units']===2,'Availability grouping incorrect');
$assert($t['need_units']===3 && $t['need_cost']===2700 && $t['need_missing']===1,'Top-up must not buy owned units again');
$all=(new D($db))->load('cs',true);
$assert($all['groups']['total']['products']===5 && $all['groups']['total']['unit_retail']===6700,'Draft scope incorrect');
$empty=D::summarize([],[],[],[],[],'cs');
$assert($empty['groups']['total']['unit_cost']===0,'Empty catalogue must render zero totals');
$broken=$data;$broken['sales'][1]['variant_prices_json']='broken';
$broken=D::summarize(...[...array_values($broken),'cs']);
$assert($broken['invalid_prices']===1 && $broken['groups']['total']['unit_cost']===3000,'Corrupt JSON must be flagged');
$basePath='/shop/';$adminUrl='/shop/admin/';$csrf='test-token';$error='';$screen='dashboard';
$site=['languages'=>['cs','en'],'default_language'=>'cs'];$dashboard=$report;$dashboardScope='published';$dashboardError='';
set_error_handler(static function(int $severity,string $message):never {throw new RuntimeException($message);});
ob_start();require dirname(__DIR__).'/view/admin/layout.php';$html=ob_get_clean();
$assert(str_contains($html,'Přehled obchodu') && str_contains($html,'Neúplný součet') && str_contains($html,'/shop/admin/dashboard/') && str_contains($html,'admin-dashboard.css'),'Dashboard not integrated in private layout');
$assert(str_contains($html,'data-admin-link="dashboard"') && str_contains($html,'name="scope"'),'Navigation or scope selector missing');
$dashboard=$empty;
ob_start();require dirname(__DIR__).'/view/admin/layout.php';ob_end_clean();
$dashboard=null;$dashboardError='<script>invalid</script>';
ob_start();require dirname(__DIR__).'/view/admin/layout.php';$html=ob_get_clean();
$assert(!str_contains($html,'<script>invalid</script>') && str_contains($html,'&lt;script&gt;'),'Error not escaped');
restore_error_handler();
echo "Dashboard: unique products, variants, known costs, stock ownership, top-up and private rendering passed.\n";
