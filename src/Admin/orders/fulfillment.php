<?php
declare(strict_types=1);

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

