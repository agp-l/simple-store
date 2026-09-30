<?php
declare(strict_types=1);

use SimpleStore\Accounting\AccountingRepository;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Accounting\InvoiceRepository;
use SimpleStore\Accounting\OrderMailQueue;

// admin.php authenticates this route. Downloads and HTML both use the same bounded filter.
$screen = 'accounting';
$accounting = new AccountingRepository($db);
$tax = new TaxEvidenceRepository($db);
$invoicesRepository = new InvoiceRepository($db);
$mailQueue = new OrderMailQueue($db);
$taxReady = $tax->installed();
$invoicesReady = $invoicesRepository->installed();
$mailReady = $mailQueue->installed();
$taxSettings = $tax->settings();
$accountingTab = $_POST['tab'] ?? $_GET['tab'] ?? 'overview';
if (!is_string($accountingTab) || !in_array($accountingTab,
    ['overview', 'money', 'balances', 'stock', 'invoices', 'mail', 'orders', 'settings'], true)) {
    $accountingTab = 'overview';
}
$rawYear = $_GET['year'] ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format('Y');
$taxYear = is_string($rawYear) && ctype_digit($rawYear) ? (int) $rawYear : 0;
$taxEntries = $taxBalances = $taxProducts = $saleLines = $stockMovements = [];
$taxReceivables = [];
$invoiceRows = $mailRows = $invoiceHistory = [];
$taxSummary = ['income' => 0, 'expenses' => 0];
$selectedInvoice = null;
$accountingReady = $accounting->installed();
$financialEventsReady = $accounting->financialEventsInstalled();
$financialChanges = ['items' => [], 'nextOffset' => null];
$financialPreviousUrl = '';
$financialNextUrl = '';
$accountingError = '';
$accountingBaseUrl = $adminUrl . '?section=accounting';
$accountingPage = ['items' => [], 'nextOffset' => null];
$accountingTotals = ['count' => 0, 'subtotal_czk' => 0, 'shipping_czk' => 0, 'total_czk' => 0];
$accountingPreviousUrl = '';
$accountingNextUrl = '';
$accountingExportUrl = '';
[$accountingFrom, $accountingTo] = AccountingRepository::period(null, null);

if ($method === 'POST') {
    try {
        if (!$taxReady || !$invoicesReady || !$mailReady) {
            throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        }
        $action = $_POST['action'] ?? '';
        $rawId = $_POST['id'] ?? null;
        $id = is_string($rawId) && ctype_digit($rawId) ? (int) $rawId : 0;
        $admin = $auth->user();
        if ($admin === null) throw new RuntimeException('Přihlášení správce vypršelo.');
        $returnOrder = 0;
        switch ($action) {
            case 'tax-save-settings':
                $tax->saveSettings($_POST);
                $accountingTab = 'settings';
                break;
            case 'tax-add-entry':
                $tax->addEntry($_POST);
                $accountingTab = 'money';
                break;
            case 'tax-add-balance':
                $tax->addBalance($_POST);
                $accountingTab = 'balances';
                break;
            case 'tax-close-balance':
                $tax->closeBalance($id, (string) ($_POST['closed_on'] ?? ''));
                $accountingTab = 'balances';
                break;
            case 'tax-add-stock':
                $tax->addStock($_POST);
                $accountingTab = 'stock';
                break;
            case 'tax-backfill-sales':
                $tax->backfillSaleLines();
                $accountingTab = 'stock';
                break;
            case 'tax-link-payment':
                $tax->addOrderReceipt($id, $_POST);
                $returnOrder = $id;
                break;
            case 'invoice-issue':
                $invoice = $invoicesRepository->issue($id, $taxSettings, [
                    'name' => $_POST['buyer_name'] ?? '',
                    'street' => $_POST['buyer_street'] ?? '',
                    'city' => $_POST['buyer_city'] ?? '',
                    'postal_code' => $_POST['buyer_postal_code'] ?? '',
                    'ico' => $_POST['buyer_ico'] ?? '',
                ]);
                $returnOrder = $id;
                if ($mailReady) {
                    try {
                        $mailId = $mailQueue->enqueueInvoice($invoice);
                        if ($taxSettings['mail_from'] !== '') $mailQueue->dispatch($mailId, $taxSettings['mail_from']);
                    } catch (Throwable $mailError) {
                        error_log('Invoice mail queue failed: ' . $mailError->getMessage());
                    }
                }
                break;
            case 'invoice-renumber':
                $invoicesRepository->renumber($id, (string) ($_POST['document_number'] ?? ''),
                    (int) $admin['id'], (string) ($_POST['reason'] ?? ''));
                $renumbered = $invoicesRepository->byId($id);
                if ($renumbered !== null && $mailReady) $mailQueue->enqueueInvoice($renumbered);
                $accountingTab = 'invoices';
                break;
            case 'invoice-email':
                $invoice = $invoicesRepository->byId($id);
                if ($invoice === null) throw new InvalidArgumentException('Faktura nebyla nalezena.');
                $mailId = $mailQueue->enqueueInvoice($invoice);
                if ($taxSettings['mail_from'] !== '') $mailQueue->dispatch($mailId, $taxSettings['mail_from']);
                $accountingTab = 'mail';
                break;
            case 'mail-retry':
                $mailQueue->dispatch($id, $taxSettings['mail_from']);
                $accountingTab = 'mail';
                break;
            default:
                throw new InvalidArgumentException('Neznámá účetní akce.');
        }
        header('Location: ' . ($returnOrder > 0 ?
            $adminUrl . '?section=orders&id=' . $returnOrder . '&tax_saved=1' :
            $accountingBaseUrl . '&tab=' . rawurlencode($accountingTab) . '&saved=1'), true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        $accountingError = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(503);
        $accountingError = $exception->getMessage();
    }
}

try {
    TaxEvidenceRepository::year($taxYear);
    if ($taxReady) {
        $taxSettings = $tax->settings();
        $taxSummary = $tax->summary($taxYear);
        if ($accountingTab === 'money') $taxEntries = $tax->entries($taxYear);
        if ($accountingTab === 'balances' || $accountingTab === 'overview') {
            $taxBalances = $tax->balances($taxYear);
            $taxReceivables = $tax->orderReceivables();
        }
        if ($accountingTab === 'stock') {
            $taxProducts = $tax->products(is_string($_GET['search'] ?? null) ? $_GET['search'] : '');
            $saleLines = $tax->saleLines($taxYear);
            $stockMovements = $tax->stockMovements();
        }
    }
    if ($accountingTab === 'invoices' && $invoicesReady) {
        $invoiceRows = $invoicesRepository->list($taxYear);
    }
    if ($accountingTab === 'mail' && $mailReady) $mailRows = $mailQueue->recent();
    $rawInvoice = $_GET['invoice_id'] ?? null;
    if (is_string($rawInvoice) && ctype_digit($rawInvoice) && $invoicesReady) {
        $selectedInvoice = $invoicesRepository->byId((int) $rawInvoice);
        if ($selectedInvoice !== null) $invoiceHistory = $invoicesRepository->numberHistory((int) $rawInvoice);
    }
    if (($_GET['print'] ?? '') === '1') {
        if ($selectedInvoice === null) throw new InvalidArgumentException('Faktura nebyla nalezena.');
        header('Cache-Control: private, no-store');
        require __DIR__ . '/../../view/admin/invoice-print.php';
        exit;
    }
    if ($accountingTab !== 'orders') return;
    $rawFrom = $_GET['from'] ?? null;
    $rawTo = $_GET['to'] ?? null;
    if (($rawFrom !== null && !is_string($rawFrom)) ||
        ($rawTo !== null && !is_string($rawTo))) {
        throw new InvalidArgumentException('Zadej platné datum od a do.');
    }
    [$accountingFrom, $accountingTo] = AccountingRepository::period($rawFrom, $rawTo);
    $rawOffset = $_GET['offset'] ?? '0';
    $accountingOffset = is_string($rawOffset) ? filter_var($rawOffset, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => 1000000]]) : false;
    if ($accountingOffset === false) {
        throw new InvalidArgumentException('Neplatná stránka účetních podkladů.');
    }
    $rawFinancialOffset = $_GET['audit_offset'] ?? '0';
    $financialOffset = is_string($rawFinancialOffset) ? filter_var($rawFinancialOffset, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => 1000000]]) : false;
    if ($financialOffset === false) {
        throw new InvalidArgumentException('Neplatná stránka historie zásahů.');
    }
    if (!$accountingReady) {
        throw new RuntimeException('Přehled vyžaduje aktuální tabulku objednávek.');
    }

    if (($_GET['download'] ?? null) === 'csv') {
        // Finish preparation before HTTP headers, so an error still produces the admin error page.
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('CSV se nepodařilo připravit.');
        }
        try {
            $accounting->writeCsv($stream, $accountingFrom, $accountingTo);
            rewind($stream);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="ucetni-podklady-' .
                $accountingFrom . '_' . $accountingTo . '.csv"');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            fpassthru($stream);
        } finally {
            fclose($stream);
        }
        exit;
    }

    $accountingTotals = $accounting->summary($accountingFrom, $accountingTo);
    $accountingPage = $accounting->page($accountingFrom, $accountingTo, $accountingOffset);
    $financialChanges = $accounting->financialChanges($accountingFrom, $accountingTo, $financialOffset);
    $filters = ['section' => 'accounting', 'tab' => 'orders',
        'from' => $accountingFrom, 'to' => $accountingTo];
    $accountingExportUrl = $adminUrl . '?' . http_build_query($filters + ['download' => 'csv']);
    $accountingPreviousUrl = $accountingOffset > 0 ? $adminUrl . '?' . http_build_query(
        $filters + ['offset' => max(0, $accountingOffset - 25)]) : '';
    $accountingNextUrl = $accountingPage['nextOffset'] === null ? '' : $adminUrl . '?' .
        http_build_query($filters + ['offset' => $accountingPage['nextOffset']]);
    $financialPreviousUrl = $financialOffset > 0 ? $adminUrl . '?' . http_build_query(
        $filters + ['offset' => $accountingOffset, 'audit_offset' => max(0, $financialOffset - 25)]) : '';
    $financialNextUrl = $financialChanges['nextOffset'] === null ? '' : $adminUrl . '?' .
        http_build_query($filters + ['offset' => $accountingOffset,
            'audit_offset' => $financialChanges['nextOffset']]);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    $accountingError = $exception->getMessage();
} catch (RuntimeException $exception) {
    http_response_code(503);
    $accountingError = $exception->getMessage();
}
