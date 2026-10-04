<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Accounting\FlatTaxAdvanceRepository;
use SimpleStore\Accounting\FlatTaxRateSchedule;
use SimpleStore\Accounting\TaxEvidenceRepository;
use SimpleStore\Accounting\TaxYearRegimeRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

$db = ConnectionFactory::create([
    'host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$modes = new TaxYearRegimeRepository($db);
$advances = new FlatTaxAdvanceRepository($db);
$ledger = new TaxEvidenceRepository($db);
if (!$modes->installed() || !$advances->installed() ||
    FlatTaxRateSchedule::monthly(2026, 1) !== 9162 ||
    FlatTaxRateSchedule::monthly(2026, 2) !== 16745 ||
    FlatTaxRateSchedule::monthly(2027, 1) !== null) {
    throw new RuntimeException('Flat-tax annual tables or year-specific rates are unavailable.');
}
$seller = ['legal_form' => 'sole_trader', 'expense_method' => 'actual', 'expense_percentage' => 60];
$year = 2077;
$modes->save($year, ['method' => 'flat_tax', 'expense_percentage' => '60',
    'flat_tax_band' => '2', 'flat_tax_confirmed' => '1'], $seller);
$mode = $modes->forYear($year, $seller);
$input = ['tax_month' => '2', 'entry_date' => '2077-02-20', 'amount_czk' => '16745',
    'account' => 'bank', 'reference' => 'FÚ-2077-02', 'note' => 'z banky'];
$advances->record($year, $input, $mode);
$rows = $advances->forYear($year);
if (count($rows) !== 1 || (int) $rows[0]['tax_month'] !== 2 ||
    (int) $rows[0]['amount_czk'] !== 16745 ||
    $rows[0]['entry_date'] !== '2077-02-20' ||
    $rows[0]['tax_kind'] !== 'nondeductible' ||
    $rows[0]['direction'] !== 'expense' ||
    $rows[0]['active_entry_id'] === null ||
    $ledger->summary($year)['expenses'] !== 0) {
    throw new RuntimeException('Monthly advance must be an actual nontaxable money movement.');
}
foreach ([
    [$year, array_replace($input, ['tax_month' => '13']), $mode],
    [$year, array_replace($input, ['entry_date' => '2077-02-30']), $mode],
    [$year, $input, array_replace($mode, ['flat_tax_confirmed' => false])],
] as [$yearArg, $inputArg, $modeArg]) {
    try {
        $advances->record($yearArg, $inputArg, $modeArg);
        throw new RuntimeException('Invalid flat-tax payment was accepted.');
    } catch (InvalidArgumentException) {
    }
}
if (count($advances->forYear($year)) !== 1) {
    throw new RuntimeException('Invalid payment changed the annual register.');
}
$entryId = (int) $rows[0]['entry_id'];
$ledger->voidEntry($entryId, 1, 'Chybně zanesená záloha');
if ($advances->forYear($year)[0]['active_entry_id'] !== null) {
    throw new RuntimeException('Voided payment still looks like a journal entry.');
}
echo "Flat-tax advance integration passed.\n";
