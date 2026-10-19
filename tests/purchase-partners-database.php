<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\{ConnectionFactory, SchemaUpdater};
use SimpleStore\Product\{PurchasePartnerRepository as Partners, ProductSalesRepository, ProductRepository};
use SimpleStore\Rendering\PageRenderer;

$db = ConnectionFactory::create(['host'=>'127.0.0.1','user'=>'root','password'=>(string)getenv('MYSQL_TEST_PASSWORD'),
    'database'=>'simple_store','port'=>3306]);
$schema = new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql');
$schema->apply();
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $run): void {
    try { $run(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid or stale operation was accepted.');
};
$partners = new Partners($db); $sales = new ProductSalesRepository($db);
$partnerIds = []; $keys = [];
$full = ['name'=>'Test partner <safe>','ico'=>'12345678','address'=>'Ulice 1, Praha',
    'email'=>'seller@example.test','terms_url'=>'https://example.test/terms','confirmed'=>'1'];
try {
    // Legacy rows were required to be confirmed; the repeatable migration preserves that status.
    $db->insert('shop_purchase_partners', array_diff_key($full, ['confirmed'=>true]));
    $legacyId = (int) $db->insertId(); $partnerIds[] = $legacyId;
    $check(Partners::missing($partners->find($legacyId)) === [], 'Legacy confirmed profile was disabled.');
    $id = $partners->save(0, ['name'=>'Rozpracovaný prodejce','email'=>'rozepsany@']); $partnerIds[] = $id;
    $draft = $partners->find($id);
    $check($draft['email']==='rozepsany@' && Partners::missing($draft)!==[] && (int)$draft['confirmed']===0,
        'Draft contact was lost or considered checkout-ready.');
    $schema->apply();
    $check((int)$partners->find($id)['confirmed']===0, 'Schema replay activated a draft.');
    $reject(static fn()=> $partners->save(0, []));
    foreach ([1800,2000] as $price) {
        $key=bin2hex(random_bytes(16));$keys[]=$key;
        $db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$key,'language'=>'cs','revision_number'=>1,
            'slug'=>'partner-'.$key,'active_slug'=>'partner-'.$key,'name'=>'Partner test','category'=>'batohy','price_czk'=>$price,
            'description'=>'','image_path'=>'','stock_status'=>'on_order','published'=>1]);
        $sales->save($key,'cs',['mode'=>'assisted','version'=>'0','partner_id'=>(string)$id]);
        $check($sales->forProduct($key)['partner_id']==$id && $sales->forProduct($key)['supplier_price_czk']===null,
            'Unfinished product settings could not be saved.');
        $reject(static fn()=> $sales->snapshot($key,'',$price));
    }
    $row = array_values(array_filter($partners->all(),static fn($p)=> (int)$p['id']===$id))[0];
    $check((int)$row['product_count']===2,'Shared product usage count wrong.');
    $partners->save($id, $full + ['version'=>'1']);
    $reject(static fn()=> $partners->save($id, array_replace($full,['name'=>'stale','version'=>'1'])));
    $check($partners->find($id)['name']===$full['name'],'Stale editor overwrote current identity.');
    $input=['mode'=>'assisted','version'=>'1','partner_id'=>(string)$id,'supplier_price_czk'=>'1678','delivery_note'=>'Do 5 dnů'];
    foreach ($keys as $key) $sales->save($key,'cs',$input);
    $before=$sales->snapshot($keys[0],'',1800);
    $check($before['assistance_fee_czk']===122 && !isset($before['seller']['confirmed'], $before['seller']['version']),
        'Final price or private seller metadata changed.');
    $partners->save($id, array_replace($full,['email'=>'new@example.test','version'=>'2']));
    $check($sales->snapshot($keys[1],'',2000)['seller']['email']==='new@example.test', 'Shared identity edit did not reach products.');
    $check($before['seller']['email']==='seller@example.test','Existing snapshot changed.');
    $partners->save($id, array_replace($full,['email'=>'','version'=>'3']));
    $reject(static fn()=> $sales->snapshot($keys[0],'',1800));
    $partners->save($id, array_replace($full,['confirmed'=>'0','version'=>'4']));
    $reject(static fn()=> $sales->snapshot($keys[0],'',1800));
    $partners->save($id, $full + ['version'=>'5']);
    $sales->save($keys[0],'cs',array_replace($input,['mode'=>'own','version'=>'2']));
    $saved=$sales->forProduct($keys[0]);
    $check($saved['supplier_price_czk']===1678 && (int)$saved['partner_id']===$id && $saved['delivery_note']==='Do 5 dnů',
        'Changing to own sale erased prepared partner data.');
    $check($sales->snapshot($keys[0],'',1800)===[], 'Own sale unexpectedly requires an assisted checkout.');
    $sales->save($keys[0],'cs',array_replace($input,['version'=>'3']));
    $check($sales->snapshot($keys[0],'',1800)['assistance_fee_czk']===122,'Switching back to assistance lost configuration.');
    $reject(static fn()=> $sales->save($keys[0],'cs',array_replace($input,['version'=>'4','partner_name'=>'New seller'])));
    $reject(static fn()=> $sales->save($keys[0],'cs',['mode'=>'assisted','version'=>'4','partner_id'=>'0','create_partner'=>'1']));
    $sales->save($keys[0],'cs',['mode'=>'assisted','version'=>'4','partner_id'=>'0','partner_name'=>'Nový neúplný']);
    $inlineId=(int)$sales->forProduct($keys[0])['partner_id'];$partnerIds[]=$inlineId;
    $check($partners->find($inlineId)['name']==='Nový neúplný' && $sales->forProduct($keys[0])['supplier_price_czk']===null,
        'Inline partial seller did not persist with unfinished sale settings.');
    $reject(static fn()=> $sales->snapshot($keys[0],'',1800));

    // Exercise actual admin controller validation and render: submitted fields and stale version survive.
    $method='POST'; $action='purchase-partner-save'; $adminUrl='/admin.php';
    $_POST=['action'=>$action,'id'=>(string)$id,'version'=>'1','name'=>'Rozepsaná změna <test>','email'=>'invalid@'];
    $_GET=[];
    require dirname(__DIR__).'/src/Admin/purchase-partners.php';
    $check($partnerError!=='' && $partnerForm['name']===$_POST['name'] && $partnerForm['version']==='1',
        'Failed admin save discarded submitted values or silently advanced the optimistic lock.');
    $escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$csrf='test-token';
    ob_start();require dirname(__DIR__).'/view/admin/purchase-partners.php';$html=ob_get_clean();
    $check(str_contains($html,'value="Rozepsaná změna &lt;test&gt;"') && str_contains($html,'value="invalid@"') &&
        str_contains($html,'name="action" value="purchase-partner-save"') && str_contains($html,'novalidate'),
        'Directory form lost draft data, escaping or authenticated save route.');
    $form=['mode'=>'assisted','version'=>'5','partner_id'=>'0','supplier_price_czk'=>'1801',
        'partner_name'=>'Nový <prodejce>','partner_email'=>'rozepsany@','partner_ico'=>'123',
        'partner_address'=>'Rozepsaná adresa','partner_terms_url'=>'https://','partner_confirmed'=>'1','delivery_note'=>'Do týdne'];
    $product=(new ProductRepository($db,['cs']))->current($keys[0],'cs');
    ob_start();(new PageRenderer(dirname(__DIR__).'/view'))->render('product-record',[
        'basePath'=>'/','language'=>'cs','editMode'=>true,'canEditProduct'=>true,'editToken'=>'test-token',
        'productSalesReady'=>true,'productSale'=>$sales->forProduct($keys[0]),'purchasePartners'=>$sales->partners(),
        'productSaleForm'=>$form,'productSaleError'=>'Cena je vyšší než konečná cena.','product'=>$product]);$html=ob_get_clean();
    foreach (['Nový &lt;prodejce&gt;','rozepsany@','Rozepsaná adresa','value="123"','value="1801"','Cena je vyšší','Seznam a úpravy prodávajících'] as $text) {
        $check(str_contains($html,$text),'Product form recovery missed: '.$text);
    }
} finally {
    foreach ($keys as $key) foreach (['shop_product_sales','shop_product_inventory','shop_product_revisions'] as $table) {
        $db->query('DELETE FROM '.$table.' WHERE product_key=%s',$key);
    }
    foreach ($partnerIds as $id) $db->query('DELETE FROM shop_purchase_partners WHERE id=%i',$id);
}
echo "Purchase partners: drafts, shared edits, optimistic locks, checkout readiness, mode switching and form recovery OK.\n";
