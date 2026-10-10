<?php
declare(strict_types=1);

use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Checkout\CarrierShipmentCsv;
use SimpleStore\Checkout\CarrierShipmentRepository;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\GoPayPaymentService;
use SimpleStore\Checkout\BTCPayPaymentService;
use SimpleStore\Checkout\BTCPayPaidOrderGuard;
use SimpleStore\Checkout\PacketaApiClient;
use SimpleStore\Checkout\PacketaShipmentRepository;
use SimpleStore\Checkout\OrderTrackingRepository;
use SimpleStore\Admin\OrderControlRepository;
use SimpleStore\Admin\OrderShippingRepository;
use SimpleStore\Admin\OrderProductLinks;
use SimpleStore\Product\ProductStockRepository;
use SimpleStore\Product\ProductSupplierLinkRepository;
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
$fioConfigured = !empty($checkoutSettings['fio_bank']['enabled']) &&
    (string) ($checkoutSettings['fio_bank']['token'] ?? '') !== '';
$fioReady = $fioConfigured && (new \SimpleStore\Checkout\FioBankReconciler(
    $db, $checkoutSettings['fio_bank']))->installed();
$orderError = '';
$order = null;
$orderProductLinks = [];
$orderSupplierLinks = [];
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
    ['pending', 'overdue', 'paid', 'processing', 'ready_to_ship', 'shipped', 'completed', 'cancelled', 'test', 'all'], true) ||
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

require __DIR__ . "/orders/payment.php";
require __DIR__ . "/orders/fulfillment.php";
require __DIR__ . "/orders/controls.php";
require __DIR__ . "/orders/carrier.php";
require __DIR__ . "/orders/packeta.php";

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
        $suppliers = new ProductSupplierLinkRepository($db);
        if ($suppliers->installed()) $orderSupplierLinks = $suppliers->forOrderItems($order['items'] ?? []);
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
