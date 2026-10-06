<?php
declare(strict_types=1);

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

