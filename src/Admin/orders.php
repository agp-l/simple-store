<?php
declare(strict_types=1);

use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Checkout\CarrierShipmentCsv;
use SimpleStore\Checkout\CarrierShipmentDraft;
use SimpleStore\Checkout\CarrierShipmentRepository;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\ComgatePaymentService;
use SimpleStore\Checkout\GoPayPaymentService;
use SimpleStore\Checkout\BTCPayPaymentService;
use SimpleStore\Checkout\BTCPayPaidOrderGuard;
use SimpleStore\Checkout\PacketaApiClient;
use SimpleStore\Checkout\PacketaPickupPoint;
use SimpleStore\Checkout\PacketaRejectedException;
use SimpleStore\Checkout\PacketaShipmentDraft;
use SimpleStore\Checkout\PacketaShipmentRepository;
use SimpleStore\Checkout\OrderTrackingRepository;
use SimpleStore\Admin\OrderControlRepository;
use SimpleStore\Admin\OrderShippingRepository;
use SimpleStore\Admin\OrderProductLinks;
use SimpleStore\Product\ProductStockRepository;
use SimpleStore\Accounting\InvoiceRepository;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Accounting\OrderMailQueue;

// admin.php has already authenticated the administrator and verified POST CSRF.
$screen = 'orders';
$stock = new ProductStockRepository($db);
$orders = new OrderRepository($db, null, 7, $stock);
$orderMail = new OrderMailQueue($db);
$orderMailSender = (string) ((new TaxEvidenceRepository($db))->settings()['mail_from'] ?? '');
$trackingStore = new OrderTrackingRepository($db);
$orderTrackingReady = $trackingStore->installed();
$orderManualTracking = ['number' => '', 'url' => ''];
$ordersReady = $orders->installed();
$fulfillmentSourceReady = $ordersReady && $orders->fulfillmentSourceInstalled();
$packetaShipments = new PacketaShipmentRepository($db);
$carrierShipments = new CarrierShipmentRepository($db);
$carrierReady = $carrierShipments->installed();
$packetaReady = $packetaShipments->installed();
$packetaCancelReady = $packetaReady && $packetaShipments->cancellationHistoryInstalled();
$checkoutExample = require __DIR__ . '/../../config/checkout.example.php';
$checkoutLocal = __DIR__ . '/../../config/checkout.php';
$checkoutSettings = (new CheckoutSettingsRepository($db))->load(
    CheckoutSettingsRepository::withDefaults(is_file($checkoutLocal) ?
        require $checkoutLocal : $checkoutExample, $checkoutExample));
$orderShipping = new OrderShippingRepository($db,
    new \SimpleStore\Checkout\ShippingPolicy($checkoutSettings['shipping_methods']));
$shippingChangeReady = $orderShipping->installed();
$shippingChangeOptions = [];
$shippingChangeNeedsAddress = false;
$packetaCredentials = $checkoutSettings['packeta'] ?? [];
$packetaConfigured = ($packetaCredentials['api_password'] ?? '') !== '' &&
    ($packetaCredentials['sender'] ?? '') !== '';
$comgateSettings = $checkoutSettings['comgate'] ?? [];
$comgateConfigured = ($comgateSettings['merchant'] ?? '') !== '' &&
    ($comgateSettings['secret'] ?? '') !== '';
$goPaySettings = $checkoutSettings['gopay'] ?? [];
$goPayConfigured = (string) ($goPaySettings['goid'] ?? '') !== '' &&
    ($goPaySettings['client_id'] ?? '') !== '' &&
    ($goPaySettings['client_secret'] ?? '') !== '';
$btcpaySettings = $checkoutSettings['btcpay'] ?? [];
$btcpayConfigured = (string) ($btcpaySettings['server_url'] ?? '') !== '' &&
    (string) ($btcpaySettings['store_id'] ?? '') !== '' &&
    (string) ($btcpaySettings['api_key'] ?? '') !== '';
$orderError = '';
$order = null;
$orderProductLinks = [];
$packetaShipment = null;
$carrierShipment = null;
$carrierAction = '';
$cancelledPackets = [];
$packetaTrackingUrl = null;
$orderControls = new OrderControlRepository($db, $stock->installed() ? $stock : null);
$orderControlsReady = $orderControls->installed();
$orderEvents = [];
$orderInvoice = null;
$orderReceipt = null;
$goPayState = null;
$btcpayState = null;
$orderTaxReady = false;
$orderInvoiceReady = false;
$sellerSettings = [];
$orderPage = ['items' => [], 'nextOffset' => null];
$statusFilter = $_GET['status'] ?? 'all';
$paymentFilter = $_GET['payment'] ?? 'all';
$orderSearch = $_GET['q'] ?? '';
$rawOffset = $_GET['offset'] ?? '0';
$offset = is_string($rawOffset) ? filter_var($rawOffset, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 100000]]) : false;
if (!is_string($statusFilter) || !in_array($statusFilter,
    ['pending', 'paid', 'processing', 'ready_to_ship', 'shipped', 'completed', 'cancelled', 'test', 'all'], true) ||
    !is_string($paymentFilter) || !in_array($paymentFilter,
        ['all', 'bank_transfer', 'comgate', 'gopay', 'btcpay', 'test'], true) ||
    !is_string($orderSearch) || strlen($orderSearch) > 100 ||
    preg_match('//u', $orderSearch) !== 1 ||
    preg_match('/[\x00-\x1f\x7f]/', $orderSearch) ||
    $offset === false) {
    http_response_code(422);
    $orderError = 'Neplatný filtr objednávek.';
    $statusFilter = 'all';
    $paymentFilter = 'all';
    $orderSearch = '';
    $offset = 0;
}
$orderSearch = trim($orderSearch);
$orderListReturnUrl = $adminUrl . '?section=orders&status=' . rawurlencode($statusFilter) .
    '&payment=' . rawurlencode($paymentFilter) . '&q=' . rawurlencode($orderSearch) . '&offset=' . $offset;

if ($method === 'POST' && ($_POST['action'] ?? '') === 'mark-order-paid') {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    if (!$ordersReady || $id === false || ($_POST['bank_checked'] ?? null) !== '1') {
        http_response_code(422);
        $orderError = 'Vyber objednávku a potvrď kontrolu přijaté platby ve výpisu banky.';
    } else {
        $admin = $auth->user();
        if ($admin === null) {
            throw new RuntimeException('Přihlášení správce vypršelo.');
        }
        try {
            $orders->markPaid($id, (int) $admin['id']);
            try { $orderMail->notifyStage($id, 'paid', $orderMailSender); }
            catch (Throwable $mailError) { error_log('Payment notification: ' . $mailError->getMessage()); }
            header('Location: ' . (($_POST['return_list'] ?? null) === '1'
                ? $orderListReturnUrl . '&payment_saved=1'
                : $adminUrl . '?section=orders&id=' . $id . '&paid=1'), true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $orderError = $exception->getMessage();
        }
    }
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'comgate-refresh') {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    try {
        $targetOrder = $id === false || !$ordersReady ? null : $orders->findById($id);
        if ($targetOrder === null || ($targetOrder['payment_method'] ?? '') !== 'comgate' ||
            !is_string($targetOrder['provider_reference'] ?? null) ||
            $targetOrder['provider_reference'] === '') {
            throw new InvalidArgumentException('Pro objednávku zatím není dostupná transakce Comgate.');
        }
        (new ComgatePaymentService($db, $checkoutSettings['comgate'] ?? []))->refresh($targetOrder);
        header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&payment_checked=1', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $orderError = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(503);
        $orderError = $exception->getMessage();
    }
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'gopay-refresh') {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    try {
        $targetOrder = $id === false || !$ordersReady ? null : $orders->findById($id);
        if ($targetOrder === null || ($targetOrder['payment_method'] ?? '') !== 'gopay' ||
            !is_string($targetOrder['provider_reference'] ?? null) ||
            $targetOrder['provider_reference'] === '') {
            throw new InvalidArgumentException('Pro objednávku zatím není dostupná transakce GoPay.');
        }
        (new GoPayPaymentService($db, $goPaySettings))->refresh($targetOrder);
        header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&payment_checked=1', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $orderError = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(503);
        $orderError = $exception->getMessage();
    }
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'btcpay-refresh') {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    try {
        $targetOrder = $id === false || !$ordersReady ? null : $orders->findById($id);
        if ($targetOrder === null || ($targetOrder['payment_method'] ?? '') !== 'btcpay' ||
            !is_string($targetOrder['provider_reference'] ?? null) ||
            $targetOrder['provider_reference'] === '') {
            throw new InvalidArgumentException('Pro objednávku zatím není dostupná faktura BTCPay.');
        }
        (new BTCPayPaymentService($db, $btcpaySettings))->refresh($targetOrder);
        header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&payment_checked=1', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $orderError = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(503);
        $orderError = $exception->getMessage();
    }
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'set-order-status') {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    $newStatus = $_POST['order_status'] ?? null;
    $source = $_POST['fulfillment_source'] ?? 'own';
    $note = $_POST['fulfillment_note'] ?? '';
    if (!$ordersReady || $id === false || !is_string($newStatus) ||
        !is_string($source) || !is_string($note)) {
        http_response_code(422);
        $orderError = 'Vyber objednávku a platný stav.';
    } else {
        try {
            $orders->setFulfillmentStatus($id, $newStatus, $source, $note);
            try { $orderMail->notifyStage($id, $newStatus, $orderMailSender); }
            catch (Throwable $mailError) { error_log('Order stage notification: ' . $mailError->getMessage()); }
            header('Location: ' . (($_POST['return_list'] ?? null) === '1'
                ? $orderListReturnUrl . '&saved=1'
                : $adminUrl . '?section=orders&id=' . $id . '&saved=1'), true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $orderError = $exception->getMessage();
        }
    }
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'save-order-tracking') {
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if (!$ordersReady || $id === false || $id === null || $orders->findById((int) $id) === null ||
            !is_string($_POST['tracking_number'] ?? null) || !is_string($_POST['tracking_url'] ?? null)) {
            throw new InvalidArgumentException('Objednávka nebo sledovací údaje nejsou platné.');
        }
        $previous = $trackingStore->manual((int) $id);
        $trackingStore->save((int) $id, $_POST['tracking_number'], $_POST['tracking_url']);
        $current = $trackingStore->manual((int) $id);
        if ($current !== $previous && ($current['number'] !== '' || $current['url'] !== '')) {
            try {
                $key = 'tracking:' . (int) $id . ':' . substr(hash('sha256', $current['number'] . "\n" . $current['url']), 0, 24);
                $orderMail->notifyStage((int) $id, 'tracking', $orderMailSender, $key);
            } catch (Throwable $mailError) { error_log('Tracking notification: ' . $mailError->getMessage()); }
        }
        header('Location: ' . $adminUrl . '?section=orders&id=' . (int) $id . '&tracking_saved=1', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $orderError = $exception->getMessage();
    }
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'change-order-shipping') {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    $newMethod = $_POST['shipping_method'] ?? null;
    $expectedMethod = $_POST['expected_method'] ?? null;
    $reason = $_POST['reason'] ?? null;
    try {
        if ($id === false || !$ordersReady || !$shippingChangeReady ||
            !is_string($newMethod) || !is_string($expectedMethod) || !is_string($reason)) {
            throw new InvalidArgumentException('Vyber objednávku, dopravce a vyplň důvod změny.');
        }
        $admin = $auth->user();
        if ($admin === null) throw new RuntimeException('Přihlášení správce vypršelo.');
        $homeAddress = [
            'street' => $_POST['shipping_street'] ?? null,
            'city' => $_POST['shipping_city'] ?? null,
            'postal_code' => $_POST['shipping_postal_code'] ?? null,
        ];
        $orderShipping->change($id, (int) $admin['id'], $newMethod, $expectedMethod,
            $reason, ($_POST['draft_not_submitted'] ?? '') === '1', $homeAddress,
            ($_POST['address_confirmed'] ?? '') === '1');
        header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&shipping_saved=1', true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $orderError = $exception->getMessage();
    }
}

if ($method === 'POST' && in_array($_POST['action'] ?? '',
    ['correct-order-status', 'correct-order-payment', 'delete-order'], true)) {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    $reason = $_POST['reason'] ?? null;
    $confirmation = $_POST['confirmation'] ?? null;
    $target = $_POST['order_status'] ?? null;
    $number = $_POST['order_number'] ?? null;
    try {
        if (!$ordersReady || !$orderControlsReady) {
            throw new InvalidArgumentException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        }
        if ($id === false || !is_string($reason) || !is_string($confirmation) ||
            ($_POST['verified'] ?? null) !== '1') {
            throw new InvalidArgumentException('Vyber objednávku, potvrď akci a vyplň důvod.');
        }
        $admin = $auth->user();
        if ($admin === null) throw new RuntimeException('Přihlášení správce vypršelo.');
        if ($_POST['action'] === 'correct-order-status') {
            if (!is_string($target)) throw new InvalidArgumentException('Vyber cílový stav.');
            $orderControls->correctFulfillment($id, $target, (int) $admin['id'], $reason, $confirmation);
            header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&corrected=1', true, 303);
        } elseif ($_POST['action'] === 'correct-order-payment') {
            $orderControls->correctPayment($id, (int) $admin['id'], $reason, $confirmation);
            header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&payment_corrected=1', true, 303);
        } else {
            if (!is_string($number) || $confirmation !== 'delete') {
                throw new InvalidArgumentException('Potvrď smazání a opiš přesné číslo objednávky.');
            }
            $orderControls->deleteOrder($id, $number, (int) $admin['id'], $reason);
            header('Location: ' . $adminUrl . '?section=orders&deleted=1', true, 303);
        }
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $orderError = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(409);
        $orderError = $exception->getMessage();
    }
}

$carrierAction = $method === 'POST' ? ($_POST['action'] ?? '') : '';
if (in_array($carrierAction, ['carrier-save', 'carrier-register'], true)) {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    try {
        if ($id === false || !$ordersReady || !$carrierReady ||
            ($targetOrder = $orders->findById($id)) === null) {
            throw new InvalidArgumentException('Objednávka nebo tabulka podkladů chybí. Aktualizuj SQL tabulky v sekci Databáze.');
        }
        $admin = $auth->user();
        if ($admin === null) throw new RuntimeException('Přihlášení správce vypršelo.');
        if ($carrierAction === 'carrier-save') {
            $draft = CarrierShipmentDraft::fromOrder($targetOrder, $_POST);
            $carrierShipments->save($id, (int) $admin['id'], $draft);
        } else {
            $number = $_POST['tracking_number'] ?? null;
            if (($_POST['carrier_confirmed'] ?? '') !== '1' || !is_string($number)) {
                throw new InvalidArgumentException('Nejdřív ověř podání v systému dopravce a zadej přidělené číslo.');
            }
            $carrierShipments->register($id, (int) $admin['id'], $number);
            try { $orderMail->notifyStage($id, 'tracking', $orderMailSender,
                'tracking:' . $id . ':' . substr(hash('sha256', $number), 0, 24)); }
            catch (Throwable $mailError) { error_log('Carrier notification: ' . $mailError->getMessage()); }
        }
        header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&carrier_saved=' .
            rawurlencode($carrierAction), true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $orderError = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(503);
        $orderError = $exception->getMessage();
    }
}

$packetaAction = $method === 'POST' ? ($_POST['action'] ?? '') : '';
if (in_array($packetaAction, ['packeta-create', 'packeta-courier',
    'packeta-reconcile', 'packeta-retry', 'packeta-cancel',
    'packeta-cancel-confirmed', 'packeta-cancel-not-done'], true)) {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    try {
        if ($id === false || !$ordersReady || !$packetaReady ||
            ($targetOrder = $orders->findById($id)) === null) {
            throw new InvalidArgumentException('Objednávka nebo tabulka zásilek nebyla nalezena. Aktualizuj SQL tabulky v sekci Databáze.');
        }
        if ($packetaAction === 'packeta-create') {
            if (!$packetaConfigured) throw new InvalidArgumentException('Doplň API heslo a označení odesílatele v nastavení obchodu.');
            if (($targetOrder['shipping']['method'] ?? '') === 'zasilkovna_pickup') {
                $newPoint = $_POST['pickup_point_id'] ?? null;
                if (!is_string($newPoint) || $newPoint === '') {
                    throw new InvalidArgumentException('Zadej ID výdejního místa Zásilkovny.');
                }
                if ($newPoint !== (string) ($targetOrder['shipping']['pickup_code'] ?? '') ||
                    ($targetOrder['shipping']['pickup_verified'] ?? false) !== true) {
                    $verified = (new PacketaPickupPoint((string) ($packetaCredentials['api_key'] ?? '')))
                        ->verify($newPoint);
                    $targetOrder['shipping'] = array_replace($targetOrder['shipping'], $verified,
                        ['pickup_verified' => true]);
                }
            }
            $draft = PacketaShipmentDraft::fromOrder($targetOrder, $_POST,
                (string) $packetaCredentials['sender']);
            $client = new PacketaApiClient((string) $packetaCredentials['api_password']);
            $admin = $auth->user();
            if ($admin === null) throw new RuntimeException('Přihlášení správce vypršelo.');
            $packetaShipments->reserve($id, (int) $admin['id'], $draft);
            try {
                $packet = $client->createPacket($draft['attributes']);
            } catch (PacketaRejectedException $exception) {
                $packetaShipments->failed($id, $exception->getMessage(), true);
                throw $exception;
            } catch (Throwable $exception) {
                $packetaShipments->failed($id, 'Výsledek podání není jistý. Zkontroluj klientskou sekci Zásilkovny.', false);
                throw new RuntimeException('Výsledek podání není jistý. Zkontroluj zásilku v klientské sekci podle čísla objednávky.');
            }
            $packetaShipments->complete($id, $packet);
        } elseif ($packetaAction === 'packeta-courier') {
            if (!$packetaConfigured) throw new InvalidArgumentException('Doplň API heslo a odesílatele.');
            $shipment = $packetaShipments->find($id);
            if ($shipment === null || $shipment['status'] !== 'created' ||
                $shipment['method'] !== 'zasilkovna_home' || $shipment['courier_number'] !== null) {
                throw new InvalidArgumentException('Číslo dopravce nelze nyní vyžádat.');
            }
            $number = (new PacketaApiClient((string) $packetaCredentials['api_password']))
                ->courierNumber((string) $shipment['barcode']);
            $packetaShipments->storeCourierNumber($id, (string) $shipment['barcode'], $number);
        } elseif ($packetaAction === 'packeta-reconcile') {
            if (($_POST['packeta_checked'] ?? '') !== '1') {
                throw new InvalidArgumentException('Potvrď kontrolu zásilky v klientské sekci.');
            }
            $shipment = $packetaShipments->find($id);
            if ($shipment === null || !in_array($shipment['status'], ['uncertain', 'submitting'], true) ||
                ($shipment['status'] === 'submitting' &&
                    strtotime((string) $shipment['updated_at'] . ' UTC') > time() - 60)) {
                throw new InvalidArgumentException('Zásilka nemá nejasný stav.');
            }
            $barcode = $_POST['packeta_barcode'] ?? null;
            $packetaShipments->reconcile($id, is_string($barcode) ? trim($barcode) : '');
        } elseif ($packetaAction === 'packeta-cancel') {
            if (!$packetaConfigured || !$packetaCancelReady) {
                throw new InvalidArgumentException('Doplň API heslo nebo aktualizuj SQL tabulky v sekci Databáze.');
            }
            if (($_POST['packeta_cancel_confirmed'] ?? '') !== '1') {
                throw new InvalidArgumentException('Potvrď, že balík ještě nebyl fyzicky předán dopravci.');
            }
            $admin = $auth->user();
            if ($admin === null) throw new RuntimeException('Přihlášení správce vypršelo.');
            $packetId = $packetaShipments->reserveCancellation($id, (int) $admin['id']);
            try {
                (new PacketaApiClient((string) $packetaCredentials['api_password']))->cancelPacket($packetId);
            } catch (PacketaRejectedException $exception) {
                $packetaShipments->cancellationFailed($id, $exception->getMessage(), true);
                throw $exception;
            } catch (Throwable $exception) {
                $packetaShipments->cancellationFailed($id,
                    'Výsledek storna je nejasný. Ověř zásilku v klientské sekci Zásilkovny.', false);
                throw new RuntimeException('Výsledek storna je nejasný. Zkontroluj zásilku u Zásilkovny; nové podání je zablokované.');
            }
            try {
                $packetaShipments->completeCancellation($id, (int) $admin['id'], 'cancelling');
            } catch (Throwable $exception) {
                $packetaShipments->cancellationFailed($id,
                    'Zásilkovna storno potvrdila, ale uložení do databáze selhalo. Potvrď výsledek po kontrole.', false);
                throw new RuntimeException('Storno Zásilkovna potvrdila, ale místní zápis selhal. Zkontroluj výsledek v klientské sekci.');
            }
        } elseif (in_array($packetaAction, ['packeta-cancel-confirmed', 'packeta-cancel-not-done'], true)) {
            if (!$packetaCancelReady || ($_POST['packeta_cancel_checked'] ?? '') !== '1') {
                throw new InvalidArgumentException('Potvrď kontrolu storna v klientské sekci Zásilkovny.');
            }
            $shipment = $packetaShipments->find($id);
            if ($shipment === null || !in_array($shipment['status'], ['cancelling', 'cancel_uncertain'], true) ||
                ($shipment['status'] === 'cancelling' &&
                    strtotime((string) $shipment['updated_at'] . ' UTC') > time() - 60)) {
                throw new InvalidArgumentException('Storno nemá nejasný výsledek. Obnov stránku.');
            }
            if ($packetaAction === 'packeta-cancel-confirmed') {
                $admin = $auth->user();
                if ($admin === null) throw new RuntimeException('Přihlášení správce vypršelo.');
                $packetaShipments->completeCancellation($id, (int) $admin['id'], $shipment['status']);
            } else {
                $packetaShipments->cancellationNotDone($id, $shipment['status']);
            }
        } elseif ($packetaAction === 'packeta-retry') {
            if (($_POST['packeta_not_created'] ?? '') !== '1') {
                throw new InvalidArgumentException('Nejprve potvrď, že zásilka u Zásilkovny nevznikla.');
            }
            $shipment = $packetaShipments->find($id);
            if ($shipment === null || !in_array($shipment['status'], ['uncertain', 'submitting'], true) ||
                strtotime((string) $shipment['updated_at'] . ' UTC') > time() - 60) {
                throw new InvalidArgumentException('Nejasné podání lze opakovat až po minutě a kontrole v klientské sekci.');
            }
            $packetaShipments->allowRetry($id);
        }
        header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&packeta_result=' .
            rawurlencode($packetaAction), true, 303);
        exit;
    } catch (InvalidArgumentException|PacketaRejectedException $exception) {
        http_response_code(422);
        $orderError = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(503);
        $orderError = $exception->getMessage();
    }
}

$rawId = $_GET['id'] ?? null;
if ($rawId !== null) {
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    if ($id === false || !$ordersReady || ($order = $orders->findById($id)) === null) {
        http_response_code(404);
        $orderError = 'Objednávka nebyla nalezena.';
    }
    if ($order !== null) {
        $orderProductLinks = (new OrderProductLinks($db))->forItems($order['items'] ?? [], $basePath);
    }
    if ($order !== null && $packetaReady) {
        $packetaShipment = $packetaShipments->find($id);
        $cancelledPackets = $packetaShipments->cancelledForOrder($id);
        $packetaTrackingUrl = PacketaShipmentRepository::trackingUrl($packetaShipment);
    }
    if ($order !== null && $carrierReady) {
        $carrierShipment = $carrierShipments->find($id);
    }
    if ($order !== null && $orderTrackingReady) {
        $orderManualTracking = $trackingStore->manual($id);
    }
    if ($order !== null && $shippingChangeReady &&
        ($packetaShipment === null || in_array($packetaShipment['status'], ['cancelled', 'rejected'], true)) &&
        ($carrierShipment === null || $carrierShipment['status'] === 'draft')) {
        $shippingChangeOptions = $orderShipping->optionsFor($order);
        $shippingChangeNeedsAddress = $orderShipping->needsAddress($order);
    }
    if ($order !== null && $orderControlsReady) {
        $orderEvents = $orderControls->eventsForOrder($id);
    }
    if ($order !== null && ($order['payment_method'] ?? '') === 'gopay' && $goPayConfigured) {
        try {
            $goPayState = (new GoPayPaymentService($db, $goPaySettings))->state($id);
        } catch (RuntimeException $exception) {
            // A malformed credential must not make unrelated order administration unavailable.
            $goPayState = null;
        }
    }
    if ($order !== null && ($order['payment_method'] ?? '') === 'btcpay' && $btcpayConfigured) {
        try {
            $btcpayState = (new BTCPayPaymentService($db, $btcpaySettings))->state($id);
        } catch (RuntimeException $exception) {
            $btcpayState = null;
        }
    }
    if ($order !== null) {
        $taxEvidence = new TaxEvidenceRepository($db);
        $invoiceStore = new InvoiceRepository($db);
        $orderTaxReady = $taxEvidence->installed();
        $orderInvoiceReady = $invoiceStore->installed();
        if ($orderTaxReady) {
            $sellerSettings = $taxEvidence->settings();
            $orderReceipt = $taxEvidence->orderReceipt($id);
        }
        if ($orderInvoiceReady) $orderInvoice = $invoiceStore->byOrder($id);
    }
} elseif ($ordersReady) {
    $orderPage = $orders->managementPage($offset, 25,
        $statusFilter === 'all' ? null : $statusFilter,
        $paymentFilter === 'all' ? null : $paymentFilter, $orderSearch);
}

if ($method === 'GET' && ($_GET['carrier_csv'] ?? null) === '1' && $order !== null) {
    try {
        if (($order['payment_method'] ?? '') === 'gopay' &&
            ($goPayState === null || ($goPayState['status'] ?? '') !== 'paid' ||
            (string) ($goPayState['payment_id'] ?? '') !== (string) ($order['provider_reference'] ?? ''))) {
            throw new InvalidArgumentException('Nejdřív ověř, že platba GoPay nebyla vrácena.');
        }
        if (($order['payment_method'] ?? '') === 'btcpay') {
            (new BTCPayPaidOrderGuard($db))->assertPaid((int) $order['id'],
                (string) ($order['provider_reference'] ?? ''));
        }
        if ($carrierShipment === null || !is_array($carrierShipment['draft'] ?? null) ||
            !in_array($carrierShipment['method'], ['gls_pickup', 'gls_home'], true) ||
            !in_array($carrierShipment['status'], ['draft', 'registered'], true)) {
            throw new InvalidArgumentException('Podklady k exportu zatím nejsou uložené.');
        }
        $csv = CarrierShipmentCsv::export($carrierShipment['draft']);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="gls-ebalik-' . (int) $order['id'] . '.csv"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $csv;
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $orderError = $exception->getMessage();
    }
}

if ($method === 'GET' && ($_GET['packeta_label'] ?? null) === '1' && $order !== null) {
    try {
        if (!$packetaConfigured || $packetaShipment === null ||
            $packetaShipment['status'] !== 'created') {
            throw new InvalidArgumentException('Štítek ještě není dostupný.');
        }
        $home = $packetaShipment['method'] === 'zasilkovna_home';
        if ($home && !$packetaShipment['courier_number']) {
            throw new InvalidArgumentException('Nejprve vyžádej číslo dopravce pro doručení domů.');
        }
        $pdf = (new PacketaApiClient((string) $packetaCredentials['api_password']))
            ->labelPdf((string) $packetaShipment['barcode'],
                $home ? (string) $packetaShipment['courier_number'] : null);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="packeta-' . $packetaShipment['barcode'] . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    } catch (InvalidArgumentException|RuntimeException $exception) {
        http_response_code(503);
        $orderError = $exception->getMessage();
    }
}

$orderBaseUrl = $adminUrl . '?section=orders';
$orderPageUrl = $orderBaseUrl . '&status=' . rawurlencode($statusFilter) .
    '&payment=' . rawurlencode($paymentFilter) . '&q=' . rawurlencode($orderSearch);
$ordersPreviousUrl = $offset > 0 ? $orderPageUrl . '&offset=' . max(0, $offset - 25) : '';
$ordersNextUrl = $orderPage['nextOffset'] === null ? '' : $orderPageUrl . '&offset=' . (int) $orderPage['nextOffset'];
