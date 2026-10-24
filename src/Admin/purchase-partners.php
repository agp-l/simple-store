<?php
declare(strict_types=1);

use SimpleStore\Product\PurchasePartnerRepository;

// Session authentication and POST CSRF validation are enforced by admin.php.
$screen = 'purchase-partners';
$partnersRepository = new PurchasePartnerRepository($db);
$partnersReady = $partnersRepository->installed();
$partnerError = '';
$partnerForm = [];
$rawId = $method === 'POST' ? ($_POST['id'] ?? '0') : ($_GET['id'] ?? '0');
$partnerId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($partnerId === false) throw new InvalidArgumentException('Neplatný prodávající.');
$selectedPartner = $partnerId > 0 ? $partnersRepository->find($partnerId) : null;
if ($partnerId > 0 && $selectedPartner === null && $partnersReady) {
    $partnerError = 'Prodávající nebyl nalezen.';
}
$partnerForm = $selectedPartner ?? ['id' => 0, 'version' => 0, 'confirmed' => 0];
if ($method === 'POST' && $action === 'purchase-partner-save') {
    // Keep every submitted value when validation or an optimistic lock fails.
    foreach (array_keys(PurchasePartnerRepository::FIELDS) as $field) {
        $partnerForm[$field] = is_string($_POST[$field] ?? null) ? $_POST[$field] : '';
    }
    $partnerForm['confirmed'] = ($_POST['confirmed'] ?? '') === '1' ? 1 : 0;
    $partnerForm['version'] = is_scalar($_POST['version'] ?? null) ? $_POST['version'] : '';
    $partnerForm['id'] = $partnerId;
    try {
        $partnerId = $partnersRepository->save($partnerId, $_POST);
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
$partnerMissing = PurchasePartnerRepository::missing($partnerForm);
$partnerQuery = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if ($partnerQuery !== '') {
    $partnerRows = array_values(array_filter($partnerRows, static fn (array $row): bool =>
        stripos(implode(' ', [$row['name'], $row['ico'], $row['email'], $row['address']]), $partnerQuery) !== false));
}
