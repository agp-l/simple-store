<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Issued non-VAT invoices store their original seller, buyer and item snapshots. */
final class InvoiceRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        foreach (['shop_invoices', 'shop_invoice_sequence', 'shop_invoice_number_events'] as $table) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table
            ) === 0) return false;
        }
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
            'shop_invoices', 'payment_method'
        ) > 0;
    }

    public function issue(int $orderId, array $seller, array $buyerInput): array
    {
        if ($orderId < 1 || !TaxEvidenceRepository::invoiceReady($seller) || !$this->installed()) {
            throw new InvalidArgumentException('Nejdřív aktualizuj databázi a vyplň údaje OSVČ v účetnictví.');
        }
        $buyer = self::buyer($buyerInput);
        $this->db->startTransaction();
        try {
            $order = $this->db->queryFirstRow('SELECT * FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId);
            if ($order === null || !in_array($order['payment_method'], ['bank_transfer', 'comgate'], true) ||
                $order['payment_status'] !== 'paid' || $order['status'] === 'test') {
                throw new InvalidArgumentException('Fakturu lze vystavit jen k uhrazené skutečné objednávce.');
            }
            if ($this->db->queryFirstRow('SELECT id FROM shop_invoices WHERE order_id=%i LIMIT 1', $orderId) !== null) {
                throw new InvalidArgumentException('Objednávka již fakturu má.');
            }
            $items = json_decode((string) $order['items_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($items) || $items === [] || !is_string($order['customer_email'] ?? null)) {
                throw new InvalidArgumentException('Položky nebo kontakt objednávky nejsou úplné.');
            }
            $payment = json_decode((string) ($order['payment_details_json'] ?? '{}'), true);
            if ($order['payment_method'] === 'bank_transfer') {
                $seller['bank_account'] = is_array($payment) && is_string($payment['account_display'] ?? null)
                    ? $payment['account_display'] : (string) ($seller['bank_account'] ?? '');
            }
            $today = new DateTimeImmutable('now', new DateTimeZone('Europe/Prague'));
            $year = (int) $today->format('Y');
            $this->db->query('INSERT IGNORE INTO shop_invoice_sequence (calendar_year, next_number) VALUES (%i, %i)',
                $year, 1);
            $sequence = $this->db->queryFirstRow(
                'SELECT next_number FROM shop_invoice_sequence WHERE calendar_year=%i FOR UPDATE', $year);
            $next = (int) ($sequence['next_number'] ?? 0);
            if ($next < 1 || $next > 999999) throw new InvalidArgumentException('Číselná řada faktur je vyčerpaná.');
            do {
                $number = sprintf('F%d-%06d', $year, $next++);
                $taken = $this->db->queryFirstField(
                    'SELECT COUNT(*) FROM shop_invoices WHERE document_number=%s', $number);
            } while ((int) $taken > 0 && $next <= 999999);
            if ((int) $taken > 0) throw new InvalidArgumentException('Číselná řada faktur je vyčerpaná.');
            $this->db->query('UPDATE shop_invoice_sequence SET next_number=%i WHERE calendar_year=%i', $next, $year);
            $buyer['email'] = (string) $order['customer_email'];
            $this->db->insert('shop_invoices', [
                'order_id' => $orderId, 'order_number' => $order['order_number'],
                'document_number' => $number, 'issue_date' => $today->format('Y-m-d'),
                'due_date' => $today->format('Y-m-d'),
                'seller_json' => json_encode($seller, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'buyer_json' => json_encode($buyer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'items_json' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'subtotal_czk' => (int) $order['subtotal_czk'],
                'shipping_czk' => (int) $order['shipping_czk'],
                'total_czk' => (int) $order['total_czk'],
                'variable_symbol' => $order['variable_symbol'],
                'payment_method' => $order['payment_method'],
                'bank_account' => (string) ($seller['bank_account'] ?? ''),
            ]);
            $invoice = $this->byOrder($orderId);
            if ($invoice === null) throw new InvalidArgumentException('Fakturu se nepodařilo načíst.');
            $this->db->commit();
            return $invoice;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function renumber(int $id, string $newNumber, int $adminId, string $reason): void
    {
        $newNumber = trim($newNumber);
        $reason = trim($reason);
        if ($id < 1 || $adminId < 1 ||
            preg_match('/^[A-Z0-9][A-Z0-9\/-]{2,39}$/D', $newNumber) !== 1 ||
            preg_match('/^.{8,190}$/usD', $reason) !== 1 || preg_match('/\p{C}/u', $reason) !== 0) {
            throw new InvalidArgumentException('Zadej jedinečné číslo dokladu a důvod opravy (8–190 znaků).');
        }
        $this->db->startTransaction();
        try {
            $row = $this->db->queryFirstRow('SELECT document_number FROM shop_invoices WHERE id=%i FOR UPDATE', $id);
            if ($row === null || $row['document_number'] === $newNumber ||
                (int) $this->db->queryFirstField(
                    'SELECT COUNT(*) FROM shop_invoices WHERE document_number=%s', $newNumber) > 0 ||
                (int) $this->db->queryFirstField(
                    'SELECT COUNT(*) FROM shop_invoice_number_events
                     WHERE old_number=%s OR new_number=%s', $newNumber, $newNumber) > 0) {
                throw new InvalidArgumentException('Doklad neexistuje nebo je nové číslo již obsazené.');
            }
            $this->db->query('UPDATE shop_invoices SET document_number=%s WHERE id=%i', $newNumber, $id);
            $this->db->insert('shop_invoice_number_events', [
                'invoice_id' => $id, 'old_number' => $row['document_number'],
                'new_number' => $newNumber, 'reason' => $reason, 'admin_id' => $adminId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if (preg_match('/^F(20[0-9]{2})-([0-9]{6})$/D', $newNumber, $match) === 1) {
                $this->db->query('INSERT INTO shop_invoice_sequence (calendar_year, next_number) VALUES (%i, %i)
                    ON DUPLICATE KEY UPDATE next_number=GREATEST(next_number, VALUES(next_number))',
                    (int) $match[1], (int) $match[2] + 1);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function byOrder(int $orderId): ?array
    {
        if ($orderId < 1 || !$this->installed()) return null;
        $row = $this->db->queryFirstRow('SELECT * FROM shop_invoices WHERE order_id=%i LIMIT 1', $orderId);
        return $row === null ? null : self::hydrate($row);
    }

    public function byId(int $id): ?array
    {
        if ($id < 1 || !$this->installed()) return null;
        $row = $this->db->queryFirstRow('SELECT * FROM shop_invoices WHERE id=%i LIMIT 1', $id);
        return $row === null ? null : self::hydrate($row);
    }

    public function list(int $year): array
    {
        TaxEvidenceRepository::year($year);
        return $this->db->query('SELECT id, order_id, order_number, document_number,
                issue_date, total_czk, emailed_at FROM shop_invoices
            WHERE issue_date >= %s AND issue_date < %s ORDER BY id DESC LIMIT %i',
            $year . '-01-01', ($year + 1) . '-01-01', 500);
    }

    public function numberHistory(int $id): array
    {
        return $this->db->query('SELECT old_number, new_number, reason, admin_id, created_at
            FROM shop_invoice_number_events WHERE invoice_id=%i ORDER BY id DESC', $id);
    }

    private static function hydrate(array $row): array
    {
        foreach (['seller', 'buyer', 'items'] as $key) {
            $row[$key] = json_decode((string) $row[$key . '_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($row[$key . '_json']);
        }
        return $row;
    }

    private static function buyer(array $input): array
    {
        $result = [];
        foreach (['name' => 120, 'street' => 160, 'city' => 100,
            'postal_code' => 6, 'ico' => 8] as $key => $limit) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || strlen(trim($value)) > $limit ||
                preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new InvalidArgumentException('Zkontroluj údaje odběratele.');
            }
            $result[$key] = trim($value);
        }
        if ($result['name'] === '' ||
            ($result['ico'] !== '' && preg_match('/^[0-9]{8}$/D', $result['ico']) !== 1)) {
            throw new InvalidArgumentException('Vyplň jméno odběratele a případné IČO.');
        }
        return $result;
    }
}
