<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;

/**
 * A read-only register of shop documents and actual cash entries.
 *
 * An order row describes a sale and its current payment state. It does not
 * become a bank receipt merely because a payment gateway marked it paid.
 * Only a linked shop_tax_entries row represents a recorded cash movement.
 */
final class EvidenceBookRepository
{
    private const PAGE_SIZE = 30;

    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        foreach (['shop_orders', 'shop_invoices', 'shop_tax_entries'] as $table) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table
            ) !== 1) return false;
        }
        return true;
    }

    /**
     * @return array{items: list<array<string, mixed>>, nextOffset: ?int}
     *
     * kind=order: an order with optional invoice and optional actual journal entry.
     * kind=entry: a standalone income/expense, including receipts detached when
     *             an order was deleted. Its amount is not an order total.
     * kind=invoice: an issued invoice whose order was deleted. The invoice
     *               remains a document, without an invented payment status.
     *
     * An order appears in a year if its creation, confirmed payment, invoice,
     * or linked journal movement falls in that year. The activity_date is the
     * actual movement date first, then invoice, payment, or order creation date
     * that falls inside the selected year. A cross-year order may consequently
     * be visible in more than one year; this is an activity register, not a
     * calculation of taxable revenue.
     */
    public function page(int $year, int $offset, string $search = ''): array
    {
        if ($year < 2000 || $year > 2100 || $offset < 0 || $offset > 50000) {
            throw new InvalidArgumentException('Neplatný rok nebo stránka evidence.');
        }
        $search = trim($search);
        if (strlen($search) > 150 || preg_match('//u', $search) !== 1 ||
            preg_match('/\p{C}/u', $search) === 1) {
            throw new InvalidArgumentException('Hledání je příliš dlouhé nebo obsahuje neplatné znaky.');
        }
        $start = $year . '-01-01';
        $end = ($year + 1) . '-01-01';

        // The invoice is unique per order. A historical duplicate journal entry
        // cannot multiply the order row: prefer an entry dated in this year.
        $sql = <<<'SQL'
SELECT book.*, book.document_number AS invoice_number,
    CASE WHEN book.kind='order' THEN book.entry_id ELSE NULL END AS receipt_id,
    CASE WHEN book.kind='order' THEN book.entry_date ELSE NULL END AS receipt_date,
    CASE WHEN book.kind='order' THEN book.entry_amount_czk ELSE NULL END AS receipt_amount_czk
FROM (
    SELECT 'order' AS kind, o.id AS record_id,
        CASE
            WHEN e.entry_date >= %s AND e.entry_date < %s THEN e.entry_date
            WHEN i.issue_date >= %s AND i.issue_date < %s THEN i.issue_date
            WHEN o.payment_paid_at >= %s AND o.payment_paid_at < %s THEN DATE(o.payment_paid_at)
            ELSE DATE(o.created_at)
        END AS activity_date,
        o.id AS order_id, o.order_number, o.status AS order_status,
        COALESCE(CONVERT(o.customer_email USING utf8mb4), '') COLLATE utf8mb4_unicode_ci AS customer_email,
        o.total_czk AS total_czk,
        o.variable_symbol, o.payment_method, o.payment_status, o.payment_paid_at,
        i.id AS invoice_id, COALESCE(i.document_number, '') AS document_number,
        i.issue_date AS invoice_issue_date,
        e.id AS entry_id, e.entry_date, e.direction AS entry_direction,
        e.account AS entry_account, e.tax_kind AS entry_tax_kind,
        e.amount_czk AS entry_amount_czk, COALESCE(e.reference, '') AS entry_reference,
        o.total_czk AS amount_czk, '' AS direction,
        COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.buyer_json, '$.name')), ''),
            CONVERT(o.customer_email USING utf8mb4), '') COLLATE utf8mb4_unicode_ci AS counterparty,
        '' AS description
    FROM shop_orders o
    LEFT JOIN shop_invoices i ON i.order_id=o.id
    LEFT JOIN shop_tax_entries e ON e.id=(
        SELECT te.id FROM shop_tax_entries te WHERE te.order_id=o.id
        ORDER BY (te.entry_date >= %s AND te.entry_date < %s) DESC, te.id DESC LIMIT 1
    )
    WHERE o.status <> 'test' AND o.payment_method <> 'test' AND (
        (o.created_at >= %s AND o.created_at < %s)
        OR (o.payment_paid_at >= %s AND o.payment_paid_at < %s)
        OR (i.issue_date >= %s AND i.issue_date < %s)
        OR (e.entry_date >= %s AND e.entry_date < %s)
    )
    UNION ALL
    SELECT 'entry', e.id, e.entry_date,
        NULL, '', '', '', NULL, '', '', '', NULL,
        NULL, '', NULL,
        e.id, e.entry_date, e.direction, e.account, e.tax_kind,
        e.amount_czk, e.reference,
        e.amount_czk, e.direction, e.counterparty, e.description
    FROM shop_tax_entries e
    LEFT JOIN shop_orders linked_order ON linked_order.id=e.order_id
    WHERE linked_order.id IS NULL AND e.entry_date >= %s AND e.entry_date < %s
    UNION ALL
    SELECT 'invoice', i.id, i.issue_date,
        NULL, i.order_number, '',
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(i.buyer_json, '$.email')), '') COLLATE utf8mb4_unicode_ci,
        i.total_czk,
        i.variable_symbol, i.payment_method, '', NULL,
        i.id, i.document_number, i.issue_date,
        NULL, NULL, '', '', '',
        NULL, '',
        i.total_czk, '',
        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(i.buyer_json, '$.name')), '') COLLATE utf8mb4_unicode_ci,
        ''
    FROM shop_invoices i
    LEFT JOIN shop_orders linked_order ON linked_order.id=i.order_id
    WHERE linked_order.id IS NULL AND i.issue_date >= %s AND i.issue_date < %s
) AS book
SQL;
        $params = [
            $start, $end, $start, $end, $start, $end,
            $start, $end,
            $start, $end, $start, $end, $start, $end, $start, $end,
            $start, $end,
            $start, $end,
        ];
        if ($search !== '') {
            // '=' escapes LIKE wildcards and itself, so a reference containing
            // an underscore or percent sign can be searched literally.
            $needle = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';
            $sql .= <<<'SQL'

WHERE LOWER(CONCAT_WS(' ', book.order_number, book.document_number,
    book.variable_symbol, book.counterparty, book.entry_reference,
    book.description)) LIKE LOWER(%s) ESCAPE '='
SQL;
            $params[] = $needle;
        }
        $sql .= "\nORDER BY book.activity_date DESC, book.kind ASC, book.record_id DESC LIMIT %i OFFSET %i";
        $params[] = self::PAGE_SIZE + 1;
        $params[] = $offset;
        $rows = $this->db->query($sql, ...$params);
        $hasMore = count($rows) > self::PAGE_SIZE;
        $items = array_slice($rows, 0, self::PAGE_SIZE);
        foreach ($items as &$item) {
            foreach (['record_id', 'order_id', 'invoice_id', 'entry_id', 'receipt_id',
                'amount_czk', 'total_czk', 'entry_amount_czk', 'receipt_amount_czk'] as $key) {
                if ($item[$key] !== null) $item[$key] = (int) $item[$key];
            }
        }
        unset($item);
        return ['items' => $items,
            'nextOffset' => $hasMore && $offset + self::PAGE_SIZE <= 50000
                ? $offset + self::PAGE_SIZE : null];
    }
}
