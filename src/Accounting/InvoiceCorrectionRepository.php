<?php
declare(strict_types=1);
namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** A non-VAT credit document reduces only the trader's original invoice, never entrusted funds. */
final class InvoiceCorrectionRepository
{
    public function __construct(private MeekroDB $db) {}

    public function issue(int $invoiceId, array $input, int $adminId): int
    {
        $amount = TaxMoney::cents($input['amount_czk'] ?? '');
        $date = TaxRegisterRepository::date($input['issue_date'] ?? '');
        $reason = TaxRegisterRepository::text($input['reason'] ?? '', 255);
        if ($adminId < 1 || $reason === '') throw new InvalidArgumentException('Doplň důvod a rozsah snížení ceny.');
        $this->db->startTransaction();
        try {
            TaxWriteGuard::claim($this->db, $input);
            $invoice = $this->db->queryFirstRow('SELECT * FROM shop_invoices WHERE id=%i FOR UPDATE', $invoiceId);
            if ($invoice === null) throw new InvalidArgumentException('Původní faktura neexistuje.');
            if ($date < $invoice['issue_date']) throw new InvalidArgumentException('Opravu nelze vystavit před původní fakturou.');
            $used = $this->db->queryFirstField('SELECT COALESCE(SUM(amount_czk),0) FROM shop_invoice_corrections
                WHERE invoice_id=%i AND voided_at IS NULL', $invoiceId);
            if ($amount + TaxMoney::cents((string) $used, true) > (int) $invoice['total_czk'] * 100) {
                throw new InvalidArgumentException('Součet oprav by překročil vlastní plnění na původní faktuře. Svěřené peníze vrať samostatně ve vyúčtování.');
            }
            // Invoice lock serializes the per-invoice sequence, including voided documents.
            $sequence = 1 + (int) $this->db->queryFirstField('SELECT COUNT(*) FROM shop_invoice_corrections WHERE invoice_id=%i', $invoiceId);
            $number='O-' . $invoiceId . '-' . $sequence;
            $numbering=new DocumentNumbering($this->db);
            if($numbering->installed())$number=$numbering->allocate('document',(int)substr($date,0,4))['document_number'];
            $this->db->insert('shop_invoice_corrections', ['invoice_id' => $invoiceId,
                'document_number' => $number, 'issue_date' => $date,
                'amount_czk' => TaxMoney::decimal($amount), 'reason' => $reason,
                'invoice_json' => json_encode($invoice, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'admin_id' => $adminId]);
            $id = (int) $this->db->insertId();
            $this->db->commit();
            return $id;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function byId(int $id): ?array
    {
        return $this->db->queryFirstRow('SELECT * FROM shop_invoice_corrections WHERE id=%i', $id);
    }

    public function forInvoice(int $id): array
    {
        return $this->db->query('SELECT * FROM shop_invoice_corrections WHERE invoice_id=%i ORDER BY id', $id);
    }

    public function void(int $id, string $reason, int $adminId): void
    {
        $reason = TaxRegisterRepository::text($reason, 190);
        if ($reason === '' || $adminId < 1) throw new InvalidArgumentException('Uveď důvod vyřazení opravy.');
        // Same invoice lock as issue() prevents races with the remaining credit allowance.
        $this->db->startTransaction();
        try {
            $row = $this->byId($id);
            if ($row === null) throw new InvalidArgumentException('Opravný doklad neexistuje.');
            $this->db->queryFirstRow('SELECT id FROM shop_invoices WHERE id=%i FOR UPDATE', $row['invoice_id']);
            $this->db->query('UPDATE shop_invoice_corrections SET voided_at=UTC_TIMESTAMP(),void_reason=%s,void_admin_id=%i
                WHERE id=%i AND voided_at IS NULL', $reason, $adminId, $id);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
