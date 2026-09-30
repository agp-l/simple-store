<?php
declare(strict_types=1);

class MeekroDB
{
    public array $rows = [];
    public ?string $missingColumn = null;
    public ?int $forcedCount = null;
    public array $financialEvents = [];
    public bool $financialInstalled = true;

    public function queryFirstField(string $sql, mixed ...$values): int
    {
        if (str_contains($sql, 'information_schema.TABLES')) {
            return $values[0] === 'shop_order_financial_events' && $this->financialInstalled ? 1 : 0;
        }
        if (!str_contains($sql, 'information_schema.COLUMNS') || $values[0] !== 'shop_orders') {
            throw new RuntimeException('Unexpected installation probe.');
        }
        return $values[1] === $this->missingColumn ? 0 : 1;
    }

    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        if (!str_contains($sql, 'COUNT(*) AS order_count')) {
            throw new RuntimeException('Unexpected accounting summary.');
        }
        $rows = $this->filtered($values);
        return [
            'order_count' => $this->forcedCount ?? count($rows),
            'subtotal_czk' => array_sum(array_column($rows, 'subtotal_czk')),
            'shipping_czk' => array_sum(array_column($rows, 'shipping_czk')),
            'total_czk' => array_sum(array_column($rows, 'total_czk')),
        ];
    }

    public function query(string $sql, mixed ...$values): array
    {
        if (str_contains($sql, 'FROM shop_order_financial_events')) {
            $rows = array_values(array_filter($this->financialEvents,
                static fn (array $row): bool => $row['created_at'] >= $values[0] &&
                    $row['created_at'] < $values[1]));
            return array_slice(array_reverse($rows), $values[3], $values[2]);
        }
        if (!str_contains($sql, 'FROM shop_orders') || !str_contains($sql, 'LIMIT %i OFFSET %i')) {
            throw new RuntimeException('Unexpected accounting page query.');
        }
        $rows = $this->filtered(array_slice($values, 0, 5));
        usort($rows, static fn (array $a, array $b): int =>
            strcmp($b['payment_paid_at'], $a['payment_paid_at']) ?: $b['id'] <=> $a['id']);
        return array_slice($rows, $values[6], $values[5]);
    }

    private function filtered(array $values): array
    {
        if ($values[0] !== 'paid' || $values[1] !== 'test' || $values[2] !== 'test') {
            throw new RuntimeException('Paid/test filter changed.');
        }
        return array_values(array_filter($this->rows, static fn (array $row): bool =>
            $row['payment_status'] === 'paid' && $row['status'] !== 'test' &&
            $row['payment_method'] !== 'test' && $row['payment_paid_at'] >= $values[3] &&
            $row['payment_paid_at'] < $values[4]));
    }
}

require dirname(__DIR__) . '/src/Accounting/AccountingRepository.php';

use SimpleStore\Accounting\AccountingRepository;

$db = new MeekroDB();
$db->rows = [
    ['id' => 1, 'order_number' => 'DB-1', 'variable_symbol' => '1234567890',
        'payment_paid_at' => '2026-09-29 09:00:00', 'subtotal_czk' => 1000,
        'shipping_czk' => 100, 'total_czk' => 1100, 'customer_email' => 'eva@example.test',
        'shipping_json' => json_encode(['recipient' => 'Eva Nová'], JSON_THROW_ON_ERROR),
        'payment_method' => 'bank_transfer', 'payment_status' => 'paid', 'status' => 'shipped'],
    ['id' => 2, 'order_number' => 'DB-2', 'variable_symbol' => '1234567891',
        'payment_paid_at' => '2026-09-30 23:59:59', 'subtotal_czk' => 500,
        'shipping_czk' => 90, 'total_czk' => 590, 'customer_email' => '+formula@example.test',
        'shipping_json' => json_encode(['recipient' => ' =HYPERLINK("https://example.test")'], JSON_THROW_ON_ERROR),
        'payment_method' => 'bank_transfer', 'payment_status' => 'paid', 'status' => 'cancelled'],
    ['id' => 3, 'order_number' => 'DB-3', 'variable_symbol' => '1234567892',
        'payment_paid_at' => '2026-09-29 10:00:00', 'subtotal_czk' => 900,
        'shipping_czk' => 50, 'total_czk' => 950, 'customer_email' => 'pending@example.test',
        'shipping_json' => '{}', 'payment_method' => 'bank_transfer',
        'payment_status' => 'pending', 'status' => 'new'],
    ['id' => 4, 'order_number' => 'TEST', 'variable_symbol' => null,
        'payment_paid_at' => '2026-09-29 10:00:00', 'subtotal_czk' => 200,
        'shipping_czk' => 10, 'total_czk' => 210, 'customer_email' => 'test@example.test',
        'shipping_json' => '{}', 'payment_method' => 'test',
        'payment_status' => 'paid', 'status' => 'test'],
    ['id' => 5, 'order_number' => 'OLD', 'variable_symbol' => '1234567893',
        'payment_paid_at' => '2026-10-01 00:00:00', 'subtotal_czk' => 800,
        'shipping_czk' => 80, 'total_czk' => 880, 'customer_email' => 'old@example.test',
        'shipping_json' => '{}', 'payment_method' => 'bank_transfer',
        'payment_status' => 'paid', 'status' => 'processing'],
];

$repository = new AccountingRepository($db);
if (!$repository->installed()) throw new RuntimeException('Existing checkout schema was not detected.');
$db->financialEvents = [[
    'order_number' => 'DB-1', 'variable_symbol' => '1234567890',
    'action' => 'payment_correction', 'payment_status_before' => 'paid',
    'payment_paid_at' => '2026-09-29 09:00:00', 'payment_verified_by' => 3,
    'total_czk' => 1100, 'reason' => 'Chybně párovaný bankovní výpis.',
    'admin_id' => 4, 'created_at' => '2026-09-30 11:00:00',
]];
if (!$repository->financialEventsInstalled() ||
    count($repository->financialChanges('2026-09-29', '2026-09-30')['items']) !== 1 ||
    $repository->financialChanges('2026-09-29', '2026-09-29')['items'] !== []) {
    throw new RuntimeException('Accounting history does not filter corrections by action date.');
}
$db->missingColumn = 'payment_paid_at';
if ($repository->installed()) throw new RuntimeException('Missing migration was not detected.');
$db->missingColumn = null;

foreach ([['2026-02-30', '2026-03-01'], ['2026-10-01', '2026-09-29'],
    ['2025-01-01', '2026-09-29'], ['', '2026-09-29']] as [$from, $to]) {
    try {
        AccountingRepository::period($from, $to);
        throw new RuntimeException('Invalid accounting dates were accepted.');
    } catch (InvalidArgumentException $expected) {
    }
}
if (AccountingRepository::period('2026-09-29', '2026-09-30') !==
    ['2026-09-29', '2026-09-30']) {
    throw new RuntimeException('Valid accounting dates changed.');
}
$totals = $repository->summary('2026-09-29', '2026-09-30');
if ($totals !== ['count' => 2, 'subtotal_czk' => 1500, 'shipping_czk' => 190,
    'total_czk' => 1690]) {
    throw new RuntimeException('Accounting must count paid orders, including paid cancellations, and shipping.');
}
$firstPage = $repository->page('2026-09-29', '2026-09-30', 0, 1);
$secondPage = $repository->page('2026-09-29', '2026-09-30', 1, 1);
if ($firstPage['items'][0]['id'] !== 2 || $firstPage['nextOffset'] !== 1 ||
    $firstPage['items'][0]['customer_name'] !== '=HYPERLINK("https://example.test")' ||
    $secondPage['items'][0]['id'] !== 1 || $secondPage['nextOffset'] !== null ||
    isset($firstPage['items'][0]['shipping_json'])) {
    throw new RuntimeException('Accounting pagination or customer snapshot is wrong.');
}

$stream = fopen('php://temp', 'w+b');
if ($stream === false || $repository->writeCsv($stream, '2026-09-29', '2026-09-30') !== 2) {
    throw new RuntimeException('CSV export failed.');
}
rewind($stream);
if (fread($stream, 3) !== "\xEF\xBB\xBF") throw new RuntimeException('CSV needs a UTF-8 BOM.');
$header = fgetcsv($stream, 0, ';', '"', '');
$row = fgetcsv($stream, 0, ';', '"', '');
if ($header[0] !== 'Objednávka' || count($header) !== 10 ||
    $row[0] !== 'DB-2' || $row[3] !== "'=HYPERLINK(\"https://example.test\")" ||
    $row[4] !== "'+formula@example.test" || $row[7] !== '590') {
    throw new RuntimeException('CSV fields, totals or formula-injection neutralization failed.');
}
fclose($stream);

$db->forcedCount = 50001;
$stream = fopen('php://temp', 'w+b');
try {
    $repository->writeCsv($stream, '2026-09-29', '2026-09-30');
    throw new RuntimeException('Unbounded export was accepted.');
} catch (InvalidArgumentException $expected) {
}
fclose($stream);

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$adminUrl = '/shop/admin.php';
$accountingBaseUrl = $adminUrl . '?section=accounting';
$accountingReady = true;
$accountingError = '';
$accountingFrom = '2026-09-29';
$accountingTo = '2026-09-30';
$accountingTotals = $totals;
$accountingPage = ['items' => $firstPage['items'], 'nextOffset' => 1];
$accountingPreviousUrl = '';
$accountingNextUrl = $accountingBaseUrl . '&offset=1';
$accountingExportUrl = $accountingBaseUrl . '&download=csv';
$financialEventsReady = true;
$financialChanges = $repository->financialChanges($accountingFrom, $accountingTo);
$financialPreviousUrl = '';
$financialNextUrl = '';
set_error_handler(static function (int $severity, string $message): never {
    throw new RuntimeException('Accounting view emitted a warning: ' . $message);
});
ob_start();
require dirname(__DIR__) . '/view/admin/accounting.php';
$html = ob_get_clean();
restore_error_handler();
if (!str_contains($html, 'Účetní podklady') || !str_contains($html, 'Stáhnout CSV') ||
    !str_contains($html, '1 690 Kč') || !str_contains($html, 'Zrušeno') ||
    !str_contains($html, '=HYPERLINK(&quot;https://example.test&quot;)') ||
    str_contains($html, '=HYPERLINK("https://example.test")')) {
    throw new RuntimeException('Accounting view lost totals, status or HTML escaping.');
}

echo "Accounting tests passed.\n";
