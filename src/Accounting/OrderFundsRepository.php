<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Checkout\AssistedPurchase;
use Throwable;

/** Actual receipts/disbursements; order payment status is deliberately not a cash ledger. */
final class OrderFundsRepository
{
    public function __construct(private MeekroDB $db) {}

    public function order(int $id): ?array
    {
        if ($id < 1) return null;
        $order = $this->db->queryFirstRow('SELECT id,order_number,status,payment_method,payment_status,
            total_czk,items_json,customer_email,created_at FROM shop_orders WHERE id=%i', $id);
        if ($order === null) return null;
        $order['items'] = json_decode($order['items_json'], true, 512, JSON_THROW_ON_ERROR);
        $order['principal_czk'] = AssistedPurchase::principal($order['items']);
        return $order;
    }

    public function page(int $offset, string $search = '', string $mode = 'assisted'): array
    {
        if ($offset < 0 || $offset > 1000000 || strlen($search) > 150 || !in_array($mode, ['all', 'assisted'], true)) {
            throw new InvalidArgumentException('Neplatný filtr vyúčtování.');
        }
        $where = "status<>'test' AND payment_method<>'test'";
        if ($mode === 'assisted') $where .= " AND JSON_SEARCH(items_json, 'one', 'assisted', NULL, '$[*].sale_mode') IS NOT NULL";
        $needle = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], trim($search)) . '%';
        $rows = $this->db->query("SELECT id,order_number,total_czk,items_json,customer_email FROM shop_orders
            WHERE " . $where . " AND CONCAT_WS(' ',order_number,customer_email) LIKE %s ESCAPE '='
            ORDER BY id DESC LIMIT 31 OFFSET %i", $needle, $offset);
        $more = count($rows) > 30;
        if ($more) array_pop($rows);
        foreach ($rows as &$row) {
            $row['principal_czk'] = AssistedPurchase::principal(json_decode($row['items_json'], true, 512, JSON_THROW_ON_ERROR));
            $row['funds'] = $this->totals((int) $row['id']);
        }
        unset($row);
        return ['items' => $rows, 'nextOffset' => $more ? $offset + 30 : null];
    }

    public function totals(int $orderId, ?string $through = null): array
    {
        $params = [$orderId];
        $where = 'order_id=%i';
        if ($through !== null) { $where .= ' AND entry_date<=%s'; $params[] = $through; }
        $rows = $this->db->query('SELECT purpose,direction,SUM(amount_czk) AS amount_czk FROM shop_tax_entries
            WHERE ' . $where . ' GROUP BY purpose,direction', ...$params);
        $totals = array_fill_keys(array_keys(TaxEvidenceRepository::PURPOSES), 0);
        foreach ($rows as $row) $totals[$row['purpose']] = ($totals[$row['purpose']] ?? 0) + TaxMoney::cents((string) $row['amount_czk'], true);
        $totals['held'] = $totals['principal_receipt'] + $totals['supplier_return'] - $totals['supplier_payment'] - $totals['principal_return'];
        $totals['received'] = $totals['own_receipt'] + $totals['principal_receipt'];
        $totals['returned'] = $totals['own_return'] + $totals['principal_return'];
        return array_map([TaxMoney::class, 'decimal'], $totals);
    }

    public function entries(int $id): array
    {
        return $this->db->query('SELECT * FROM shop_tax_entries WHERE order_id=%i ORDER BY entry_date,id', $id);
    }

    public function refundCases(int $orderId): array
    {
        return $this->db->query('SELECT case_number,refund_amount_czk,refund_reference,refunded_at
            FROM shop_after_sales_cases WHERE order_id=%i AND refunded_at IS NOT NULL ORDER BY id', $orderId);
    }

    /** Reconstruct year-end balances from movements, including deleted orders and earlier years. */
    public function writeBalancesCsv(mixed $stream, int $year): void
    {
        TaxEvidenceRepository::year($year);
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['K datu','Objednávka ID','Číslo objednávky','Přijato svěřeno Kč','Vráceno dodavatelem Kč','Zaplaceno dodavateli Kč','Vráceno zákazníkovi Kč','Zůstatek Kč'], ';', '"', '');
        $offset = 0;
        do {
            $rows = $this->db->query("SELECT order_id,order_number,
                SUM(CASE WHEN purpose='principal_receipt' THEN amount_czk ELSE 0 END) AS received,
                SUM(CASE WHEN purpose='supplier_return' THEN amount_czk ELSE 0 END) AS supplier_return,
                SUM(CASE WHEN purpose='supplier_payment' THEN amount_czk ELSE 0 END) AS paid,
                SUM(CASE WHEN purpose='principal_return' THEN amount_czk ELSE 0 END) AS returned
                FROM shop_tax_entries WHERE entry_date<=%s AND purpose IN
                ('principal_receipt','supplier_return','supplier_payment','principal_return')
                GROUP BY order_id,order_number ORDER BY order_id,order_number LIMIT 200 OFFSET %i", $year . '-12-31', $offset);
            foreach ($rows as $row) {
                $held = TaxMoney::cents((string) $row['received'],true) + TaxMoney::cents((string) $row['supplier_return'],true)
                    - TaxMoney::cents((string) $row['paid'],true) - TaxMoney::cents((string) $row['returned'],true);
                fputcsv($stream, [$year . '-12-31', $row['order_id'] ?? '', TaxEvidenceRepository::safeCsvCell($row['order_number']),
                    $row['received'], $row['supplier_return'], $row['paid'], $row['returned'], TaxMoney::decimal($held)], ';', '"', '');
            }
            $offset += count($rows);
        } while (count($rows) === 200);
    }

    /** Explicit split lets the admin record partial payments and historical adjustments. */
    public function record(int $id, array $input): void
    {
        $kind = $input['movement'] ?? '';
        if (!in_array($kind, ['receipt', 'refund', 'supplier_payment', 'supplier_return'], true)) {
            throw new InvalidArgumentException('Vyber druh skutečné platby.');
        }
        $own = TaxMoney::cents($input['own_czk'] ?? '0', true);
        $principal = TaxMoney::cents($input['principal_czk'] ?? '0', true);
        if ($own + $principal === 0 || in_array($kind, ['supplier_payment', 'supplier_return'], true) && $own !== 0) {
            throw new InvalidArgumentException('Vyplň skutečnou částku. Platba dodavateli za zákazníka patří celá do svěřených peněz.');
        }
        // Foreign amounts need a deliberate per-component valuation; use the journal form.
        if (($input['account'] ?? '') === 'crypto' || ($input['currency'] ?? 'CZK') !== 'CZK') {
            throw new InvalidArgumentException('Platbu v kryptu nebo cizí měně rozděl přes peněžní deník; u každé části vyplň množství a ocenění.');
        }
        $this->db->startTransaction();
        try {
            TaxWriteGuard::claim($this->db, $input);
            $locked = $this->db->queryFirstRow('SELECT id,status FROM shop_orders WHERE id=%i FOR UPDATE', $id);
            if ($locked === null || $locked['status'] === 'test') throw new InvalidArgumentException('Vyber skutečnou objednávku.');
            $order = $this->order($id);
            $tax = new TaxEvidenceRepository($this->db);
            $base = ['entry_date' => $input['entry_date'] ?? '', 'account' => $input['account'] ?? '',
                'reference' => $input['reference'] ?? '', 'counterparty' => $input['counterparty'] ?? '',
                'order_id' => $id];
            $parts = $kind === 'receipt' ? [['own_receipt', 'income', 'taxable', $own], ['principal_receipt', 'income', 'nontaxable', $principal]] :
                ($kind === 'refund' ? [['own_return', 'expense', 'income_return', $own], ['principal_return', 'expense', 'nondeductible', $principal]] :
                [[$kind, $kind === 'supplier_payment' ? 'expense' : 'income', $kind === 'supplier_payment' ? 'nondeductible' : 'nontaxable', $principal]]);
            foreach ($parts as [$purpose, $direction, $taxKind, $amount]) {
                if ($amount === 0) continue;
                $tax->addEntry($base + ['purpose' => $purpose, 'direction' => $direction, 'tax_kind' => $taxKind,
                    'amount_czk' => TaxMoney::decimal($amount),
                    'description' => TaxEvidenceRepository::PURPOSES[$purpose] . ' · ' . $order['order_number']]);
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    /** Same-currency own transfer, not another sale. Both rows succeed or fail together. */
    public function transfer(array $input): void
    {
        $from = $input['from_account'] ?? '';
        $to = $input['to_account'] ?? '';
        if ($from === $to || !in_array($from, ['bank','cash','clearing'], true) || !in_array($to, ['bank','cash','clearing'], true)) {
            throw new InvalidArgumentException('Vyber dva různé korunové účty. Směnu měn zapiš samostatně podle podkladů.');
        }
        $this->db->startTransaction();
        try {
            TaxWriteGuard::claim($this->db, $input);
            $tax = new TaxEvidenceRepository($this->db);
            foreach ([[$from, 'expense', 'nondeductible'], [$to, 'income', 'nontaxable']] as [$account, $direction, $kind]) {
                $tax->addEntry(['entry_date' => $input['entry_date'] ?? '', 'amount_czk' => $input['amount_czk'] ?? '',
                    'account' => $account, 'direction' => $direction, 'tax_kind' => $kind, 'purpose' => 'transfer',
                    'description' => 'Vlastní převod: ' . TaxEvidenceRepository::ACCOUNTS[$from] . ' → ' . TaxEvidenceRepository::ACCOUNTS[$to],
                    'reference' => $input['reference'] ?? '']);
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
