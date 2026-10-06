<?php
declare(strict_types=1);

use SimpleStore\Checkout\PacketaApiClient;
use SimpleStore\Checkout\PacketaPickupPoint;
use SimpleStore\Checkout\PacketaRejectedException;
use SimpleStore\Checkout\PacketaShipmentDraft;

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

