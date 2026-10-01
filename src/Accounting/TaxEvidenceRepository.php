<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use DateTimeImmutable;
use InvalidArgumentException;
use MeekroDB;
use RuntimeException;

/** Cash-basis tax records for a Czech sole trader who is not a VAT payer. */
final class TaxEvidenceRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        foreach (['shop_tax_settings', 'shop_tax_entries', 'shop_tax_entry_events', 'shop_tax_balances',
            'shop_stock_movements', 'shop_sale_lines', 'shop_deleted_sale_lines'] as $table) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table
            ) === 0) return false;
        }
        return true;
    }

    public function settings(): array
    {
        $defaults = ['name' => '', 'ico' => '', 'street' => '', 'city' => '',
            'postal_code' => '', 'email' => '', 'phone' => '', 'bank_account' => '',
            'mail_from' => ''];
        if (!$this->installed()) return $defaults;
        $row = $this->db->queryFirstRow('SELECT settings_json FROM shop_tax_settings WHERE id=%i', 1);
        if ($row === null) return $defaults;
        $saved = json_decode((string) $row['settings_json'], true, 512, JSON_THROW_ON_ERROR);
        return is_array($saved) ? array_replace($defaults, array_intersect_key($saved, $defaults)) : $defaults;
    }

    public function saveSettings(array $input): void
    {
        $fields = [];
        foreach (['name' => 120, 'ico' => 8, 'street' => 160, 'city' => 100,
            'postal_code' => 6, 'email' => 254, 'phone' => 40, 'bank_account' => 40,
            'mail_from' => 254] as $key => $limit) {
            $fields[$key] = self::text($input[$key] ?? '', $limit);
        }
        if (($fields['ico'] !== '' && preg_match('/^[0-9]{8}$/D', $fields['ico']) !== 1) ||
            ($fields['postal_code'] !== '' && preg_match('/^[0-9]{3} ?[0-9]{2}$/D', $fields['postal_code']) !== 1) ||
            ($fields['email'] !== '' && filter_var($fields['email'], FILTER_VALIDATE_EMAIL) === false) ||
            ($fields['mail_from'] !== '' && filter_var($fields['mail_from'], FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException('Zkontroluj IČO, PSČ a e-mailové adresy.');
        }
        $this->db->query('INSERT INTO shop_tax_settings (id, settings_json) VALUES (%i, %s)
            ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json), updated_at=CURRENT_TIMESTAMP',
            1, json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function invoiceReady(array $settings): bool
    {
        return ($settings['name'] ?? '') !== '' &&
            preg_match('/^[0-9]{8}$/D', (string) ($settings['ico'] ?? '')) === 1 &&
            ($settings['street'] ?? '') !== '' && ($settings['city'] ?? '') !== '' &&
            ($settings['postal_code'] ?? '') !== '';
    }

    public function addEntry(array $input): void
    {
        $fields = self::entryFields($input);
        $this->db->insert('shop_tax_entries', $fields + ['order_id' => null]);
    }

    private static function entryFields(array $input): array
    {
        $date = self::date($input['entry_date'] ?? null);
        $direction = $input['direction'] ?? '';
        $account = $input['account'] ?? '';
        $kind = $input['tax_kind'] ?? '';
        $amount = filter_var($input['amount_czk'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 99999999]]);
        if (!in_array($direction, ['income', 'expense'], true) ||
            !in_array($account, ['bank', 'cash'], true) ||
            !in_array($kind, $direction === 'income' ? ['taxable', 'nontaxable'] :
                ['deductible', 'nondeductible'], true) || $amount === false) {
            throw new InvalidArgumentException('Vyplň směr, banku/pokladnu, daňové zařazení a částku.');
        }
        $description = self::text($input['description'] ?? '', 255);
        if ($description === '') throw new InvalidArgumentException('Popiš peněžní pohyb.');
        return [
            'entry_date' => $date, 'direction' => $direction, 'account' => $account,
            'tax_kind' => $kind, 'amount_czk' => $amount, 'description' => $description,
            'counterparty' => self::text($input['counterparty'] ?? '', 190),
            'reference' => self::text($input['reference'] ?? '', 100),
        ];
    }

    public function entry(int $id): ?array
    {
        if ($id < 1) return null;
        return $this->db->queryFirstRow('SELECT * FROM shop_tax_entries WHERE id=%i LIMIT 1', $id);
    }

    public function amendEntry(int $id, array $input, int $adminId, string $reason): void
    {
        $fields = self::entryFields($input);
        self::assertReason($id, $adminId, $reason);
        $this->db->startTransaction();
        try {
            $old = $this->db->queryFirstRow('SELECT * FROM shop_tax_entries WHERE id=%i FOR UPDATE', $id);
            if ($old === null) throw new InvalidArgumentException('Peněžní zápis neexistuje.');
            $this->db->query('UPDATE shop_tax_entries SET entry_date=%s, direction=%s, account=%s,
                    tax_kind=%s, amount_czk=%i, description=%s, counterparty=%s, reference=%s WHERE id=%i',
                $fields['entry_date'], $fields['direction'], $fields['account'], $fields['tax_kind'],
                $fields['amount_czk'], $fields['description'], $fields['counterparty'], $fields['reference'], $id);
            $this->recordEntryEvent($old, $fields, 'amended', $adminId, $reason);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function voidEntry(int $id, int $adminId, string $reason): void
    {
        self::assertReason($id, $adminId, $reason);
        $this->db->startTransaction();
        try {
            $old = $this->db->queryFirstRow('SELECT * FROM shop_tax_entries WHERE id=%i FOR UPDATE', $id);
            if ($old === null) throw new InvalidArgumentException('Peněžní zápis neexistuje.');
            $this->recordEntryEvent($old, null, 'voided', $adminId, $reason);
            $this->db->query('DELETE FROM shop_tax_entries WHERE id=%i', $id);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function entryHistory(int $year): array
    {
        self::year($year);
        return $this->db->query('SELECT entry_id, action, old_json, new_json, reason, admin_id, created_at
            FROM shop_tax_entry_events WHERE created_at >= %s AND created_at < %s
            ORDER BY id DESC LIMIT %i', $year . '-01-01', ($year + 1) . '-01-01', 100);
    }

    private function recordEntryEvent(array $old, ?array $new, string $action,
        int $adminId, string $reason): void
    {
        $this->db->insert('shop_tax_entry_events', [
            'entry_id' => (int) $old['id'], 'action' => $action,
            'old_json' => json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'new_json' => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'reason' => trim($reason), 'admin_id' => $adminId, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private static function assertReason(int $id, int $adminId, string $reason): void
    {
        $reason = trim($reason);
        if ($id < 1 || $adminId < 1 || preg_match('/^.{8,190}$/usD', $reason) !== 1 ||
            preg_match('/\p{C}/u', $reason) !== 0) {
            throw new InvalidArgumentException('Vyplň důvod opravy (8–190 znaků).');
        }
    }

    /** Link a verified bank receipt to its order; never infer bank activity from checkout. */
    public function addOrderReceipt(int $orderId, array $input): void
    {
        $date = self::date($input['entry_date'] ?? null);
        $reference = self::text($input['reference'] ?? '', 100);
        $this->db->startTransaction();
        try {
            $order = $this->db->queryFirstRow(
                'SELECT id, order_number, payment_status, payment_method, total_czk, variable_symbol
                 FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId);
            if ($order === null || $order['payment_method'] !== 'bank_transfer' ||
                $order['payment_status'] !== 'paid') {
                throw new InvalidArgumentException('Příjem lze přiřadit jen k zaplacené objednávce převodem.');
            }
            $already = $this->db->queryFirstField(
                'SELECT COUNT(*) FROM shop_tax_entries WHERE order_id=%i', $orderId);
            if ((int) $already > 0) throw new InvalidArgumentException('Tato objednávka už má zapsaný příjem.');
            $this->db->insert('shop_tax_entries', [
                'entry_date' => $date, 'direction' => 'income', 'account' => 'bank',
                'tax_kind' => 'taxable', 'amount_czk' => (int) $order['total_czk'],
                'description' => 'Úhrada objednávky ' . $order['order_number'],
                'counterparty' => '', 'reference' => $reference !== '' ? $reference :
                    (string) ($order['variable_symbol'] ?? ''), 'order_id' => $orderId,
            ]);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function orderReceipt(int $orderId): ?array
    {
        if ($orderId < 1 || !$this->installed()) return null;
        return $this->db->queryFirstRow('SELECT id, entry_date, amount_czk, reference
            FROM shop_tax_entries WHERE order_id=%i AND direction=%s ORDER BY id DESC LIMIT 1',
            $orderId, 'income');
    }

    public function entries(int $year): array
    {
        self::year($year);
        return $this->db->query(
            'SELECT id, entry_date, direction, account, tax_kind, amount_czk,
                    description, counterparty, reference, order_id
             FROM shop_tax_entries WHERE entry_date >= %s AND entry_date < %s
             ORDER BY entry_date DESC, id DESC LIMIT %i',
            $year . '-01-01', ($year + 1) . '-01-01', 500
        );
    }

    /** Export the complete annual money journal, capped to a reviewable file size. */
    public function writeLedgerCsv(mixed $stream, int $year): int
    {
        self::year($year);
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Neplatný výstup CSV.');
        }
        if (fwrite($stream, "\xEF\xBB\xBF") !== 3 ||
            fputcsv($stream, ['Datum', 'Účet', 'Pohyb', 'Zařazení', 'Částka Kč',
                'Popis', 'Protistrana', 'Doklad', 'Objednávka ID'], ';', '"', '') === false) {
            throw new RuntimeException('CSV se nepodařilo vytvořit.');
        }
        $offset = 0;
        do {
            $rows = $this->db->query('SELECT entry_date, account, direction, tax_kind,
                    amount_czk, description, counterparty, reference, order_id
                FROM shop_tax_entries WHERE entry_date >= %s AND entry_date < %s
                ORDER BY entry_date ASC, id ASC LIMIT %i OFFSET %i',
                $year . '-01-01', ($year + 1) . '-01-01', 200, $offset);
            foreach ($rows as $row) {
                if ($offset >= 50000) throw new InvalidArgumentException('Deník je příliš dlouhý pro jediný export.');
                $values = [
                    $row['entry_date'], $row['account'], $row['direction'], $row['tax_kind'],
                    $row['amount_czk'], self::safeCsvCell($row['description']),
                    self::safeCsvCell($row['counterparty']), self::safeCsvCell($row['reference']),
                    $row['order_id'] ?? '',
                ];
                if (fputcsv($stream, $values, ';', '"', '') === false) {
                    throw new RuntimeException('CSV se nepodařilo vytvořit.');
                }
                $offset++;
            }
        } while (count($rows) === 200);
        return $offset;
    }

    public function summary(int $year): array
    {
        self::year($year);
        $row = $this->db->queryFirstRow(
            'SELECT COALESCE(SUM(CASE WHEN direction=%s AND tax_kind=%s THEN amount_czk ELSE 0 END),0) AS income,
                    COALESCE(SUM(CASE WHEN direction=%s AND tax_kind=%s THEN amount_czk ELSE 0 END),0) AS expenses
             FROM shop_tax_entries WHERE entry_date >= %s AND entry_date < %s',
            'income', 'taxable', 'expense', 'deductible', $year . '-01-01', ($year + 1) . '-01-01'
        ) ?? [];
        return ['income' => (int) ($row['income'] ?? 0), 'expenses' => (int) ($row['expenses'] ?? 0)];
    }

    public function addBalance(array $input): void
    {
        $kind = $input['kind'] ?? '';
        $amount = filter_var($input['amount_czk'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 99999999]]);
        if (!in_array($kind, ['receivable', 'liability', 'asset'], true) || $amount === false) {
            throw new InvalidArgumentException('Vyber majetek, pohledávku nebo dluh a částku.');
        }
        $description = self::text($input['description'] ?? '', 255);
        if ($description === '') throw new InvalidArgumentException('Vyplň popis záznamu.');
        $this->db->insert('shop_tax_balances', [
            'kind' => $kind, 'opened_on' => self::date($input['opened_on'] ?? null),
            'amount_czk' => $amount, 'description' => $description,
            'counterparty' => self::text($input['counterparty'] ?? '', 190),
            'reference' => self::text($input['reference'] ?? '', 100),
        ]);
    }

    public function closeBalance(int $id, string $date): void
    {
        if ($id < 1) throw new InvalidArgumentException('Neplatný záznam.');
        $closed = self::date($date);
        $this->db->query('UPDATE shop_tax_balances SET closed_on=%s
            WHERE id=%i AND closed_on IS NULL AND opened_on<=%s', $closed, $id, $closed);
    }

    public function balances(int $year): array
    {
        self::year($year);
        return $this->db->query('SELECT * FROM shop_tax_balances
            WHERE opened_on < %s AND (closed_on IS NULL OR closed_on >= %s)
            ORDER BY id DESC LIMIT %i', ($year + 1) . '-01-01', $year . '-01-01', 500);
    }

    public function orderReceivables(): array
    {
        return $this->db->query('SELECT id, order_number, customer_email, total_czk, created_at
            FROM shop_orders WHERE payment_method IN (%s,%s,%s,%s) AND payment_status=%s
                AND status NOT IN (%s,%s) ORDER BY id DESC LIMIT %i',
            'bank_transfer', 'comgate', 'gopay', 'btcpay', 'pending', 'cancelled', 'test', 100);
    }

    public function addStock(array $input): void
    {
        $key = $input['product_key'] ?? '';
        $quantity = filter_var($input['quantity_change'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => -100000, 'max_range' => 100000]]);
        $cost = ($input['unit_cost_czk'] ?? '') === '' ? null : filter_var($input['unit_cost_czk'],
            FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 99999999]]);
        if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 ||
            $quantity === false || $quantity === 0 || $cost === false) {
            throw new InvalidArgumentException('Vyber produkt, nenulovou změnu kusů a správnou pořizovací cenu.');
        }
        $description = self::text($input['description'] ?? '', 255);
        if ($description === '') throw new InvalidArgumentException('Uveď důvod pohybu skladu.');
        $this->db->insert('shop_stock_movements', [
            'product_key' => $key, 'movement_date' => self::date($input['movement_date'] ?? null),
            'quantity_change' => $quantity, 'unit_cost_czk' => $cost,
            'description' => $description, 'reference' => self::text($input['reference'] ?? '', 100),
        ]);
    }

    public function stockMovements(): array
    {
        return $this->db->query('SELECT * FROM shop_stock_movements ORDER BY id DESC LIMIT %i', 100);
    }

    public function saleLines(int $year, int $limit = 500, int $offset = 0): array
    {
        self::year($year);
        if ($limit < 1 || $limit > 500 || $offset < 0 || $offset > 50000) {
            throw new InvalidArgumentException('Neplatná stránka prodejů.');
        }
        return $this->db->query(
            'SELECT * FROM (
                SELECT l.order_id, o.order_number, l.product_key, l.name, l.quantity,
                    l.unit_price_czk, o.created_at, o.status, l.line_no AS sort_key
                FROM shop_sale_lines l JOIN shop_orders o ON o.id=l.order_id
                WHERE o.created_at >= %s AND o.created_at < %s AND o.status NOT IN (%s,%s)
                UNION ALL
                SELECT NULL AS order_id, a.order_number, a.product_key, a.name, a.quantity,
                    a.unit_price_czk, a.order_created_at AS created_at, a.order_status AS status,
                    a.id AS sort_key
                FROM shop_deleted_sale_lines a
                WHERE a.order_created_at >= %s AND a.order_created_at < %s
            ) AS sold ORDER BY created_at DESC, order_number DESC, sort_key ASC LIMIT %i OFFSET %i',
            $year . '-01-01', ($year + 1) . '-01-01', 'cancelled', 'test',
            $year . '-01-01', ($year + 1) . '-01-01', $limit, $offset
        );
    }

    public function writeSalesCsv(mixed $stream, int $year): int
    {
        self::year($year);
        self::csvHeader($stream, ['Datum objednávky', 'Objednávka', 'Produktový klíč',
            'Název', 'Kusů', 'Cena/ks Kč', 'Celkem Kč', 'Stav']);
        $offset = 0;
        do {
            $rows = $this->saleLines($year, 200, $offset);
            foreach ($rows as $row) {
                if ($offset >= 50000) throw new InvalidArgumentException('Export prodejů přesáhl 50 000 řádků.');
                if (fputcsv($stream, [$row['created_at'], self::safeCsvCell($row['order_number']),
                    $row['product_key'], self::safeCsvCell($row['name']), $row['quantity'],
                    $row['unit_price_czk'], (int) $row['quantity'] * (int) $row['unit_price_czk'],
                    $row['status']], ';', '"', '') === false) {
                    throw new RuntimeException('CSV se nepodařilo vytvořit.');
                }
                $offset++;
            }
        } while (count($rows) === 200);
        return $offset;
    }

    public function writeStockCsv(mixed $stream, int $year): int
    {
        self::year($year);
        self::csvHeader($stream, ['Datum', 'Produktový klíč', 'Změna kusů',
            'Pořizovací cena/ks Kč', 'Důvod', 'Doklad']);
        $offset = 0;
        do {
            $rows = $this->db->query('SELECT movement_date, product_key, quantity_change,
                    unit_cost_czk, description, reference FROM shop_stock_movements
                WHERE movement_date >= %s AND movement_date < %s
                ORDER BY movement_date ASC, id ASC LIMIT %i OFFSET %i',
                $year . '-01-01', ($year + 1) . '-01-01', 200, $offset);
            foreach ($rows as $row) {
                if ($offset >= 50000) throw new InvalidArgumentException('Export skladu přesáhl 50 000 řádků.');
                if (fputcsv($stream, [$row['movement_date'], $row['product_key'],
                    $row['quantity_change'], $row['unit_cost_czk'] ?? '',
                    self::safeCsvCell($row['description']), self::safeCsvCell($row['reference'])],
                    ';', '"', '') === false) {
                    throw new RuntimeException('CSV se nepodařilo vytvořit.');
                }
                $offset++;
            }
        } while (count($rows) === 200);
        return $offset;
    }

    public function products(string $search = ''): array
    {
        $search = trim($search);
        if (strlen($search) > 100) throw new InvalidArgumentException('Hledání je příliš dlouhé.');
        return $this->db->query(
            'SELECT p.product_key, p.name, p.stock_status,
                COALESCE((SELECT SUM(m.quantity_change) FROM shop_stock_movements m
                          WHERE m.product_key=p.product_key),0) AS received,
                COALESCE((SELECT SUM(l.quantity) FROM shop_sale_lines l
                          JOIN shop_orders o ON o.id=l.order_id
                          WHERE l.product_key=p.product_key AND o.status IN (%s,%s)),0) AS dispatched
             FROM product_revisions p WHERE p.active_product_key IS NOT NULL AND p.language=%s
                 AND (%s=%s OR LOCATE(%s,p.name)>0 OR p.product_key=%s)
             ORDER BY p.name ASC LIMIT %i',
            'shipped', 'completed', 'cs', $search, '', $search, $search, 60
        );
    }

    /** Import older order snapshots in small repeatable batches after the schema upgrade. */
    public function backfillSaleLines(): int
    {
        $orders = $this->db->query('SELECT o.id, o.items_json FROM shop_orders o
            WHERE o.status<>%s AND NOT EXISTS
                (SELECT 1 FROM shop_sale_lines l WHERE l.order_id=o.id)
            ORDER BY o.id ASC LIMIT %i', 'test', 100);
        $count = 0;
        foreach ($orders as $order) {
            $items = json_decode((string) $order['items_json'], true);
            if (!is_array($items)) continue;
            $this->db->startTransaction();
            try {
                foreach ($items as $index => $item) {
                    if (!is_array($item) || !is_string($item['product_key'] ?? null) ||
                        preg_match('/^[a-f0-9]{32}$/D', $item['product_key']) !== 1 ||
                        (int) ($item['quantity'] ?? 0) < 1) continue;
                    $this->db->query('INSERT IGNORE INTO shop_sale_lines
                        (order_id, line_no, product_key, name, quantity, unit_price_czk)
                        VALUES (%i,%i,%s,%s,%i,%i)', (int) $order['id'], $index + 1,
                        $item['product_key'], (string) ($item['name'] ?? ''),
                        (int) $item['quantity'], (int) ($item['unit_price_czk'] ?? 0));
                }
                $this->db->commit();
                $count++;
            } catch (\Throwable $error) {
                $this->db->rollback();
                throw $error;
            }
        }
        return $count;
    }

    public static function year(int $year): void
    {
        if ($year < 2000 || $year > 2100) throw new InvalidArgumentException('Neplatný rok evidence.');
    }

    private static function safeCsvCell(string $cell): string
    {
        return preg_match('/^[\x00-\x20]*[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell;
    }

    private static function csvHeader(mixed $stream, array $columns): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream' ||
            fwrite($stream, "\xEF\xBB\xBF") !== 3 ||
            fputcsv($stream, $columns, ';', '"', '') === false) {
            throw new RuntimeException('CSV se nepodařilo vytvořit.');
        }
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value)) throw new InvalidArgumentException('Zadej platné datum.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value ||
            $date->format('Y') < '2000' || $date->format('Y') > '2100') {
            throw new InvalidArgumentException('Zadej platné datum.');
        }
        return $value;
    }

    private static function text(mixed $value, int $limit): string
    {
        if (!is_string($value)) throw new InvalidArgumentException('Zadej platný text.');
        $value = trim($value);
        if (strlen($value) > $limit || preg_match('//u', $value) !== 1 ||
            preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('Text je příliš dlouhý nebo obsahuje neplatné znaky.');
        }
        return $value;
    }
}
