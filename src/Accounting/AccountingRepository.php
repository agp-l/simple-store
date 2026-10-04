<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MeekroDB;
use RuntimeException;

/** Read-only paid-order overview. It is neither an invoice register nor a VAT ledger. */
final class AccountingRepository
{
    private const CSV_MAX_ROWS = 50000;

    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        foreach (['order_number', 'variable_symbol', 'payment_paid_at', 'payment_status',
            'payment_method', 'subtotal_czk', 'shipping_czk', 'total_czk', 'customer_email',
            'shipping_json', 'status'] as $column) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
                'shop_orders', $column
            ) === 0) {
                return false;
            }
        }
        return true;
    }

    public function financialEventsInstalled(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_order_financial_events'
        ) > 0;
    }

    /** Admin corrections by action date, independent of the currently paid orders. */
    public function financialChanges(string $from, string $to, int $offset = 0, int $limit = 25): array
    {
        if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Neplatná stránka historie zásahů.');
        }
        if (!$this->financialEventsInstalled()) return ['items' => [], 'nextOffset' => null];
        [$start, $end] = self::bounds($from, $to);
        $rows = $this->db->query(
            'SELECT order_id, order_number, variable_symbol, action, payment_status_before,
                    payment_paid_at, payment_verified_by, total_czk, reason, admin_id, created_at
             FROM shop_order_financial_events
             WHERE created_at >= %s AND created_at < %s ORDER BY id DESC LIMIT %i OFFSET %i',
            $start, $end, $limit + 1, $offset
        );
        return ['items' => array_slice($rows, 0, $limit),
            'nextOffset' => count($rows) > $limit ? $offset + $limit : null];
    }

    /** Dates refer to the UTC time when an administrator confirmed the payment. */
    public static function period(?string $from, ?string $to): array
    {
        if ($from === null && $to === null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $from = $now->format('Y-m-01');
            $to = $now->format('Y-m-t');
        }
        $first = self::date($from);
        $last = self::date($to);
        if ($first < new DateTimeImmutable('2000-01-01', new DateTimeZone('UTC')) ||
            $last > new DateTimeImmutable('2100-12-31', new DateTimeZone('UTC')) ||
            $last < $first || $first->diff($last)->days > 365) {
            throw new InvalidArgumentException('Vyber časové období nejvýše 366 dní.');
        }
        return [$first->format('Y-m-d'), $last->format('Y-m-d')];
    }

    public function summary(string $from, string $to): array
    {
        [$start, $end] = self::bounds($from, $to);
        $row = $this->db->queryFirstRow(
            'SELECT COUNT(*) AS order_count, COALESCE(SUM(subtotal_czk), 0) AS subtotal_czk,
                    COALESCE(SUM(shipping_czk), 0) AS shipping_czk,
                    COALESCE(SUM(total_czk), 0) AS total_czk
             FROM shop_orders
             WHERE payment_status=%s AND status<>%s AND payment_method<>%s
               AND payment_paid_at >= %s AND payment_paid_at < %s',
            'paid', 'test', 'test', $start, $end
        );
        return [
            'count' => (int) ($row['order_count'] ?? 0),
            'subtotal_czk' => (int) ($row['subtotal_czk'] ?? 0),
            'shipping_czk' => (int) ($row['shipping_czk'] ?? 0),
            'total_czk' => (int) ($row['total_czk'] ?? 0),
        ];
    }

    /** @return array{items: array, nextOffset: ?int} */
    public function page(string $from, string $to, int $offset = 0, int $limit = 25): array
    {
        if ($offset < 0 || $offset > 1000000 || $limit < 1 || $limit > 250) {
            throw new InvalidArgumentException('Neplatná stránka účetních podkladů.');
        }
        [$start, $end] = self::bounds($from, $to);
        $rows = $this->db->query(
            'SELECT id, order_number, variable_symbol, payment_paid_at, subtotal_czk,
                    shipping_czk, total_czk, customer_email, shipping_json, payment_method, status
             FROM shop_orders
             WHERE payment_status=%s AND status<>%s AND payment_method<>%s
               AND payment_paid_at >= %s AND payment_paid_at < %s
             ORDER BY payment_paid_at DESC, id DESC LIMIT %i OFFSET %i',
            'paid', 'test', 'test', $start, $end, $limit + 1, $offset
        );
        return [
            'items' => array_map(static fn (array $row): array => self::displayRow($row),
                array_slice($rows, 0, $limit)),
            'nextOffset' => count($rows) > $limit ? $offset + $limit : null,
        ];
    }

    /** Write a UTF-8 Excel-friendly, semicolon-separated CSV to a caller-owned stream. */
    public function writeCsv(mixed $stream, string $from, string $to): int
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Neplatný výstup pro CSV.');
        }
        $count = $this->summary($from, $to)['count'];
        if ($count > self::CSV_MAX_ROWS) {
            throw new InvalidArgumentException('Export obsahuje příliš mnoho objednávek. Zkrať vybrané období.');
        }
        if (fwrite($stream, "\xEF\xBB\xBF") !== 3 ||
            fputcsv($stream, ['Objednávka', 'Variabilní symbol', 'Potvrzeno UTC',
                'Zákazník', 'E-mail', 'Zboží Kč', 'Doprava Kč', 'Celkem Kč',
                'Platba', 'Vyřízení'], ';', '"', '') === false) {
            throw new RuntimeException('CSV se nepodařilo vytvořit.');
        }
        $offset = 0;
        do {
            $page = $this->page($from, $to, $offset, 200);
            foreach ($page['items'] as $row) {
                if (fputcsv($stream, [
                    self::safeCsvCell((string) $row['order_number']),
                    self::safeCsvCell((string) ($row['variable_symbol'] ?? '')),
                    (string) $row['payment_paid_at'],
                    self::safeCsvCell($row['customer_name']),
                    self::safeCsvCell((string) ($row['customer_email'] ?? '')),
                    (int) ($row['subtotal_czk'] ?? 0),
                    (int) ($row['shipping_czk'] ?? 0),
                    (int) $row['total_czk'],
                    self::safeCsvCell((string) $row['payment_method']),
                    self::safeCsvCell((string) $row['status']),
                ], ';', '"', '') === false) {
                    throw new RuntimeException('CSV se nepodařilo vytvořit.');
                }
                $offset++;
                if ($offset > self::CSV_MAX_ROWS) {
                    throw new RuntimeException('Export překročil limit. Zkrať vybrané období.');
                }
            }
        } while ($page['nextOffset'] !== null);

        return $offset;
    }

    private static function bounds(string $from, string $to): array
    {
        [$from, $to] = self::period($from, $to);
        $until = self::date($to)->modify('+1 day')->format('Y-m-d H:i:s');
        return [$from . ' 00:00:00', $until];
    }

    private static function date(?string $value): DateTimeImmutable
    {
        if ($value === null || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Zadej platné datum od a do.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Zadej platné datum od a do.');
        }
        return $date;
    }

    private static function displayRow(array $row): array
    {
        $shipping = json_decode((string) ($row['shipping_json'] ?? ''), true);
        $name = is_array($shipping) ? ($shipping['recipient'] ?? $shipping['name'] ?? '') : '';
        $row['customer_name'] = is_string($name) && trim($name) !== ''
            ? trim($name) : (string) ($row['customer_email'] ?? '');
        unset($row['shipping_json']);
        return $row;
    }

    private static function safeCsvCell(string $cell): string
    {
        // Spreadsheet applications can evaluate CSV formulas even inside quoted fields.
        return preg_match('/^[\x00-\x20]*[=+\-@]/', $cell) === 1 ? "'" . $cell : $cell;
    }
}
