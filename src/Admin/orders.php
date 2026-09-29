<?php
declare(strict_types=1);

use SimpleStore\Checkout\OrderRepository;
use SimpleStore\Checkout\CheckoutSettingsRepository;
use SimpleStore\Checkout\PacketaApiClient;
use SimpleStore\Checkout\PacketaRejectedException;
use SimpleStore\Checkout\PacketaShipmentDraft;
use SimpleStore\Checkout\PacketaShipmentRepository;

// admin.php has already authenticated the administrator and verified POST CSRF.
$screen = 'orders';
$orders = new OrderRepository($db);
$ordersReady = $orders->installed();
$packetaShipments = new PacketaShipmentRepository($db);
$packetaReady = $packetaShipments->installed();
$checkoutExample = require __DIR__ . '/../../config/checkout.example.php';
$checkoutLocal = __DIR__ . '/../../config/checkout.php';
$checkoutSettings = (new CheckoutSettingsRepository($db))->load(
    CheckoutSettingsRepository::withDefaults(is_file($checkoutLocal) ?
        require $checkoutLocal : $checkoutExample, $checkoutExample));
$packetaCredentials = $checkoutSettings['packeta'] ?? [];
$packetaConfigured = ($packetaCredentials['api_password'] ?? '') !== '' &&
    ($packetaCredentials['sender'] ?? '') !== '';
$orderError = '';
$order = null;
$packetaShipment = null;
$orderPage = ['items' => [], 'nextOffset' => null];
$statusFilter = $_GET['status'] ?? 'all';
$rawOffset = $_GET['offset'] ?? '0';
$offset = is_string($rawOffset) ? filter_var($rawOffset, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 100000]]) : false;
if (!is_string($statusFilter) || !in_array($statusFilter, ['pending', 'paid', 'test', 'all'], true) ||
    $offset === false) {
    http_response_code(422);
    $orderError = 'Neplatný filtr objednávek.';
    $statusFilter = 'all';
    $offset = 0;
}

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
        $orders->markPaid($id, (int) $admin['id']);
        header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&paid=1', true, 303);
        exit;
    }
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'set-order-status') {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    $newStatus = $_POST['order_status'] ?? null;
    if (!$ordersReady || $id === false || !is_string($newStatus)) {
        http_response_code(422);
        $orderError = 'Vyber objednávku a platný stav.';
    } else {
        try {
            $orders->setFulfillmentStatus($id, $newStatus);
            header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&saved=1', true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $orderError = $exception->getMessage();
        }
    }
}

$packetaAction = $method === 'POST' ? ($_POST['action'] ?? '') : '';
if (in_array($packetaAction, ['packeta-create', 'packeta-courier',
    'packeta-reconcile', 'packeta-retry'], true)) {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    try {
        if ($id === false || !$ordersReady || !$packetaReady ||
            ($targetOrder = $orders->findById($id)) === null) {
            throw new InvalidArgumentException('Objednávka nebo tabulka zásilek nebyla nalezena. Importuj aktuální database/schema.sql.');
        }
        if ($packetaAction === 'packeta-create') {
            if (!$packetaConfigured) throw new InvalidArgumentException('Doplň API heslo a označení odesílatele v nastavení obchodu.');
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
        } else {
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
        header('Location: ' . $adminUrl . '?section=orders&id=' . $id . '&packeta_saved=1', true, 303);
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
    if ($order !== null && $packetaReady) {
        $packetaShipment = $packetaShipments->find($id);
    }
} elseif ($ordersReady && $orderError === '') {
    $orderPage = $orders->managementPage($offset, 25, $statusFilter === 'all' ? null : $statusFilter);
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
$orderPageUrl = $orderBaseUrl . '&status=' . rawurlencode($statusFilter);
$ordersPreviousUrl = $offset > 0 ? $orderPageUrl . '&offset=' . max(0, $offset - 25) : '';
$ordersNextUrl = $orderPage['nextOffset'] === null ? '' : $orderPageUrl . '&offset=' . (int) $orderPage['nextOffset'];
