<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

/** Labels and next actions for a read-only evidence row. No tax calculation happens here. */
final class EvidenceBookRowPresenter
{
    public function __construct(private array $row, private string $adminUrl, private int $year)
    {
    }

    public function source(): string
    {
        return match ($this->row['kind']) {
            'order' => 'Objednávka',
            'invoice' => 'Vydaná faktura',
            default => 'Peněžní zápis',
        };
    }

    public function payment(): string
    {
        if ($this->row['kind'] !== 'order') return '—';
        return match ($this->row['payment_status']) {
            'paid' => 'Potvrzena v objednávce',
            'pending' => 'Čeká na platbu',
            default => 'Ověřit stav objednávky',
        };
    }

    /** A linked entry is a recorded amount, not an automatic bank reconciliation. */
    public function completionLabel(): string
    {
        return 'Faktura a příjem jsou zapsány. Porovnej s bankovním výpisem.';
    }

    /** @return array{label: string, href: string}|null */
    public function nextStep(): ?array
    {
        $evidenceUrl = $this->adminUrl . '?section=accounting';
        if ($this->row['kind'] === 'entry') {
            return ['label' => $this->row['entry_reference'] === '' ? 'Doplnit podklad' : 'Zobrazit / opravit',
                'href' => $evidenceUrl . '&tab=money&year=' . $this->year .
                    '&edit_entry=' . (int) $this->row['entry_id']];
        }
        if ($this->row['kind'] === 'invoice') {
            return ['label' => 'Otevřít doklad', 'href' => $evidenceUrl .
                '&tab=invoices&year=' . $this->year . '&invoice_id=' . (int) $this->row['invoice_id']];
        }
        $orderUrl = $this->adminUrl . '?section=orders&id=' . (int) $this->row['order_id'];
        if ($this->row['order_status'] === 'cancelled') {
            return ['label' => 'Prověřit storno a vratku', 'href' => $orderUrl];
        }
        if ($this->row['payment_status'] !== 'paid') {
            return ['label' => $this->row['receipt_id'] !== null ?
                'Prověřit opravu platby' : 'Zkontrolovat platbu', 'href' => $orderUrl];
        }
        if ($this->row['invoice_id'] === null) {
            return ['label' => 'Vystavit doklad', 'href' => $orderUrl];
        }
        if ($this->row['payment_method'] !== 'bank_transfer') {
            return ['label' => 'Prověřit vyúčtování / vratky',
                'href' => $evidenceUrl . '&tab=money&year=' . $this->year];
        }
        if ($this->row['receipt_id'] === null) {
            return ['label' => 'Zapsat příjem z výpisu', 'href' => $orderUrl];
        }
        if ((int) $this->row['receipt_amount_czk'] !== (int) $this->row['total_czk']) {
            return ['label' => 'Prověřit rozdílnou částku', 'href' => $orderUrl];
        }
        return null;
    }
}
