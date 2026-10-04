<?php
declare(strict_types=1);

// Isolated CI database; exercise annual settings against the real schema/upgrader.
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Accounting\TaxYearRegimeRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;

$db = ConnectionFactory::create([
    'host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'),
    'database' => 'simple_store', 'port' => 3306,
]);
$schema = new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql');
$schema->apply();
if ($schema->apply()) throw new RuntimeException('The schema upgrade was not repeatable.');

$regimes = new TaxYearRegimeRepository($db);
if (!$regimes->installed() || (int) $db->queryFirstField(
    'SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_flat_tax_advances') !== 1) {
    throw new RuntimeException('Annual tax settings or flat-tax advances table is missing.');
}

$osvc = ['legal_form' => 'sole_trader', 'expense_method' => 'percentage', 'expense_percentage' => 40];
$company = ['legal_form' => 'company', 'expense_method' => 'actual', 'expense_percentage' => 40];
$years = [2094, 2095];
$db->query('DELETE FROM shop_tax_year_regimes WHERE tax_year IN (%i,%i)', ...$years);
try {
    $old = $regimes->forYear(2094, $osvc);
    if ($old !== ['method' => 'percentage', 'expense_percentage' => 40,
        'flat_tax_band' => 1, 'flat_tax_confirmed' => false,
        'legal_form_mismatch' => false, 'saved' => false]) {
        throw new RuntimeException('A year without an explicit regime lost the legacy expense preference.');
    }
    if ($regimes->forYear(2094, $company)['method'] !== 'actual') {
        throw new RuntimeException('Company received a sole-trader legacy expense method.');
    }

    $regimes->save(2094, ['method' => 'flat_tax', 'expense_percentage' => '40',
        'flat_tax_band' => '2'], $osvc);
    $flat = $regimes->forYear(2094, $osvc);
    if ($flat['method'] !== 'flat_tax' || $flat['flat_tax_band'] !== 2 ||
        $flat['flat_tax_confirmed'] || !$flat['saved']) {
        throw new RuntimeException('Selecting a band must not imply an approved flat-tax status.');
    }
    $regimes->save(2094, ['method' => 'flat_tax', 'expense_percentage' => '40',
        'flat_tax_band' => '2', 'flat_tax_confirmed' => '1'], $osvc);
    if (!$regimes->forYear(2094, $osvc)['flat_tax_confirmed']) {
        throw new RuntimeException('An explicit administrator confirmation was not saved.');
    }
    $companyView = $regimes->forYear(2094, $company);
    if ($companyView['method'] !== 'actual' || !$companyView['legal_form_mismatch'] ||
        $companyView['flat_tax_confirmed']) {
        throw new RuntimeException('A persisted OSVČ regime was shown as valid for a company.');
    }

    $regimes->save(2095, ['method' => 'actual', 'expense_percentage' => '60',
        'flat_tax_band' => '1'], $company);
    if ($regimes->forYear(2095, $osvc)['method'] !== 'actual' ||
        $regimes->forYear(2094, $osvc)['method'] !== 'flat_tax') {
        throw new RuntimeException('Changing one calendar year affected another year.');
    }
    $invalid = [
        [2093, ['method' => 'flat_tax', 'flat_tax_band' => '4'], $osvc],
        [2094, ['method' => 'percentage', 'expense_percentage' => '70'], $osvc],
        [2094, ['method' => 'flat_tax', 'flat_tax_band' => 2], $osvc],
        [2094, ['method' => ['flat_tax']], $osvc],
        [2094, ['method' => 'actual', 'flat_tax_confirmed' => ['1']], $osvc],
        [2094, ['method' => 'percentage'], $company],
        [2094, ['method' => 'flat_tax'], $company],
        [1999, ['method' => 'actual'], $osvc],
        [2101, ['method' => 'actual'], $osvc],
    ];
    foreach ($invalid as [$year, $input, $seller]) {
        try {
            $regimes->save($year, $input, $seller);
            throw new RuntimeException('An invalid annual regime was accepted.');
        } catch (InvalidArgumentException) {
            if ($regimes->forYear(2094, $osvc)['method'] !== 'flat_tax') {
                throw new RuntimeException('An invalid regime changed persisted data.');
            }
        }
    }
    $regimes->save(2094, ['method' => 'actual', 'expense_percentage' => '40',
        'flat_tax_band' => '2', 'flat_tax_confirmed' => '1'], $osvc);
    $changed = $regimes->forYear(2094, $osvc);
    if ($changed['method'] !== 'actual' || $changed['flat_tax_confirmed'] ||
        (int) $db->queryFirstField('SELECT COUNT(*) FROM shop_tax_year_regimes WHERE tax_year=%i', 2094) !== 1) {
        throw new RuntimeException('The annual upsert did not replace a prior explicit regime.');
    }
} finally {
    $db->query('DELETE FROM shop_tax_year_regimes WHERE tax_year IN (%i,%i)', ...$years);
}

echo "Annual OSVC regime database integration passed.\n";
