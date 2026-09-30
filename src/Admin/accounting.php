<?php
declare(strict_types=1);

use SimpleStore\Accounting\AccountingRepository;

// admin.php authenticates this route. Downloads and HTML both use the same bounded filter.
$screen = 'accounting';
$accounting = new AccountingRepository($db);
$accountingReady = $accounting->installed();
$accountingError = '';
$accountingBaseUrl = $adminUrl . '?section=accounting';
$accountingPage = ['items' => [], 'nextOffset' => null];
$accountingTotals = ['count' => 0, 'subtotal_czk' => 0, 'shipping_czk' => 0, 'total_czk' => 0];
$accountingPreviousUrl = '';
$accountingNextUrl = '';
$accountingExportUrl = '';
[$accountingFrom, $accountingTo] = AccountingRepository::period(null, null);

try {
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
    $filters = ['section' => 'accounting', 'from' => $accountingFrom, 'to' => $accountingTo];
    $accountingExportUrl = $adminUrl . '?' . http_build_query($filters + ['download' => 'csv']);
    $accountingPreviousUrl = $accountingOffset > 0 ? $adminUrl . '?' . http_build_query(
        $filters + ['offset' => max(0, $accountingOffset - 25)]) : '';
    $accountingNextUrl = $accountingPage['nextOffset'] === null ? '' : $adminUrl . '?' .
        http_build_query($filters + ['offset' => $accountingPage['nextOffset']]);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    $accountingError = $exception->getMessage();
} catch (RuntimeException $exception) {
    http_response_code(503);
    $accountingError = $exception->getMessage();
}
