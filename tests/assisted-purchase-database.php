<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Accounting\{OrderLegalDocuments, MailSettingsRepository, InvoiceRepository, TaxEvidenceRepository, EvidenceBookRepository};
use SimpleStore\Checkout\{AssistedPurchase as A, BankTransferPayment, CartService, CartSession, OrderRepository};
use SimpleStore\Content\ContentRepository;
use SimpleStore\Database\{ConnectionFactory, SchemaUpdater};
use SimpleStore\Product\{ProductRepository, ProductSalesRepository, ProductStockRepository};
use SimpleStore\AfterSales\{CaseRepository, CaseNotice};

$db = ConnectionFactory::create(['host'=>'127.0.0.1', 'user'=>'root', 'password'=>(string)getenv('MYSQL_TEST_PASSWORD'), 'database'=>'simple_store', 'port'=>3306]);
(new SchemaUpdater($db, dirname(__DIR__).'/database/schema.sql'))->apply();
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $run): void {
    try { $run(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid purchase was accepted.');
};
$sales = new ProductSalesRepository($db);
$stock = new ProductStockRepository($db, $sales);
$content = new ContentRepository($db);
$createdPages = []; $productKeys = []; $orderIds = []; $partnerId = 0;
$mail = new MailSettingsRepository($db);
$templates = [];
foreach (MailSettingsRepository::EVENTS as $event=>$value) $templates[$event]=['enabled'=>'1', 'subject'=>$value['subject'], 'message'=>$value['message']];
$mailInput = ['from_email'=>'shop@example.test', 'from_name'=>'Test', 'reply_to'=>'', 'public_base_url'=>'https://shop.example.test', 'automatic_enabled'=>'1', 'templates'=>$templates];
$mail->save($mailInput);
try {
    foreach ([A::TERMS_SLUG, OrderLegalDocuments::TERMS_SLUG] as $slug) {
        if ($content->findPublished('page',$slug,'cs')===null) {
            $saved=$content->saveRevision(['type'=>'page','language'=>'cs','title'=>$slug,'slug'=>$slug,'body'=>'Test podmínek '.$slug,'published'=>true]);
            $createdPages[]=$saved['document_key'];
        }
    }
    foreach ([1800,200] as $price) {
        $key=bin2hex(random_bytes(16));$productKeys[]=$key;
        $db->insert('shop_product_revisions',['product_key'=>$key,'active_product_key'=>$key,'language'=>'cs','revision_number'=>1,
            'slug'=>'assist-'.$key,'active_slug'=>'assist-'.$key,'name'=>'Test '.$price,'category'=>'batohy','price_czk'=>$price,
            'description'=>'','image_path'=>'','stock_status'=>'in_stock','published'=>1]);
        $stock->ensure($key);$stock->setAvailable($key,0,10);
    }
    [$key,$ownKey]=$productKeys;
    $input=['mode'=>'assisted','version'=>'0','supplier_price_czk'=>'1678',
        'partner_id'=>'0','partner_name'=>'Partner <test>','partner_ico'=>'12345678','partner_address'=>'Ulice 1, Praha',
        'partner_email'=>'partner@example.test','partner_terms_url'=>'https://partner.example.test/terms','partner_confirmed'=>'1'];
    $sales->save($key,'cs',$input);
    $partnerId=(int)$sales->forProduct($key)['partner_id'];
    $check($sales->forProduct($key)['delivery_note']==='' && $sales->snapshot($key,'',1800)['delivery_note']===A::DELIVERY_POLICY,
        'New products must be orderable without a manual delivery estimate.');
    $input = array_diff_key($input, array_flip(['partner_name','partner_ico','partner_address','partner_email','partner_terms_url','partner_confirmed']));
    $input['partner_id'] = (string) $partnerId;
    $reject(static fn()=> $sales->save($key,'cs',$input)); // stale admin save
    $reject(static fn()=> $sales->save($key,'cs',array_replace($input,['version'=>'1','partner_id'=>(string)$partnerId,'supplier_price_czk'=>'1801'])));
    $public=$stock->decorate([['product_key'=>$key,'stock_status'=>'in_stock','price_czk'=>1800]])[0];
    $check($public['sale_mode']==='assisted'&&!isset($public['supplier_price_czk'])&&!isset($public['seller']), 'Private purchase fields leaked into public product.');
    $cart=new CartSession('/assisted-test/');$cart->clear();
    $cartService=new CartService(new ProductRepository($db,['cs'],null,50,$stock),['cs'],$sales);
    $cartService->add($cart,$key,'cs',[],2);$cartService->add($cart,$ownKey,'cs',[],1);
    $summary=$cartService->summary($cart);
    $check($summary['can_continue']&&$summary['subtotal_czk']===3800&&A::principal($summary['items'])===3356,'Live mixed cart totals wrong.');
    $legal=new OrderLegalDocuments($db);$bundle=$legal->checkoutBundle($summary['items']);
    $shipping=['method'=>'gls_home','label'=>'GLS domů','name'=>'Eva Test','assisted_purchase'=>[
        'early_start'=>true,'early_request_text'=>A::EARLY_REQUEST,'terms_hash'=>$bundle['hash']]];
    $orders=new OrderRepository($db,new BankTransferPayment('','1004823033/3030','Test'),7,$stock);
    $requestKey=bin2hex(random_bytes(32));
    $order=$orders->create(null,'buyer@example.test',$summary['items'],$shipping,79,$requestKey);$orderIds[]=(int)$order['id'];
    $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$key)===10,'Assisted order permanently drained the partner limit.');
    $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$ownKey)===9,'Mixed order did not reserve own stock.');
    $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_partner_refresh_requests WHERE product_key=%s',$key)===1,'Assisted order did not queue partner refresh.');
    // A reservation created by the old assisted-stock logic must not inflate a fresh partner limit.
    $db->startTransaction();
    $db->insert('shop_order_stock_reservations',['order_id'=>$order['id'],'product_key'=>$key,'quantity'=>2,'state'=>'reserved']);
    $stock->release((int)$order['id']);$stock->release((int)$order['id']);
    $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$key)===10,'Legacy assisted cancellation inflated partner limit.');
    $stock->reopen((int)$order['id']);
    $check((int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$key)===10,'Legacy assisted reopening drained partner limit.');
    $db->rollback();
    $check(($order['items'][0]['procurement'] ?? null) === ($summary['items'][0]['procurement'] ?? null), 'Reviewed procurement snapshot was not persisted.');
    $check($order['items'][0]['delivery_note']===A::DELIVERY_POLICY, 'Common delivery mandate was not frozen in the order.');
    $check((int)$order['total_czk']===3879&&$order['items'][0]['assistance_fee_czk']===122,'Order snapshot differs from approval.');
    $check($orders->create(null,'buyer@example.test',$summary['items'],$shipping,79,$requestKey)['id']===$order['id'],'Duplicate contract created.');
    $outbox=$db->queryFirstRow('SELECT * FROM shop_mail_outbox WHERE event_key=%s','order:'.$order['id']);
    $check($outbox!==null&&str_contains($outbox['terms_attachment'],A::TERMS_SLUG)&&str_contains($outbox['terms_attachment'],OrderLegalDocuments::TERMS_SLUG),
        'Durable confirmation lost its terms attachment.');
    foreach (['Zboží 1 678 Kč + zajištění nákupu 122 Kč', 'Partner <test>', 'partner@example.test', A::EARLY_REQUEST, A::DELIVERY_POLICY] as $contractText) {
        $check(str_contains($outbox['body_text'],$contractText), 'Durable confirmation lost a price, seller, consent or delivery agreement.');
        $check(str_contains($outbox['body_html'],htmlspecialchars($contractText,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),
            'HTML confirmation differs from the saved purchase agreement.');
    }
    $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_sale_lines WHERE order_id=%i',$order['id'])===1,'Partner goods entered our goods ledger.');
    $partners = new \SimpleStore\Product\PurchasePartnerRepository($db);
    $profile = $partners->find($partnerId);
    $partners->save($partnerId, array_replace($profile,['confirmed'=>'1','email'=>'changed@example.test']));
    $check($orders->findById((int)$order['id'])['items'][0]['seller']['email']==='partner@example.test' &&
        $sales->snapshot($key,'',1800)['seller']['email']==='changed@example.test', 'Editing the seller changed an accepted contract.');
    $partners->save($partnerId, array_replace($profile,['confirmed'=>'1','version'=>'2']));
    // A failed confirmation must roll back the entire order and its stock reservation.
    $before=(int)$db->queryFirstField('SELECT COUNT(*) FROM shop_orders');
    $refreshBefore=$db->queryFirstRow('SELECT * FROM shop_partner_refresh_requests WHERE product_key=%s',$key);
    $stockBefore=(int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$key);
    $mail->save(array_replace($mailInput,['automatic_enabled'=>'0']));
    $reject(static fn()=> $orders->create(null,'buyer@example.test',$summary['items'],$shipping,79,bin2hex(random_bytes(32))));
    $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_orders')===$before&&
        (int)$db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s',$key)===$stockBefore,'Nested mail transaction committed a failed order.');
    $check($refreshBefore===$db->queryFirstRow('SELECT * FROM shop_partner_refresh_requests WHERE product_key=%s',$key),'Rolled-back order changed the refresh outbox.');
    $mail->save($mailInput);
    $bad=$shipping;$bad['assisted_purchase']['terms_hash']=str_repeat('0',64);
    $reject(static fn()=> $orders->create(null,'buyer@example.test',$summary['items'],$bad,79,bin2hex(random_bytes(32))));
    $sales->save($key,'cs',array_replace($input,['version'=>'1','partner_id'=>(string)$partnerId,'supplier_price_czk'=>'1700']));
    $check($orders->findById((int)$order['id'])['items'][0]['supplier_price_czk']===1678,'Catalog edit changed existing contract.');
    $delivery=['method'=>'gls_home','name'=>'Eva Test','email'=>'buyer@example.test','phone'=>'123456789',
        'street'=>'Test 1','city'=>'Praha','postal_code'=>'11000','country'=>'CZ'];
    $cart->setDelivery($delivery);$cart->setPaymentMethod('bank_transfer');
    $bank=new BankTransferPayment('','1004823033/3030','Test');
    $controller=new \SimpleStore\Checkout\CheckoutController(
        new \SimpleStore\Navigation\UrlManager('/cs/pokladna','/index.php',['cs'],'cs'),
        new \SimpleStore\Rendering\PageRenderer(dirname(__DIR__).'/view'), ['basePath'=>'/','language'=>'cs'],
        $cart,$cartService,new \SimpleStore\Checkout\ShippingPolicy(['gls_home'=>['price_czk'=>79,'label'=>'GLS domů','requires_address'=>true]]),
        $orders,$bank,null,'/cs/obchodni-podminky',[],[],null,null,null,null,
        new \SimpleStore\Accounting\OrderMailQueue($db),'shop@example.test',null,null,null,null,null,$legal);
    $place=new ReflectionMethod($controller,'placeOrder');$place->setAccessible(true);
    $_SERVER['REQUEST_METHOD']='GET';$_GET=['step'=>'review'];
    ob_start();$controller->handle(['name'=>'checkout']);$review=ob_get_clean();
    $check(str_contains($review,'name="assisted_terms"')&&str_contains($review,'name="review_token"')&&
        str_contains($review,'Partner &lt;test&gt;')&&str_contains($review,'1 700 Kč'), 'Final review lost seller, breakdown or explicit consent.');
    preg_match('/name="review_token" value="([a-f0-9]{64})"/', $review, $approval);
    $_POST=['terms'=>'1','assisted_terms'=>'1','early_start'=>'1','review_token'=>$approval[1]];
    $orderCount=(int)$db->queryFirstField('SELECT COUNT(*) FROM shop_orders');
    $sales->save($key,'cs',array_replace($input,['version'=>'2','partner_id'=>(string)$partnerId,'supplier_price_czk'=>'1701']));
    $reject(static fn()=> $place->invoke($controller));
    $sales->save($key,'cs',array_replace($input,['version'=>'3','mode'=>'own','partner_id'=>(string)$partnerId,'supplier_price_czk'=>'1700']));
    $reject(static fn()=> $place->invoke($controller)); // assistance -> own must be reviewed again
    ob_start();$controller->handle(['name'=>'checkout']);$ownReview=ob_get_clean();
    preg_match('/name="review_token" value="([a-f0-9]{64})"/', $ownReview, $ownApproval);
    $check(isset($ownApproval[1])&&!str_contains($ownReview,'name="assisted_terms"'),'Own checkout lacks its review protection.');
    $_POST['review_token']=$ownApproval[1];
    $sales->save($key,'cs',array_replace($input,['version'=>'4','partner_id'=>(string)$partnerId,'supplier_price_czk'=>'1700']));
    $reject(static fn()=> $place->invoke($controller)); // own -> assistance cannot reuse an own-sale approval
    $check((int)$db->queryFirstField('SELECT COUNT(*) FROM shop_orders')===$orderCount,'A stale review created an order.');
    ob_start();
    (new \SimpleStore\Rendering\PageRenderer(dirname(__DIR__).'/view'))->render('product-record',[
        'basePath'=>'/','language'=>'cs','editMode'=>true,'canEditProduct'=>true,'productSalesReady'=>true,
        'productSale'=>$sales->forProduct($key),'purchasePartners'=>$sales->partners(),
        'product'=>(new ProductRepository($db,['cs'],null,50,$stock))->current($key,'cs')]);
    $adminHtml=ob_get_clean();
    $check(str_contains($adminHtml,'name="supplier_price_czk"')&&str_contains($adminHtml,'name="mode"')&&
        str_contains($adminHtml,'Partner &lt;test&gt;'), 'Admin cannot configure the sale and supplier price.');
    $db->query('UPDATE shop_orders SET payment_status=%s,payment_paid_at=UTC_TIMESTAMP() WHERE id=%i','paid',$order['id']);
    $seller=['name'=>'Test OSVČ','ico'=>'12345678','street'=>'Test 1','city'=>'Praha','postal_code'=>'11000','bank_account'=>'1004823033/3030'];
    $invoice=(new InvoiceRepository($db))->issue((int)$order['id'],$seller,['name'=>'Eva Test']);
    $check((int)$invoice['total_czk']===523&&$invoice['items'][0]['unit_price_czk']===122&&A::principal($invoice['items'])===3356,'Our invoice includes partner funds.');
    $tax=new TaxEvidenceRepository($db);$tax->addOrderReceipt((int)$order['id'],['entry_date'=>gmdate('Y-m-d'),'reference'=>'test']);
    $entries=$db->query('SELECT tax_kind,amount_czk FROM shop_tax_entries WHERE order_id=%i',$order['id']);
    $amounts=array_column($entries,'amount_czk','tax_kind');
    $check((int)$amounts['taxable']===523&&(int)$amounts['nontaxable']===3356&&(int)$tax->orderReceipt((int)$order['id'])['amount_czk']===3879,'Payment was not split and reconciled.');
    $case=(new CaseRepository($db))->submit($order['order_token'],['kind'=>'withdrawal','item_line'=>'1','quantity'=>'1',
        'description'=>'','confirmed'=>'1','request_key'=>bin2hex(random_bytes(32))]);
    $check((int)$case['unit_price_czk']===122&&str_contains(CaseNotice::receipt($case)['text'],'službě asistovaného'),'Withdrawal treated our service as our sale of goods.');
    $book=(new EvidenceBookRepository($db))->page((int)gmdate('Y'),0,$order['order_number']);
    $check(count($book['items'])===1&&$book['items'][0]['receipt_amount_czk']===3879,'Split receipt was lost in evidence overview.');
} finally {
    foreach ($orderIds as $id) {
        $stock->release($id);
        $db->query('DELETE FROM shop_after_sales_events WHERE case_id IN (SELECT id FROM shop_after_sales_cases WHERE order_id=%i)',$id);
        $db->query('DELETE FROM shop_invoice_number_events WHERE invoice_id IN (SELECT id FROM shop_invoices WHERE order_id=%i)',$id);
        foreach (['shop_after_sales_cases','shop_invoices','shop_tax_entries','shop_mail_outbox','shop_order_legal_snapshots','shop_sale_lines','shop_order_stock_reservations'] as $table) $db->query('DELETE FROM '.$table.' WHERE order_id=%i',$id);
        $db->query('DELETE FROM shop_orders WHERE id=%i',$id);
    }
    foreach ($productKeys as $key) foreach (['shop_product_sales','shop_product_inventory','shop_product_revisions'] as $table) $db->query('DELETE FROM '.$table.' WHERE product_key=%s',$key);
    if ($partnerId) $db->query('DELETE FROM shop_purchase_partners WHERE id=%i',$partnerId);
    foreach ($createdPages as $key) $db->query('DELETE FROM shop_content_revisions WHERE document_key=%s',$key);
    $mail->save($mailInput);
}
echo "Assisted purchase database: mixed checkout, immutable seller, atomic consent mail, rollback, invoicing, tax split and withdrawal OK.\n";
