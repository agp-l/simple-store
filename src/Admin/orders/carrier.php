<?php
declare(strict_types=1);

use SimpleStore\Checkout\CarrierShipmentDraft;

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

