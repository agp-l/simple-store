<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use DateTimeImmutable;
use InvalidArgumentException;
use MeekroDB;
use RuntimeException;

/** Records actual lump-sum-regime remittances, never inferred tax liability. */
final class FlatTaxAdvanceRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_flat_tax_advances'
        ) === 1;
    }

    /** @return list<array<string, mixed>> */
    public function forYear(int $year): array
    {
        TaxEvidenceRepository::year($year);
        if (!$this->installed()) return [];
        return $this->db->query('SELECT a.id, a.tax_month, a.entry_id, e.id AS active_entry_id,
                e.entry_date, e.direction, e.tax_kind, e.amount_czk, e.reference, e.description
            FROM shop_flat_tax_advances a
            LEFT JOIN shop_tax_entries e ON e.id=a.entry_id
            WHERE a.tax_year=%i ORDER BY a.tax_month, a.id', $year);
    }

    /**
     * The date, amount and reference also enter the money journal in one transaction.
     * A remittance is not an expense deductible again from lump-sum tax.
     */
    public function record(int $year, array $input, array $yearMode): void
    {
        TaxEvidenceRepository::year($year);
        if (!$this->installed()) throw new RuntimeException('Nejprve aktualizuj SQL tabulky.');
        if (($yearMode['method'] ?? '') !== 'flat_tax' ||
            ($yearMode['flat_tax_confirmed'] ?? false) !== true) {
            throw new InvalidArgumentException('Nejdřív potvrď skutečný vstup do paušálního režimu pro tento rok.');
        }
        $month = filter_var($input['tax_month'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 12]]);
        $amount = filter_var($input['amount_czk'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 99999999]]);
        $date = $input['entry_date'] ?? null;
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($month === false || $amount === false || $parsed === false ||
            $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Zadej měsíc, datum úhrady a skutečně zaplacenou částku.');
        }
        $account = $input['account'] ?? '';
        if (!in_array($account, ['bank', 'cash'], true)) {
            throw new InvalidArgumentException('Vyber bankovní účet nebo hotovost.');
        }
        $reference = self::cleanText($input['reference'] ?? '', 100);
        $note = self::cleanText($input['note'] ?? '', 100);
        $description = 'Paušální záloha za ' . $year . '-' . sprintf('%02d', $month);
        if ($note !== '') $description .= ' · ' . $note;

        $this->db->startTransaction();
        try {
            $this->db->insert('shop_tax_entries', [
                'entry_date' => $date, 'direction' => 'expense', 'account' => $account,
                'tax_kind' => 'nondeductible', 'amount_czk' => $amount,
                'description' => $description, 'counterparty' => 'Finanční úřad',
                'reference' => $reference, 'order_id' => null,
            ]);
            $entryId = (int) $this->db->insertId();
            $this->db->insert('shop_flat_tax_advances', [
                'tax_year' => $year, 'tax_month' => $month, 'entry_id' => $entryId,
            ]);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private static function cleanText(mixed $value, int $max): string
    {
        if (!is_string($value) || strlen($value) > $max ||
            preg_match('//u', $value) !== 1 || preg_match('/\p{C}/u', $value) !== 0) {
            throw new InvalidArgumentException('Zkontroluj referenci a poznámku k platbě.');
        }
        return trim($value);
    }
}
