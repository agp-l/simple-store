<?php
declare(strict_types=1);
namespace SimpleStore\Accounting;

use DateTimeImmutable;
use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Editable assets/debts, annual inventory and non-cash tax adjustments with immutable history. */
final class TaxRegisterRepository
{
    public const KINDS = ['receivable' => 'Pohledávka', 'liability' => 'Dluh', 'asset' => 'Majetek',
        'inventory_stock' => 'Inventura · vlastní zásoby', 'inventory_asset' => 'Inventura · majetek',
        'inventory_receivable' => 'Inventura · pohledávky', 'inventory_liability' => 'Inventura · dluhy',
        'inventory_money' => 'Inventura · peníze / krypto', 'inventory_entrusted' => 'Inventura · svěřené prostředky',
        'income_add' => 'Uzávěrka · zvýšení příjmů', 'income_subtract' => 'Uzávěrka · snížení příjmů',
        'expense_add' => 'Uzávěrka · zvýšení výdajů (např. odpis)', 'expense_subtract' => 'Uzávěrka · snížení výdajů'];
    public function __construct(private MeekroDB $db) {}

    public function byId(int $id): ?array
    {
        return $this->db->queryFirstRow('SELECT * FROM shop_tax_balances WHERE id=%i', $id);
    }

    public static function date(mixed $value): string
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '2000-01-01' || $value > '2100-12-31') {
            throw new InvalidArgumentException('Zadej platné datum (2000–2100).');
        }
        return $value;
    }

    public static function text(mixed $value, int $max): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/u', $value) || strlen($value) > $max * 4 || preg_match_all('/./us', $value) > $max) {
            throw new InvalidArgumentException('Text je příliš dlouhý nebo obsahuje neplatné znaky.');
        }
        return trim($value);
    }

    public function save(int $id, array $input, int $adminId): int
    {
        $kind = $input['kind'] ?? '';
        if (!is_string($kind) || !isset(self::KINDS[$kind]) || $adminId < 1) throw new InvalidArgumentException('Vyber druh záznamu.');
        $date = self::date($input['opened_on'] ?? null);
        $year = in_array($kind, ['receivable','liability','asset'], true) ? null : (int) substr($date, 0, 4);
        $closed = ($input['closed_on'] ?? '') === '' ? null : self::date($input['closed_on']);
        if ($closed !== null && ($closed < $date || $year !== null)) throw new InvalidArgumentException('Datum uzavření patří jen k majetku a dluhům, nejdříve k datu vzniku.');
        $description = self::text($input['description'] ?? '', 255);
        if ($description === '') throw new InvalidArgumentException('Doplň popis záznamu.');
        $fields = ['kind' => $kind, 'opened_on' => $date, 'closed_on' => $closed, 'tax_year' => $year,
            'amount_czk' => TaxMoney::decimal(TaxMoney::cents($input['amount_czk'] ?? '', true)),
            'book_value_czk' => TaxMoney::decimal(TaxMoney::cents(($input['book_value_czk'] ?? '') === '' ? '0' : $input['book_value_czk'], true)),
            'description' => $description, 'counterparty' => self::text($input['counterparty'] ?? '', 190),
            'reference' => self::text($input['reference'] ?? '', 100), 'quantity' => self::text($input['quantity'] ?? '', 40),
            'note' => self::text($input['note'] ?? '', 4000)];
        $reason = self::text($input['reason'] ?? '', 190);
        if ($id > 0 && $reason === '') throw new InvalidArgumentException('Stručně popiš důvod opravy.');
        $this->db->startTransaction();
        try {
            $old = null;
            if ($id > 0) {
                $old = $this->db->queryFirstRow('SELECT * FROM shop_tax_balances WHERE id=%i FOR UPDATE', $id);
                if ($old === null || $old['voided_at'] !== null) throw new InvalidArgumentException('Záznam neexistuje nebo byl vyřazen.');
                if ((int) ($input['version'] ?? 0) !== (int) $old['version']) throw new InvalidArgumentException('Záznam se mezitím změnil. Načti aktuální údaje.');
                $fields['version'] = (int) $old['version'] + 1;
                $this->db->update('shop_tax_balances', $fields, 'id=%i', $id);
            } else {
                TaxWriteGuard::claim($this->db, $input);
                $this->db->insert('shop_tax_balances', $fields);
                $id = (int) $this->db->insertId();
            }
            $this->event($id, $old, $this->byId($id), $adminId, $reason === '' ? 'Vytvoření záznamu' : $reason);
            $this->db->commit();
            return $id;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function void(int $id, int $version, int $adminId, string $reason): void
    {
        $reason = self::text($reason, 190);
        if ($adminId < 1 || $reason === '') throw new InvalidArgumentException('Doplň důvod vyřazení.');
        $this->db->startTransaction();
        try {
            $old = $this->db->queryFirstRow('SELECT * FROM shop_tax_balances WHERE id=%i FOR UPDATE', $id);
            if ($old === null || $old['voided_at'] !== null || (int) $old['version'] !== $version) throw new InvalidArgumentException('Záznam se změnil nebo je již vyřazený.');
            $this->db->update('shop_tax_balances', ['voided_at' => gmdate('Y-m-d H:i:s'), 'version' => $version + 1], 'id=%i', $id);
            $this->event($id, $old, $this->byId($id), $adminId, $reason);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    private function event(int $id, ?array $old, ?array $new, int $admin, string $reason): void
    {
        $this->db->insert('shop_tax_register_events', ['record_id' => $id, 'admin_id' => $admin, 'reason' => $reason,
            'old_json' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'new_json' => json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    public function page(int $year, string $section, int $offset = 0, int $limit = 50): array
    {
        TaxEvidenceRepository::year($year);
        if (!in_array($section, ['balances','closing'], true) || $offset < 0 || $limit < 1 || $limit > 200) throw new InvalidArgumentException('Neplatná stránka evidence.');
        if ($section === 'balances') {
            $where = "kind IN ('receivable','liability','asset') AND opened_on<%s AND (closed_on IS NULL OR closed_on>=%s)";
            $params = [($year + 1) . '-01-01', $year . '-01-01'];
        } else { $where = 'tax_year=%i'; $params = [$year]; }
        $rows = $this->db->query('SELECT * FROM shop_tax_balances WHERE voided_at IS NULL AND ' . $where .
            ' ORDER BY kind,opened_on DESC,id DESC LIMIT %i OFFSET %i', ...array_merge($params, [$limit + 1, $offset]));
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        return ['items' => $rows, 'nextOffset' => $more ? $offset + $limit : null];
    }

    public function history(int $id): array
    {
        return $this->db->query('SELECT * FROM shop_tax_register_events WHERE record_id=%i ORDER BY id DESC', $id);
    }

    public function adjustments(int $year): array
    {
        TaxEvidenceRepository::year($year);
        $rows = $this->db->query('SELECT kind,SUM(amount_czk) AS amount_czk FROM shop_tax_balances
            WHERE tax_year=%i AND voided_at IS NULL GROUP BY kind', $year);
        $result = ['income' => 0, 'expenses' => 0];
        foreach ($rows as $row) {
            $value = TaxMoney::cents((string) $row['amount_czk'], true);
            if ($row['kind'] === 'income_add') $result['income'] += $value;
            if ($row['kind'] === 'income_subtract') $result['income'] -= $value;
            if ($row['kind'] === 'expense_add') $result['expenses'] += $value;
            if ($row['kind'] === 'expense_subtract') $result['expenses'] -= $value;
        }
        return array_map([TaxMoney::class, 'decimal'], $result);
    }

    public function writeCsv(mixed $stream, int $year, string $section): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['ID','Druh','Datum','Uzavřeno','Částka Kč','Evidenční hodnota Kč','Množství / jednotka','Popis','Protistrana','Doklad','Poznámka'], ';', '"', '');
        $offset = 0;
        do {
            $page = $this->page($year, $section, $offset, 200);
            foreach ($page['items'] as $row) {
                $values = array_map(static fn ($key) => TaxEvidenceRepository::safeCsvCell((string) ($row[$key] ?? '')),
                    ['id','kind','opened_on','closed_on','amount_czk','book_value_czk','quantity','description','counterparty','reference','note']);
                if (fputcsv($stream, $values, ';', '"', '') === false) throw new \RuntimeException('CSV se nepodařilo vytvořit.');
            }
            $offset = $page['nextOffset'];
        } while ($offset !== null);
    }
}
