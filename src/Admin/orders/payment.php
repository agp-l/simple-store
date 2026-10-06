<?php
declare(strict_types=1);

use SimpleStore\Checkout\ComgatePaymentService;
use SimpleStore\Checkout\GoPayPaymentService;
use SimpleStore\Checkout\BTCPayPaymentService;

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

if ($method === 'POST' && ($_POST['action'] ?? '') === 'cancel-overdue-bank-order') {
    $rawId = $_POST['id'] ?? null;
    $id = is_string($rawId) && ctype_digit($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]) : false;
    if (!$ordersReady || $id === false || ($_POST['bank_checked'] ?? null) !== '1') {
        http_response_code(422);
        $orderError = 'Vyber objednávku a potvrď kontrolu nepřijaté platby ve výpisu banky.';
    } else {
        try {
            $orders->cancelOverdueBankTransfer($id);
            try { $orderMail->notifyStage($id, 'cancelled', $orderMailSender); }
            catch (Throwable $mailError) { error_log('Order stage notification: ' . $mailError->getMessage()); }
            header('Location: ' . $orderListReturnUrl . '&overdue_cancelled=1', true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $orderError = $exception->getMessage();
        }
    }
}

