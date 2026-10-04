<?php
declare(strict_types=1);

use SimpleStore\AfterSales\CaseNotificationService;
use SimpleStore\AfterSales\CaseRepository;

// admin.php has already checked administrator authentication and CSRF.
$screen = 'returns';
$returns = new CaseRepository($db);
$returnsReady = $returns->installed();
$returnError = '';
$returnCase = null;
$returnRows = [];
$returnPage = ['items' => [], 'nextOffset' => null];
$returnSearch = $_GET['q'] ?? '';
$returnOffset = filter_var($_GET['offset'] ?? '0', FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'max_range' => 100000]]);
if (!is_string($returnSearch) || strlen($returnSearch) > 100 ||
    preg_match('/^[\pL\pN @._+-]*$/uD', $returnSearch) !== 1 || $returnOffset === false) {
    http_response_code(422);
    $returnSearch = '';
    $returnOffset = 0;
    $returnError = 'Neplatné hledání případů.';
}
$returnMail = 'missing';
$returnMessages = [];
$returnBaseUrl = $adminUrl . '?section=returns';
$returnId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]);

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]);
    $actor = $auth->user();
    if (!$returnsReady || !is_int($id) || $actor === null) {
        $returnError = 'Aktualizuj SQL tabulky a vyber platný případ.';
    } else {
        try {
            if ($action === 'return-delete') {
                $returns->delete($id, is_string($_POST['confirmation'] ?? null) ? $_POST['confirmation'] : '');
                header('Location: ' . $returnBaseUrl . '&deleted=1', true, 303);
                exit;
            }
            if ($action === 'return-refund') {
                $amount = filter_var($_POST['amount_czk'] ?? null, FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]);
                if ($amount === false || ($_POST['verified'] ?? '') !== '1') {
                    throw new InvalidArgumentException('Ověř skutečné odeslání peněz a vyplň částku.');
                }
                $result = $returns->recordRefund($id, (int) $actor['id'], $amount,
                    is_string($_POST['reference'] ?? null) ? $_POST['reference'] : '');
            } elseif ($action === 'return-update') {
                $result = $returns->update($id, (int) $actor['id'],
                    is_string($_POST['state'] ?? null) ? $_POST['state'] : '',
                    is_string($_POST['message'] ?? null) ? $_POST['message'] : '',
                    is_string($_POST['resolution_type'] ?? null) ? $_POST['resolution_type'] : '',
                    is_string($_POST['repair_duration'] ?? null) ? $_POST['repair_duration'] : '');
            } else {
                throw new InvalidArgumentException('Neplatná akce případu.');
            }
            $mailProblem = false;
            if ($result['public']) {
                try {
                    (new CaseNotificationService($db))->event($result['case'], $result['event_id'], $result['message']);
                } catch (Throwable $mailError) {
                    error_log('Case outcome queue: ' . $mailError->getMessage());
                    $mailProblem = true;
                }
            }
            header('Location: ' . $returnBaseUrl . '&id=' . $id . '&saved=1' .
                ($mailProblem ? '&mail_problem=1' : ''), true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $returnError = $exception->getMessage();
            $returnId = $id;
        }
    }
}

if ($returnsReady) {
    $returnPage = $returns->latest($returnSearch, $returnOffset);
    $returnRows = $returnPage['items'];
    if ($returnId !== false && $returnId !== null) {
        $returnCase = $returns->byId($returnId);
        if ($returnCase === null) {
            http_response_code(404);
            $returnError = 'Případ nebyl nalezen.';
        } else {
            $notify = new CaseNotificationService($db);
            $returnMail = $notify->state($returnCase);
            if ((new \SimpleStore\Accounting\OrderMailQueue($db))->installed()) {
                $returnMessages = $db->query('SELECT event_key, state, last_error, sent_at
                    FROM shop_mail_outbox WHERE event_key LIKE %s ORDER BY id DESC LIMIT 30',
                    'after-sales:' . (int) $returnCase['id'] . ':%');
            }
        }
    }
}
