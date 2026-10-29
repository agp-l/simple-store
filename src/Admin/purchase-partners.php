<?php
declare(strict_types=1);

use SimpleStore\Product\PurchasePartnerRepository;

// Session authentication and POST CSRF validation are enforced by admin.php.
$screen = 'purchase-partners';
$partnersRepository = new PurchasePartnerRepository($db);
$partnersReady = $partnersRepository->assignmentReady();
$partnerError = '';
$partnerForm = [];
$rawId = $method === 'POST' ? ($_POST['id'] ?? '0') : ($_GET['id'] ?? '0');
$partnerId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($partnerId === false) throw new InvalidArgumentException('Neplatný prodávající.');
$selectedPartner = $partnerId > 0 ? $partnersRepository->find($partnerId) : null;
if ($partnerId > 0 && $selectedPartner === null && $partnersReady) {
    $partnerError = 'Prodávající nebyl nalezen.';
}
$deliveryCosts = new \SimpleStore\Product\PartnerDeliveryCosts($db);
$deliveryCostsReady = $deliveryCosts->installed();
$partnerRates = $deliveryCosts->all();
$partnerForm = $selectedPartner ?? ['id' => 0, 'version' => 0, 'confirmed' => 0];
$selectedRate = $partnerRates[$selectedPartner['partner_key'] ?? ''] ?? null;
$partnerForm['partner_shipping_czk'] = $selectedRate['shipping'] ?? '';
$partnerForm['partner_free_from_czk'] = $selectedRate['free_from'] ?? '';
if ($method === 'POST' && $action === 'purchase-partner-delete') {
    try {
        $deleteVersion = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($deleteVersion === false) throw new InvalidArgumentException('Obnov stránku prodávajícího.');
        $partnersRepository->delete($partnerId, $deleteVersion, ($_POST['confirm_delete'] ?? '') === '1');
        header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=purchase-partners&deleted=1'), true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        $partnerError = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log((string) $exception);
        $partnerError = 'Smazání se nezdařilo. Obnov stránku a zkus to znovu.';
    }
}
if ($method === 'POST' && $action === 'purchase-partner-save') {
    // Keep every submitted value when validation or an optimistic lock fails.
    foreach (array_keys(PurchasePartnerRepository::FIELDS) as $field) {
        $partnerForm[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }
    $partnerForm['confirmed'] = ($_POST['confirmed'] ?? '') === '1' ? 1 : 0;
    $partnerForm['priority'] = is_string($_POST['priority'] ?? null) ? $_POST['priority'] : '';
    $partnerForm['version'] = is_scalar($_POST['version'] ?? null) ? $_POST['version'] : '';
    $partnerForm['id'] = $partnerId;
    foreach (['partner_shipping_czk','partner_free_from_czk'] as $field) $partnerForm[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';

    try {
        $rateValues = $deliveryCostsReady ? \SimpleStore\Product\PartnerDeliveryCosts::values($_POST) : null;
        $db->startTransaction();
        try {
            $partnerId = $partnersRepository->save($partnerId, $_POST);
            if ($deliveryCostsReady) $deliveryCosts->save($partnersRepository->find($partnerId)['partner_key'], $rateValues);
            $db->commit();
        } catch (Throwable $error) { $db->rollback(); throw $error; }
        header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($adminUrl . '?section=purchase-partners&id=' . $partnerId . '&saved=1#partner-form'), true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        $partnerError = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log((string) $exception);
        $partnerError = 'Uložení se nezdařilo. Údaje zůstaly ve formuláři; zkus jej znovu uložit.';
    }
}
$partnerRows = $partnersRepository->all();
$partnerUsage = $partnersReady ? (new \SimpleStore\Product\PurchasePartnerUsage($db))->products($partnerRows) : [];
usort($partnerRows, static fn (array $a, array $b): int => [(int) ($a['priority'] ?? 100), $a['name'], $a['id']] <=> [(int) ($b['priority'] ?? 100), $b['name'], $b['id']]);
$partnerMissing = PurchasePartnerRepository::missing($partnerForm);
$partnerQuery = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if ($partnerQuery !== '') {
    $partnerRows = array_values(array_filter($partnerRows, static fn (array $row): bool =>
        stripos(implode(' ', [$row['name'], $row['ico'], $row['email'], $row['address'], $row['website_url'] ?? '']), $partnerQuery) !== false));
}
require __DIR__ . '/purchase-partner-transfer.php';
