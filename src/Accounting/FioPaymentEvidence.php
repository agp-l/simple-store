<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use MeekroDB;
use RuntimeException;

/** A private copy of one matched API movement, not a bank-issued PDF or invoice. */
final class FioPaymentEvidence
{
    public function __construct(private MeekroDB $db) {}

    public static function bookingDate(array $row): ?string
    {
        $value = $row['column0']['value'] ?? null;
        // Fio's documented JSON examples use Unix milliseconds. Recent exports
        // also use a calendar date with an offset. Preserve the bank's local day.
        if ((is_int($value) || is_string($value)) && preg_match('/^[0-9]{12,13}$/D', (string) $value)) {
            $date = (new DateTimeImmutable('@' . intdiv((int) $value, 1000)))
                ->setTimezone(new DateTimeZone('Europe/Prague'))->format('Y-m-d');
            return $date >= '2000-01-01' && $date < '2100-01-01' ? $date : null;
        }
        if (!is_string($value) || !preg_match('/^(20[0-9]{2}-[0-9]{2}-[0-9]{2})(?:[+-](?:0[0-9]|1[0-4]):?[0-5][0-9])?$/D', $value, $parts)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $parts[1], new DateTimeZone('Europe/Prague'));
        return $date !== false && $date->format('Y-m-d') === $parts[1] ? $parts[1] : null;
    }

    public function forOrder(int $orderId): ?array
    {
        if (!(new EvidenceDocumentRepository($this->db))->installed()) return null;
        return $this->db->queryFirstRow('SELECT * FROM shop_evidence_documents
            WHERE order_id=%i AND kind=%s ORDER BY id LIMIT 1', $orderId, 'bank_movement');
    }

    /** Called inside the same transaction as the unique Fio match and paid status. */
    public function record(array $order, array $movement, string $date, array $info, array $row): void
    {
        if (!(new EvidenceDocumentRepository($this->db))->installed()) return;
        if ($this->db->transactionDepth() < 1) throw new RuntimeException('Fio evidence requires a payment transaction.');
        $number = (new DocumentNumbering($this->db))->allocate('document', (int) substr($date, 0, 4));
        $payload = json_encode([
            'format' => 'simple-store-fio-movement-v1',
            'source' => 'Fio API period export; selected movement only',
            'retrieved_at_utc' => gmdate('Y-m-d H:i:s'),
            'order_number' => $order['order_number'],
            'booking_date' => $date,
            // Do not persist balances or unrelated movements from a personal account.
            'account' => array_intersect_key($info, array_flip(['accountId', 'bankId', 'currency', 'iban'])),
            'transaction' => $row,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $file = ['filename' => 'fio-pohyb-' . $movement['id'] . '.json',
            'mime_type' => 'application/json', 'file_size' => strlen($payload),
            'sha256' => hash('sha256', $payload), 'file_data' => $payload];
        $fields = [
            'kind' => 'bank_movement', 'document_number' => $number['document_number'],
            'external_number' => 'Fio:' . $movement['id'], 'activity' => $number['activity'],
            'issue_date' => $date, 'paid_date' => $date,
            'description' => 'Přijatá platba Fio · ' . $order['order_number'],
            'amount_czk' => $movement['amount'], 'order_id' => (int) $order['id'],
            'notes' => 'Automaticky uložený podklad ke spárované platbě. Příloha obsahuje jeden pohyb z API, nikoli celý výpis ani bankou podepsané potvrzení. Nenahrazuje fakturu. Samotný podklad nezapisuje daňový příjem; peněžní zápis potvrď podle povahy obchodu. SHA-256 slouží ke kontrole uloženého souboru, není podpisem banky.',
            'seller_json' => json_encode((new TaxEvidenceRepository($this->db))->settings(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
        $this->db->insert('shop_evidence_documents', $fields);
        $id = (int) $this->db->insertId();
        $this->db->insert('shop_evidence_files', $file + ['document_id' => $id]);
        $this->db->insert('shop_evidence_events', [
            'entity_kind' => 'document', 'entity_id' => $id, 'old_json' => 'null',
            'new_json' => json_encode(['document' => $fields, 'file_sha256' => $file['sha256']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'reason' => 'Automatický import spárovaného pohybu Fio', 'admin_id' => 0,
        ]);
        $this->db->query('INSERT IGNORE INTO shop_evidence_links (document_id, entry_id)
            SELECT %i, id FROM shop_tax_entries WHERE order_id=%i AND purpose IN %ls',
            $id, $order['id'], ['own_receipt', 'principal_receipt']);
    }

    /** Also covers a receipt entered later, including both assisted-purchase parts. */
    public function linkReceipt(int $entryId, int $orderId): void
    {
        if (!(new EvidenceDocumentRepository($this->db))->installed()) return;
        $this->db->query('INSERT IGNORE INTO shop_evidence_links (document_id, entry_id)
            SELECT id, %i FROM shop_evidence_documents WHERE order_id=%i AND kind=%s AND voided_at IS NULL',
            $entryId, $orderId, 'bank_movement');
    }
}
