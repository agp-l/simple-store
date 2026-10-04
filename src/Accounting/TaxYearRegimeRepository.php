<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;

/** A dated administrator preference; it does not establish eligibility for a tax regime. */
final class TaxYearRegimeRepository
{
    private const EXPENSE_PERCENTAGES = [30, 40, 60, 80];

    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_tax_year_regimes'
        ) === 1;
    }

    /**
     * @return array{method:string, expense_percentage:int, flat_tax_band:int,
     *     flat_tax_confirmed:bool, legal_form_mismatch:bool, saved:bool}
     */
    public function forYear(int $year, array $sellerSettings): array
    {
        self::validateYear($year);
        $percentage = $sellerSettings['expense_percentage'] ?? 60;
        if (!in_array($percentage, self::EXPENSE_PERCENTAGES, true)) $percentage = 60;
        $method = ($sellerSettings['expense_method'] ?? '') === 'percentage' ? 'percentage' : 'actual';
        $saved = false;
        $band = 1;
        $confirmed = false;

        if ($this->installed()) {
            $row = $this->db->queryFirstRow('SELECT method, expense_percentage, flat_tax_band,
                flat_tax_confirmed FROM shop_tax_year_regimes WHERE tax_year=%i LIMIT 1', $year);
            if ($row !== null) {
                $saved = true;
                $method = (string) $row['method'];
                $percentage = (int) $row['expense_percentage'];
                $band = (int) $row['flat_tax_band'];
                $confirmed = (int) $row['flat_tax_confirmed'] === 1;
                if (!in_array($method, ['actual', 'percentage', 'flat_tax'], true) ||
                    !in_array($percentage, self::EXPENSE_PERCENTAGES, true) ||
                    $band < 1 || $band > 3 || !in_array((int) $row['flat_tax_confirmed'], [0, 1], true)) {
                    throw new RuntimeException('Uložený roční režim obsahuje neplatné údaje.');
                }
            }
        }

        $company = ($sellerSettings['legal_form'] ?? 'sole_trader') === 'company';
        $mismatch = $company && $saved && $method !== 'actual';
        if ($company) {
            $method = 'actual';
            $confirmed = false;
        }
        return [
            'method' => $method,
            'expense_percentage' => $percentage,
            'flat_tax_band' => $band,
            'flat_tax_confirmed' => $confirmed,
            'legal_form_mismatch' => $mismatch,
            'saved' => $saved,
        ];
    }

    /** @param array<string, mixed> $input Form values, never an authority's tax confirmation. */
    public function save(int $year, array $input, array $sellerSettings): void
    {
        self::validateYear($year);
        if (!$this->installed()) {
            throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        }
        $legalForm = $sellerSettings['legal_form'] ?? 'sole_trader';
        if (!in_array($legalForm, ['sole_trader', 'company'], true)) {
            throw new InvalidArgumentException('Nejdřív ověř právní formu prodávajícího.');
        }
        $method = $input['method'] ?? null;
        if (!is_string($method) || !in_array($method, ['actual', 'percentage', 'flat_tax'], true)) {
            throw new InvalidArgumentException('Vyber způsob evidence pro daný rok.');
        }
        if ($legalForm === 'company' && $method !== 'actual') {
            throw new InvalidArgumentException('Výdaje OSVČ ani paušální daň nelze nastavit pro společnost.');
        }
        $percentage = self::number($input['expense_percentage'] ?? '60',
            self::EXPENSE_PERCENTAGES, 'Vyber platné procento výdajů.');
        $band = self::number($input['flat_tax_band'] ?? '1', [1, 2, 3],
            'Vyber platné pásmo paušální daně.');
        $rawConfirmation = $input['flat_tax_confirmed'] ?? '0';
        if (!is_string($rawConfirmation) || !in_array($rawConfirmation, ['0', '1'], true)) {
            throw new InvalidArgumentException('Zkontroluj potvrzení paušálního režimu.');
        }
        $confirmed = $method === 'flat_tax' && $rawConfirmation === '1' ? 1 : 0;
        $this->db->query('INSERT INTO shop_tax_year_regimes
                (tax_year, method, expense_percentage, flat_tax_band, flat_tax_confirmed)
                VALUES (%i, %s, %i, %i, %i)
                ON DUPLICATE KEY UPDATE method=VALUES(method),
                    expense_percentage=VALUES(expense_percentage),
                    flat_tax_band=VALUES(flat_tax_band),
                    flat_tax_confirmed=VALUES(flat_tax_confirmed),
                    updated_at=CURRENT_TIMESTAMP',
            $year, $method, $percentage, $band, $confirmed);
    }

    private static function validateYear(int $year): void
    {
        if ($year < 2000 || $year > 2100) {
            throw new InvalidArgumentException('Neplatný rok evidence.');
        }
    }

    /** @param list<int> $allowed */
    private static function number(mixed $value, array $allowed, string $error): int
    {
        if (!is_string($value) || $value === '' || !ctype_digit($value)) {
            throw new InvalidArgumentException($error);
        }
        $number = (int) $value;
        if (!in_array($number, $allowed, true) || (string) $number !== $value) {
            throw new InvalidArgumentException($error);
        }
        return $number;
    }
}
