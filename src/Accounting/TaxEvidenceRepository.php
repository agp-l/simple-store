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
    private const EXPENSE_PERCENTAGES = [30, 40, 60, 80];

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
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
             AND TABLE_NAME=%s AND COLUMN_NAME=%s', 'shop_tax_entries', 'version') > 0;
    }

    public function settings(): array
    {
        $defaults = ['name' => '', 'ico' => '', 'street' => '', 'city' => '',
            'postal_code' => '', 'email' => '', 'phone' => '', 'bank_account' => '',
            'mail_from' => '', 'legal_form' => 'sole_trader',
            'expense_method' => 'actual', 'expense_percentage' => 60];
        if (!$this->installed()) return $defaults;
        $row = $this->db->queryFirstRow('SELECT settings_json FROM shop_tax_settings WHERE id=%i', 1);
        if ($row === null) return $defaults;
        $saved = json_decode((string) $row['settings_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($saved)) return $defaults;
        $settings = array_replace($defaults, array_intersect_key($saved, $defaults));
        if (!in_array($settings['legal_form'], ['sole_trader', 'company'], true)) {
            $settings['legal_form'] = $defaults['legal_form'];
        }
        if (!in_array($settings['expense_method'], ['actual', 'percentage'], true)) {
            $settings['expense_method'] = $defaults['expense_method'];
        }
        if (!in_array($settings['expense_percentage'], self::EXPENSE_PERCENTAGES, true)) {
            $settings['expense_percentage'] = $defaults['expense_percentage'];
        }
        if ($settings['legal_form'] === 'company') {
            $settings['expense_method'] = 'actual';
        }
        return $settings;
    }

    public function saveSettings(array $input): void
    {
        $existing = $this->settings();
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
        $legalForm = $input['legal_form'] ?? $existing['legal_form'];
        $expenseMethod = $input['expense_method'] ?? $existing['expense_method'];
        $percentage = $input['expense_percentage'] ?? $existing['expense_percentage'];
        $percentage = filter_var($percentage, FILTER_VALIDATE_INT);
        if ($legalForm === 'company') {
            $expenseMethod = 'actual';
        }
        if (!in_array($legalForm, ['sole_trader', 'company'], true) ||
            !in_array($expenseMethod, ['actual', 'percentage'], true) ||
            !in_array($percentage, self::EXPENSE_PERCENTAGES, true)) {
            throw new InvalidArgumentException('Vyber právní formu, metodu výdajů a platné procento paušálu.');
        }
        $fields['legal_form'] = $legalForm;
        $fields['expense_method'] = $expenseMethod;
        $fields['expense_percentage'] = $percentage;
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
        $fields = $this->entryFields($input);
        $this->db->insert('shop_tax_entries', $fields + ['order_id' => null]);
    }

    public const ACCOUNTS = ['bank' => 'Banka', 'cash' => 'Hotovost',
        'clearing' => 'Platební brána', 'crypto' => 'Krypto', 'noncash' => 'Nepeněžní plnění'];
    public const KINDS = ['taxable' => 'Zdanitelný příjem', 'nontaxable' => 'Nedaňový příjem',
        'deductible' => 'Daňový výdaj', 'nondeductible' => 'Nedaňový výdaj',
        'income_return' => 'Vrácení zdanitelného příjmu', 'expense_return' => 'Vrácení daňového výdaje'];
    public const PURPOSES = ['manual' => 'Ostatní pohyb', 'own_receipt' => 'Úhrada vlastního prodeje / služby',
        'principal_receipt' => 'Přijetí svěřených peněz', 'supplier_payment' => 'Platba dodavateli za zákazníka',
        'supplier_return' => 'Dodavatel vrátil svěřené peníze', 'principal_return' => 'Vrácení svěřených peněz zákazníkovi',
        'own_return' => 'Vrácení vlastní tržby', 'fee' => 'Poplatek brány / banky', 'transfer' => 'Vlastní převod / vklad / výběr'];
    private const PURPOSE_RULES = [
        'own_receipt' => ['income', 'taxable'], 'principal_receipt' => ['income', 'nontaxable'],
        'supplier_payment' => ['expense', 'nondeductible'], 'supplier_return' => ['income', 'nontaxable'],
        'principal_return' => ['expense', 'nondeductible'], 'own_return' => ['expense', 'income_return'],
        'fee' => ['expense', 'deductible'],
    ];

    private function entryFields(array $input): array
    {
        $date = self::date($input['entry_date'] ?? null);
        $direction = $input['direction'] ?? '';
        $account = $input['account'] ?? '';
        $kind = $input['tax_kind'] ?? '';
        $purpose = $input['purpose'] ?? 'manual';
        $amount = TaxMoney::decimal(TaxMoney::cents($input['amount_czk'] ?? null));
        if (!is_string($account) || !is_string($purpose) || !in_array($direction, ['income', 'expense'], true) || !isset(self::ACCOUNTS[$account]) ||
            !in_array($kind, $direction === 'income' ? ['taxable', 'nontaxable', 'expense_return'] :
                ['deductible', 'nondeductible', 'income_return'], true) || !isset(self::PURPOSES[$purpose])) {
            throw new InvalidArgumentException('Zkontroluj směr pohybu, účet a daňové zařazení.');
        }
        if (isset(self::PURPOSE_RULES[$purpose]) && self::PURPOSE_RULES[$purpose] !== [$direction, $kind] ||
            $purpose === 'transfer' && !in_array($kind, ['nontaxable', 'nondeductible'], true)) {
            throw new InvalidArgumentException('Účel pohybu neodpovídá směru a daňovému zařazení.');
        }
        $description = self::text($input['description'] ?? '', 255);
        if ($description === '') throw new InvalidArgumentException('Popiš peněžní pohyb.');
        $orderId = $input['order_id'] ?? '';
        $order = null;
        if ($orderId !== '' && $orderId !== null && $orderId !== 0 && $orderId !== '0') {
            $orderId = filter_var($orderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $order = $orderId === false ? null : $this->db->queryFirstRow(
                'SELECT id, order_number FROM shop_orders WHERE id=%i', $orderId);
            if ($order === null) throw new InvalidArgumentException('Objednávka neexistuje. Vazbu můžeš nechat prázdnou a uvést číslo do reference.');
        }
        $currency = $input['currency'] ?? 'CZK';
        $foreign = trim((string) ($input['foreign_amount'] ?? ''));
        $valuation = self::text($input['valuation_note'] ?? '', 255);
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Měnu zadej třemi velkými písmeny, např. CZK, EUR, BTC.');
        }
        if ($currency !== 'CZK') {
            $foreign = str_replace(',', '.', $foreign);
            if (!preg_match('/^\d{1,9}(?:\.\d{1,8})?$/D', $foreign) || (float) $foreign <= 0 || $valuation === '') {
                throw new InvalidArgumentException('U cizí měny nebo BTC doplň původní částku a podklad ocenění v Kč.');
            }
        } else $foreign = null;
        if ($account === 'crypto' && $currency === 'CZK') {
            throw new InvalidArgumentException('U krypta uveď měnu, skutečné množství a podklad korunového ocenění.');
        }
        return [
            'entry_date' => $date, 'direction' => $direction, 'account' => $account,
            'tax_kind' => $kind, 'amount_czk' => $amount, 'description' => $description,
            'counterparty' => self::text($input['counterparty'] ?? '', 190),
            'reference' => self::text($input['reference'] ?? '', 100),
            'purpose' => $purpose, 'order_id' => $order['id'] ?? null,
            'order_number' => $order['order_number'] ?? self::text($input['order_number'] ?? '', 40),
            'currency' => $currency, 'foreign_amount' => $foreign, 'valuation_note' => $valuation,
        ];
    }

    public function entry(int $id): ?array
    {
        if ($id < 1) return null;
        return $this->db->queryFirstRow('SELECT * FROM shop_tax_entries WHERE id=%i LIMIT 1', $id);
    }

    public function amendEntry(int $id, array $input, int $adminId, string $reason): void
    {
        self::assertReason($id, $adminId, $reason);
        $this->db->startTransaction();
        try {
            $old = $this->db->queryFirstRow('SELECT * FROM shop_tax_entries WHERE id=%i FOR UPDATE', $id);
            if ($old === null) throw new InvalidArgumentException('Peněžní zápis neexistuje.');
            if (isset($input['version']) && (int) $input['version'] !== (int) $old['version']) {
                throw new InvalidArgumentException('Zápis mezitím změnil jiný správce. Otevři aktuální verzi a porovnej údaje.');
            }
            $merged = array_replace($old, $input);
            if ($old['order_id'] !== null && ($merged['order_id'] ?? null) == $old['order_id'] &&
                $this->db->queryFirstRow('SELECT id FROM shop_orders WHERE id=%i', $old['order_id']) === null) {
                $merged['order_id'] = null; // Deleted orders do not make historical entries uneditable.
            }
            $fields = $this->entryFields($merged);
            $fields['version'] = (int) ($old['version'] ?? 1) + 1;
            $this->db->update('shop_tax_entries', $fields, 'id=%i', $id);
            $this->recordEntryEvent($old, $fields, 'amended', $adminId, $reason);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function voidEntry(int $id, int $adminId, string $reason, ?int $version = null): void
    {
        self::assertReason($id, $adminId, $reason);
        $this->db->startTransaction();
        try {
            $old = $this->db->queryFirstRow('SELECT * FROM shop_tax_entries WHERE id=%i FOR UPDATE', $id);
            if ($old === null) throw new InvalidArgumentException('Peněžní zápis neexistuje.');
            if ($version !== null && $version !== (int) $old['version']) throw new InvalidArgumentException('Zápis se mezitím změnil. Nejdřív načti aktuální verzi.');
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
            FROM shop_tax_entry_events WHERE
                JSON_UNQUOTE(JSON_EXTRACT(old_json, \'$.entry_date\')) BETWEEN %s AND %s
                OR JSON_UNQUOTE(JSON_EXTRACT(new_json, \'$.entry_date\')) BETWEEN %s AND %s
            ORDER BY id DESC LIMIT %i', $year . '-01-01', $year . '-12-31',
                $year . '-01-01', $year . '-12-31', 100);
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
                'SELECT id, order_number, payment_status, payment_method, total_czk, variable_symbol, items_json
                 FROM shop_orders WHERE id=%i LIMIT 1 FOR UPDATE', $orderId);
            if ($order === null || $order['payment_method'] !== 'bank_transfer' ||
                $order['payment_status'] !== 'paid') {
                throw new InvalidArgumentException('Příjem lze přiřadit jen k zaplacené objednávce převodem.');
            }
            $already = $this->db->queryFirstField(
                "SELECT COUNT(*) FROM shop_tax_entries WHERE order_id=%i AND purpose IN ('own_receipt','principal_receipt')", $orderId);
            if ((int) $already > 0) throw new InvalidArgumentException('Tato objednávka už má zapsaný příjem.');
            $items = json_decode((string) ($order['items_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            $principal = \SimpleStore\Checkout\AssistedPurchase::principal($items);
            $receipt = [
                'entry_date' => $date, 'direction' => 'income', 'account' => 'bank',
                'tax_kind' => 'taxable', 'amount_czk' => (int) $order['total_czk'] - $principal,
                'purpose' => 'own_receipt', 'order_number' => $order['order_number'],
                'description' => 'Úhrada objednávky ' . $order['order_number'],
                'counterparty' => '', 'reference' => $reference !== '' ? $reference :
                    (string) ($order['variable_symbol'] ?? ''), 'order_id' => $orderId,
            ];
            if ($receipt['amount_czk'] > 0) $this->db->insert('shop_tax_entries', $receipt);
            if ($principal > 0) $this->db->insert('shop_tax_entries', array_replace($receipt, [
                'tax_kind' => 'nontaxable', 'amount_czk' => $principal, 'purpose' => 'principal_receipt',
                'description' => 'Svěřené prostředky k nákupu · ' . $order['order_number'],
            ]));
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function orderReceipt(int $orderId): ?array
    {
        if ($orderId < 1 || !$this->installed()) return null;
        return $this->db->queryFirstRow('SELECT MIN(id) AS id, MIN(entry_date) AS entry_date, SUM(amount_czk) AS amount_czk, MAX(reference) AS reference
            FROM shop_tax_entries WHERE order_id=%i AND direction=%s AND purpose IN (\'own_receipt\', \'principal_receipt\') GROUP BY order_id',
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

    /** @return array{items: array, nextOffset: ?int} */
    public function entriesPage(int $year, int $offset, int $limit = 50, array $filters = []): array
    {
        self::year($year);
        if ($offset < 0 || $limit < 1 || $limit > 200 || $offset > PHP_INT_MAX - $limit - 1) {
            throw new InvalidArgumentException('Neplatná stránka peněžního deníku.');
        }
        [$where, $params] = self::ledgerFilter($year, $filters);
        $rows = $this->db->query('SELECT * FROM shop_tax_entries WHERE ' . $where .
            ' ORDER BY entry_date DESC, id DESC LIMIT %i OFFSET %i', ...array_merge($params, [$limit + 1, $offset]));
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        return ['items' => $rows, 'nextOffset' => $hasMore ? $offset + $limit : null];
    }

    public static function ledgerFilter(int $year, array $filters): array
    {
        self::year($year);
        $where = 'entry_date >= %s AND entry_date < %s';
        $params = [$year . '-01-01', ($year + 1) . '-01-01'];
        foreach (['account' => self::ACCOUNTS, 'tax_kind' => self::KINDS, 'purpose' => self::PURPOSES] as $key => $choices) {
            $value = $filters[$key] ?? '';
            if ($value === '') continue;
            if (!is_string($value) || !isset($choices[$value])) throw new InvalidArgumentException('Neplatný filtr deníku.');
            $where .= ' AND ' . $key . '=%s'; $params[] = $value;
        }
        if (($filters['order_id'] ?? '') !== '') {
            $id = filter_var($filters['order_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new InvalidArgumentException('Neplatné ID objednávky.');
            $where .= ' AND order_id=%i'; $params[] = $id;
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (($filters[$key] ?? '') !== '') { $where .= ' AND entry_date' . $operator . '%s'; $params[] = self::date($filters[$key]); }
        }
        $search = self::text($filters['q'] ?? '', 150);
        if ($search !== '') {
            $where .= " AND CONCAT_WS(' ', description,counterparty,reference,order_number) LIKE %s ESCAPE '='";
            $params[] = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $search) . '%';
        }
        return [$where, $params];
    }

    /** Export precisely the selected rows, with valuation and order links. */
    public function writeLedgerCsv(mixed $stream, int $year, array $filters = []): int
    {
        [$where, $params] = self::ledgerFilter($year, $filters);
        if (!is_resource($stream)) throw new InvalidArgumentException('Neplatný výstup CSV.');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['ID', 'Datum', 'Účet', 'Pohyb', 'Zařazení', 'Částka Kč', 'Popis',
            'Protistrana', 'Doklad', 'Objednávka ID', 'Účel', 'Číslo objednávky', 'Měna', 'Původní částka', 'Podklad ocenění'], ';', '"', '');
        $offset = 0;
        do {
            $rows = $this->db->query('SELECT id, entry_date, account, direction, tax_kind,
                amount_czk, description, counterparty, reference, order_id, purpose, order_number,
                currency, foreign_amount, valuation_note FROM shop_tax_entries WHERE ' . $where .
                ' ORDER BY entry_date ASC,id ASC LIMIT 200 OFFSET %i', ...array_merge($params, [$offset]));
            foreach ($rows as $row) {
                if (fputcsv($stream, array_map(static fn ($v) => self::safeCsvCell((string) ($v ?? '')), array_values($row)), ';', '"', '') === false) {
                    throw new RuntimeException('CSV se nepodařilo vytvořit.');
                }
                $offset++;
            }
        } while (count($rows) === 200);
        return $offset;
    }

    public function summary(int $year, array $filters = []): array
    {
        [$where, $params] = self::ledgerFilter($year, $filters);
        $row = $this->db->queryFirstRow("SELECT
            COALESCE(SUM(CASE WHEN direction='income' AND tax_kind='taxable' THEN amount_czk
                WHEN direction='expense' AND tax_kind='income_return' THEN -amount_czk ELSE 0 END),0) AS income,
            COALESCE(SUM(CASE WHEN direction='expense' AND tax_kind='deductible' THEN amount_czk
                WHEN direction='income' AND tax_kind='expense_return' THEN -amount_czk ELSE 0 END),0) AS expenses,
            COALESCE(SUM(CASE WHEN direction='income' THEN amount_czk ELSE 0 END),0) AS received,
            COALESCE(SUM(CASE WHEN direction='expense' THEN amount_czk ELSE 0 END),0) AS paid
            FROM shop_tax_entries WHERE " . $where, ...$params) ?? [];
        return array_map(static fn ($v) => TaxMoney::number((string) $v), $row);
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
                          WHERE l.product_key=p.product_key AND o.fulfillment_source=%s
                            AND o.status IN (%s,%s)),0) AS dispatched
             FROM shop_product_revisions p WHERE p.active_product_key IS NOT NULL AND p.language=%s
                 AND (%s=%s OR LOCATE(%s,p.name)>0 OR p.product_key=%s)
             ORDER BY p.name ASC LIMIT %i',
            'own', 'shipped', 'completed', 'cs', $search, '', $search, $search, 60
        );
    }

    /** Import older order snapshots in small repeatable batches after the schema upgrade. */
    public function backfillSaleLines(): int
    {
        $orders = $this->db->query('SELECT o.id, o.items_json FROM shop_orders o
            WHERE o.status<>%s AND o.items_json NOT LIKE %s AND NOT EXISTS
                (SELECT 1 FROM shop_sale_lines l WHERE l.order_id=o.id)
            ORDER BY o.id ASC LIMIT %i', 'test', '%"sale_mode":"assisted"%', 100);
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

    public static function safeCsvCell(string $cell): string
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
