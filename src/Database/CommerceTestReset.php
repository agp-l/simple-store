<?php
declare(strict_types=1);

namespace SimpleStore\Database;

use MeekroDB;
use RuntimeException;
use SimpleStore\Product\ProductStockRepository;
use Throwable;

/** Explicit, one-time removal of development commerce data; never part of schema upgrades. */
final class CommerceTestReset
{
    // Child tables precede their parents. Keep product, content, media, customer,
    // configuration and manually entered physical inventory outside this list.
    private const TABLES = [
        'shop_after_sales_events', 'shop_after_sales_cases',
        'shop_invoice_number_events', 'shop_invoices', 'shop_invoice_sequence',
        'shop_flat_tax_advances', 'shop_tax_entry_events', 'shop_tax_entries',
        'shop_tax_balances', 'shop_tax_year_regimes',
        'shop_mail_outbox', 'shop_order_legal_snapshots', 'shop_order_tracking',
        'shop_sale_lines', 'shop_order_stock_reservations',
        'shop_packeta_cancelled_shipments', 'shop_packeta_shipments', 'shop_carrier_shipments',
        'shop_comgate_payments', 'shop_gopay_payments', 'shop_btcpay_payments',
        'shop_order_admin_events', 'shop_order_financial_events', 'shop_orders',
        'shop_deleted_sale_lines', 'shop_deleted_shipments',
    ];

    public function __construct(private MeekroDB $db)
    {
    }

    public function report(): array
    {
        $name = (string) $this->db->queryFirstField('SELECT DATABASE()');
        if ($name === '' || !$this->exists('shop_orders')) {
            throw new RuntimeException('Připojená databáze nemá tabulku objednávek. Nejdřív aktualizuj SQL schéma.');
        }
        $counts = [];
        foreach (self::TABLES as $table) {
            if ($this->exists($table)) $counts[$table] = (int) $this->db->queryFirstField('SELECT COUNT(*) FROM ' . $table);
        }
        $inventory = [];
        if ($this->exists('shop_order_stock_reservations')) {
            foreach ($this->db->query('SELECT state, COUNT(*) AS rows_count, COALESCE(SUM(quantity),0) AS pieces
                FROM shop_order_stock_reservations GROUP BY state') as $row) {
                $inventory[(string) $row['state']] = [
                    'rows' => (int) $row['rows_count'], 'pieces' => (int) $row['pieces'],
                ];
            }
        }
        return ['database' => $name, 'tables' => $counts, 'reservations' => $inventory,
            'shop_product_revisions' => $this->exists('shop_product_revisions')
                ? (int) $this->db->queryFirstField('SELECT COUNT(*) FROM shop_product_revisions') : null,
            'stock_movements_preserved' => $this->exists('shop_stock_movements')
                ? (int) $this->db->queryFirstField('SELECT COUNT(*) FROM shop_stock_movements') : null];
    }

    public function apply(string $expectedDatabase): array
    {
        $before = $this->report();
        if ($expectedDatabase === '' || !hash_equals($before['database'], $expectedDatabase)) {
            throw new RuntimeException('Název databáze nepotvrzuje aktuální připojení. Nic nebylo smazáno.');
        }
        $this->db->startTransaction();
        try {
            // The tool is meant for an offline development database. A mail worker
            // already transmitting a message cannot be rolled back by deleting rows.
            if ($this->exists('shop_mail_outbox') && (int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM shop_mail_outbox WHERE state=%s', 'sending') > 0) {
                throw new RuntimeException('Právě se odesílá e-mail. Zastav worker a zopakuj úklid.');
            }
            if ($this->exists('shop_order_stock_reservations')) {
                $stock = new ProductStockRepository($this->db);
                foreach ($this->db->query('SELECT id FROM shop_orders ORDER BY id FOR UPDATE') as $order) {
                    $stock->release((int) $order['id']);
                }
            }
            foreach (self::TABLES as $table) {
                if ($this->exists($table)) $this->db->query('DELETE FROM ' . $table);
            }
            if ($before['shop_product_revisions'] !== null && (int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM shop_product_revisions') !== $before['shop_product_revisions']) {
                throw new RuntimeException('Počet produktových revizí se změnil. Úklid byl vrácen zpět.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return ['before' => $before, 'after' => $this->report()];
    }

    private function exists(string $table): bool
    {
        return (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table) === 1;
    }
}
