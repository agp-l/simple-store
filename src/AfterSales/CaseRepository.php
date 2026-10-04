<?php
declare(strict_types=1);

namespace SimpleStore\AfterSales;

use DateTimeImmutable;
use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use SimpleStore\Accounting\TaxEvidenceRepository;
use Throwable;

/** Customer-owned complaint and withdrawal records, independent of later order edits. */
final class CaseRepository
{
    public const KINDS = ['complaint' => 'Reklamace', 'withdrawal' => 'Odstoupení od smlouvy'];
    public const STATUSES = [
        'submitted' => 'Podáno', 'awaiting_goods' => 'Čekáme na zboží',
        'reviewing' => 'Posuzujeme', 'resolved' => 'Vyřízeno', 'rejected' => 'Zamítnuto',
    ];
    public const REMEDIES = [
        'repair' => 'Oprava', 'replacement' => 'Výměna',
        'discount' => 'Přiměřená sleva', 'refund' => 'Vrácení peněz',
    ];

    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        foreach (['shop_after_sales_cases', 'shop_after_sales_events'] as $table) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table
            ) === 0) return false;
        }
        foreach (['seller_json', 'item_options_json', 'repair_duration'] as $column) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',
                'shop_after_sales_cases', $column
            ) === 0) return false;
        }
        return true;
    }

    /** The order receipt token is the only guest credential; no guessed number grants access. */
    public function orderForToken(string $token): ?array
    {
        if (!self::validToken($token)) return null;
        $row = $this->db->queryFirstRow(
            'SELECT id, user_id, order_number, customer_email, status, payment_status, items_json, shipping_json,
                    total_czk, created_at FROM shop_orders WHERE order_token=%s LIMIT 1', $token
        );
        if ($row === null) return null;
        $row['items'] = json_decode((string) $row['items_json'], true);
        $row['shipping'] = json_decode((string) $row['shipping_json'], true);
        $row['items'] = is_array($row['items']) ? array_values($row['items']) : [];
        $row['shipping'] = is_array($row['shipping']) ? $row['shipping'] : [];
        unset($row['items_json'], $row['shipping_json']);
        return $row;
    }

    public function submit(string $orderToken, array $input): array
    {
        if (!$this->installed() || !self::validToken($orderToken)) {
            throw new InvalidArgumentException('Pro podání je potřeba soukromý odkaz na objednávku a aktuální SQL tabulky.');
        }
        $kind = self::choice($input['kind'] ?? null, self::KINDS, 'Vyber reklamaci nebo odstoupení.');
        $line = filter_var($input['item_line'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 200]]);
        $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if ($line === false || $quantity === false) throw new InvalidArgumentException('Vyber položku a počet kusů.');
        $description = self::field($input['description'] ?? null, 5000);
        if ($kind === 'complaint' && preg_match('/^.{10,}$/usD', $description) !== 1) {
            throw new InvalidArgumentException('Popiš prosím konkrétní vadu alespoň deseti znaky.');
        }
        if ($kind === 'withdrawal' && ($input['confirmed'] ?? null) !== '1') {
            throw new InvalidArgumentException('Potvrď odeslání odstoupení od smlouvy.');
        }
        $requestKey = $input['request_key'] ?? null;
        if (!is_string($requestKey) || !self::validToken($requestKey)) {
            throw new InvalidArgumentException('Platnost podání vypršela. Obnov stránku a odešli jej znovu.');
        }
        $solution = $kind === 'withdrawal' ? 'refund' :
            self::choice($input['requested_solution'] ?? null, self::REMEDIES, 'Vyber požadované řešení reklamace.');
        $deliveredOn = $input['delivered_on'] ?? '';
        if (!is_string($deliveredOn) || ($deliveredOn !== '' &&
            (!DateTimeImmutable::createFromFormat('!Y-m-d', $deliveredOn) ||
                DateTimeImmutable::createFromFormat('!Y-m-d', $deliveredOn)?->format('Y-m-d') !== $deliveredOn ||
                $deliveredOn > gmdate('Y-m-d')))) {
            throw new InvalidArgumentException('Datum převzetí zboží musí být platné a nesmí být v budoucnosti.');
        }

        $this->db->startTransaction();
        try {
            $row = $this->db->queryFirstRow(
                'SELECT id, user_id, order_number, customer_email, status, payment_status,
                        items_json, shipping_json, total_czk
                 FROM shop_orders WHERE order_token=%s LIMIT 1 FOR UPDATE', $orderToken
            );
            if ($row === null) {
                throw new InvalidArgumentException('K této objednávce už nelze podat nový případ. Kontaktuj obchod.');
            }
            $existing = $this->db->queryFirstRow(
                'SELECT id, order_id, case_token FROM shop_after_sales_cases WHERE request_key=%s LIMIT 1',
                $requestKey
            );
            if ($existing !== null) {
                if ((int) $existing['order_id'] !== (int) $row['id']) {
                    throw new InvalidArgumentException('Odkaz na podání neodpovídá objednávce.');
                }
                $this->db->commit();
                return $this->byToken((string) $existing['case_token']) ??
                    throw new RuntimeException('Dřívější podání se nepodařilo načíst.');
            }
            if ($row['status'] === 'test' ||
                ($row['status'] === 'cancelled' && $row['payment_status'] !== 'paid')) {
                throw new InvalidArgumentException('K této objednávce už nelze podat nový případ. Kontaktuj obchod.');
            }
            $items = json_decode((string) $row['items_json'], true);
            $shipping = json_decode((string) $row['shipping_json'], true);
            $item = is_array($items) ? ($items[$line - 1] ?? null) : null;
            if (!is_array($item) || !is_array($shipping) || $quantity > (int) ($item['quantity'] ?? 0) ||
                (int) ($item['unit_price_czk'] ?? 0) < 1 || !filter_var($row['customer_email'], FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Vybraná položka neodpovídá objednávce.');
            }
            if ($kind === 'withdrawal') {
                $alreadyDeclared = (int) $this->db->queryFirstField(
                    'SELECT COALESCE(SUM(quantity),0) FROM shop_after_sales_cases
                     WHERE order_id=%i AND item_line=%i AND kind=%s', (int) $row['id'], $line, 'withdrawal'
                );
                if ($alreadyDeclared + $quantity > (int) $item['quantity']) {
                    throw new InvalidArgumentException('Počet již podaných odstoupení přesahuje objednané kusy.');
                }
            }
            $sellerSettings = (new TaxEvidenceRepository($this->db))->settings();
            $seller = array_intersect_key($sellerSettings,
                array_fill_keys(['name', 'ico', 'street', 'city', 'postal_code', 'email', 'phone'], true));
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($optionsJson) > 4000) throw new InvalidArgumentException('Varianty zboží jsou příliš dlouhé.');
            $number = ($kind === 'complaint' ? 'R' : 'V') . '-' . gmdate('Y') . '-' . strtoupper(bin2hex(random_bytes(6)));
            $token = bin2hex(random_bytes(32));
            $this->db->insert('shop_after_sales_cases', [
                'order_id' => (int) $row['id'], 'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
                'case_number' => $number, 'case_token' => $token, 'request_key' => $requestKey,
                'order_number' => (string) $row['order_number'], 'customer_email' => (string) $row['customer_email'],
                'customer_name' => substr((string) ($shipping['recipient'] ?? $shipping['name'] ?? ''), 0, 120),
                'customer_phone' => substr((string) ($shipping['phone'] ?? ''), 0, 40),
                'customer_company' => substr((string) ($shipping['company'] ?? ''), 0, 120),
                'seller_json' => json_encode($seller, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'order_total_czk' => (int) $row['total_czk'],
                'kind' => $kind, 'status' => 'submitted', 'item_name' => (string) $item['name'],
                'item_options_json' => $optionsJson,
                'item_line' => $line, 'quantity' => $quantity, 'unit_price_czk' => (int) $item['unit_price_czk'],
                'description' => $description, 'requested_solution' => $solution,
                'delivered_on' => $deliveredOn === '' ? null : $deliveredOn,
                'submitted_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $id = (int) $this->db->insertId();
            $this->db->insert('shop_after_sales_events', [
                'case_id' => $id, 'actor' => 'customer', 'actor_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
                'status' => 'submitted', 'message' => $kind === 'withdrawal'
                    ? 'Zákazník výslovně odstoupil od smlouvy v rozsahu uvedené položky a množství.'
                    : 'Zákazník uplatnil reklamaci a zvolil požadovaný způsob vyřízení.',
                'visible_to_customer' => 1,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->db->commit();
            return $this->byToken($token) ?? throw new RuntimeException('Podání se nepodařilo načíst.');
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function byToken(string $token): ?array
    {
        if (!self::validToken($token)) return null;
        $row = $this->db->queryFirstRow('SELECT * FROM shop_after_sales_cases WHERE case_token=%s LIMIT 1', $token);
        return $row === null ? null : $this->withEvents($row);
    }

    public function byId(int $id): ?array
    {
        if ($id < 1) return null;
        $row = $this->db->queryFirstRow('SELECT * FROM shop_after_sales_cases WHERE id=%i LIMIT 1', $id);
        return $row === null ? null : $this->withEvents($row);
    }

    public function byCustomer(int $userId, int $id): ?array
    {
        if ($userId < 1 || $id < 1) return null;
        $row = $this->db->queryFirstRow(
            'SELECT c.* FROM shop_after_sales_cases c
             LEFT JOIN shop_orders o ON o.id=c.order_id
             WHERE c.id=%i AND (c.user_id=%i OR (c.user_id IS NULL AND o.user_id=%i)) LIMIT 1',
            $id, $userId, $userId
        );
        return $row === null ? null : $this->withEvents($row);
    }

    public function forCustomer(int $userId): array
    {
        if ($userId < 1) return [];
        return $this->db->query(
            'SELECT c.id, c.case_token, c.case_number, c.kind, c.status, c.order_number, c.item_name,
                    c.quantity, c.submitted_at, c.resolved_at, c.refunded_at
             FROM shop_after_sales_cases c LEFT JOIN shop_orders o ON o.id=c.order_id
             WHERE c.user_id=%i OR (c.user_id IS NULL AND o.user_id=%i)
             ORDER BY c.id DESC LIMIT 100', $userId, $userId
        );
    }

    public function forOrderToken(string $orderToken): array
    {
        $order = $this->orderForToken($orderToken);
        if ($order === null) return [];
        return $this->db->query(
            'SELECT case_token, case_number, kind, status, item_name, submitted_at
             FROM shop_after_sales_cases WHERE order_id=%i ORDER BY id DESC LIMIT 100', (int) $order['id']
        );
    }

    /** Search by case, order, or email; active cases are shown before resolved ones. */
    public function latest(string $search = '', int $offset = 0, int $limit = 40): array
    {
        if ($offset < 0 || $offset > 100000 || $limit < 1 || $limit > 100 ||
            strlen($search) > 100 || preg_match('/^[\pL\pN @._+-]*$/uD', $search) !== 1) {
            throw new InvalidArgumentException('Neplatné hledání případů.');
        }
        $select = 'SELECT id, case_number, kind, status, order_number, customer_email, item_name,
                    submitted_at, resolved_at, refunded_at FROM shop_after_sales_cases';
        $sort = ' ORDER BY (status IN (%s,%s,%s)) DESC,
            CASE WHEN status IN (%s,%s,%s) THEN submitted_at END ASC,
            id DESC LIMIT %i OFFSET %i';
        if ($search === '') {
            $rows = $this->db->query($select . $sort,
                'submitted', 'awaiting_goods', 'reviewing',
                'submitted', 'awaiting_goods', 'reviewing', $limit + 1, $offset);
        } else {
            $term = '%' . trim($search) . '%';
            $rows = $this->db->query($select . ' WHERE case_number LIKE %s OR order_number LIKE %s
                OR customer_email LIKE %s' . $sort,
                $term, $term, $term, 'submitted', 'awaiting_goods', 'reviewing',
                'submitted', 'awaiting_goods', 'reviewing', $limit + 1, $offset);
        }
        return ['items' => array_slice($rows, 0, $limit),
            'nextOffset' => count($rows) > $limit ? $offset + $limit : null];
    }

    /** A dated visible event is returned for an idempotent customer notification. */
    public function update(int $id, int $adminId, string $action, string $message,
        string $resolutionType = '', string $repairDuration = ''): array
    {
        if ($id < 1 || $adminId < 1) throw new InvalidArgumentException('Vyber případ a přihlášeného správce.');
        $message = self::field($message, 5000);
        if ($action === 'note' && $message === '') throw new InvalidArgumentException('Napiš interní poznámku.');
        if ($action !== 'note' && $action !== 'received' &&
            !isset(self::STATUSES[$action])) throw new InvalidArgumentException('Vyber platný stav případu.');
        if (in_array($action, ['resolved', 'rejected'], true) && preg_match('/^.{12,}$/usD', $message) !== 1) {
            throw new InvalidArgumentException('Písemné vyřízení musí obsahovat důvod nebo popis řešení.');
        }
        if ($action === 'resolved') $resolutionType = self::choice($resolutionType,
            self::REMEDIES, 'Vyber skutečný způsob vyřízení.');
        if ($action === 'rejected') $resolutionType = 'rejected';
        $repairDuration = self::field($repairDuration, 190);
        if ($action === 'resolved' && $resolutionType === 'repair' && $repairDuration === '') {
            throw new InvalidArgumentException('U provedené opravy uveď dobu jejího trvání.');
        }
        if ($action === 'awaiting_goods' && preg_match('/^.{15,}$/usD', $message) !== 1) {
            throw new InvalidArgumentException('Zákazníkovi napiš konkrétní postup a adresu pro zaslání zboží.');
        }

        $this->db->startTransaction();
        try {
            $row = $this->db->queryFirstRow(
                'SELECT * FROM shop_after_sales_cases WHERE id=%i LIMIT 1 FOR UPDATE', $id
            );
            if ($row === null) throw new InvalidArgumentException('Případ nebyl nalezen.');
            $status = $action === 'received' ? 'reviewing' : ($action === 'note' ? $row['status'] : $action);
            if ($action !== 'note' && $action !== 'received' && $status === $row['status']) {
                throw new InvalidArgumentException('Případ již má zvolený stav.');
            }
            if ($action === 'received' && $row['received_at'] !== null) {
                throw new InvalidArgumentException('Zboží již bylo označeno jako převzaté.');
            }
            if ($action === 'received' && in_array($row['status'], ['resolved', 'rejected'], true)) {
                throw new InvalidArgumentException('Vyřízený případ nejdřív znovu otevři.');
            }
            if ($action !== 'note' && $row['refunded_at'] !== null) {
                throw new InvalidArgumentException('Případ s vyplaceným vrácením nelze znovu otevřít.');
            }
            if ($action === 'received') {
                $this->db->query('UPDATE shop_after_sales_cases SET status=%s, received_at=UTC_TIMESTAMP(),
                    updated_at=UTC_TIMESTAMP() WHERE id=%i', $status, $id);
            } elseif ($action !== 'note') {
                $this->db->query('UPDATE shop_after_sales_cases SET status=%s,
                    resolution_type=%s, resolution_text=%s, repair_duration=%s,
                    resolved_at=IF(%i=1, UTC_TIMESTAMP(), NULL), updated_at=UTC_TIMESTAMP() WHERE id=%i',
                    $status, $resolutionType === '' ? null : $resolutionType,
                    in_array($action, ['resolved', 'rejected'], true) ? $message : null,
                    $action === 'resolved' && $resolutionType === 'repair' ? $repairDuration : null,
                    in_array($action, ['resolved', 'rejected'], true) ? 1 : 0, $id);
            }
            $public = $action !== 'note';
            $text = $message !== '' ? $message : match ($action) {
                'received' => 'Zboží jsme převzali k posouzení.',
                'awaiting_goods' => 'Pošleme vám konkrétní pokyny k zaslání zboží.',
                'reviewing' => 'Případ nyní posuzujeme.',
                'submitted' => 'Případ jsme znovu otevřeli.',
                default => '',
            };
            $this->db->insert('shop_after_sales_events', [
                'case_id' => $id, 'actor' => 'admin', 'actor_id' => $adminId,
                'status' => $status, 'message' => $text, 'visible_to_customer' => $public ? 1 : 0,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $eventId = (int) $this->db->insertId();
            $this->db->commit();
            return ['case' => $this->byId($id), 'event_id' => $eventId, 'public' => $public, 'message' => $text];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    /** Record only a refund verified outside this application; never initiate a payment. */
    public function recordRefund(int $id, int $adminId, int $amountCzk, string $reference): array
    {
        $reference = self::field($reference, 120);
        if ($id < 1 || $adminId < 1 || $amountCzk < 1 || $reference === '') {
            throw new InvalidArgumentException('Vyplň částku a ověřenou referenci skutečně vrácené platby.');
        }
        $this->db->startTransaction();
        try {
            // Lock all cases for this order to prevent parallel manual refunds
            // from exceeding the original order total.
            $target = $this->db->queryFirstRow(
                'SELECT order_number FROM shop_after_sales_cases WHERE id=%i LIMIT 1', $id
            );
            if ($target === null) throw new InvalidArgumentException('Případ nebyl nalezen.');
            $siblings = $this->db->query(
                'SELECT id, refund_amount_czk, refunded_at FROM shop_after_sales_cases
                 WHERE order_number=%s ORDER BY id FOR UPDATE', $target['order_number']
            );
            $case = $this->db->queryFirstRow('SELECT * FROM shop_after_sales_cases WHERE id=%i LIMIT 1 FOR UPDATE', $id);
            $alreadyRefunded = 0;
            foreach ($siblings as $sibling) {
                if ((int) $sibling['id'] !== $id && $sibling['refunded_at'] !== null) {
                    $alreadyRefunded += (int) $sibling['refund_amount_czk'];
                }
            }
            if ($case === null || $case['status'] !== 'resolved' || $case['resolution_type'] !== 'refund' ||
                $case['refunded_at'] !== null || $alreadyRefunded + $amountCzk > (int) $case['order_total_czk']) {
                throw new InvalidArgumentException('Vrácení lze zaznamenat jen jednou po vyřízení vrácením peněz; zkontroluj částku.');
            }
            $this->db->query('UPDATE shop_after_sales_cases SET refund_amount_czk=%i, refund_reference=%s,
                refunded_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=%i', $amountCzk, $reference, $id);
            $this->db->insert('shop_after_sales_events', [
                'case_id' => $id, 'actor' => 'admin', 'actor_id' => $adminId, 'status' => 'resolved',
                'message' => 'Vrácení peněz potvrzeno: ' . number_format($amountCzk, 0, ',', ' ') . ' Kč, reference ' . $reference . '.',
                'visible_to_customer' => 1,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $eventId = (int) $this->db->insertId();
            $this->db->commit();
            return ['case' => $this->byId($id), 'event_id' => $eventId, 'public' => true,
                'message' => 'Potvrdili jsme vrácení ' . number_format($amountCzk, 0, ',', ' ') . ' Kč.'];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    /** Remove a mistaken/test case explicitly, including unsent notifications. */
    public function delete(int $id, string $confirmation): void
    {
        if ($id < 1) throw new InvalidArgumentException('Vyber případ.');
        $this->db->startTransaction();
        try {
            $case = $this->db->queryFirstRow('SELECT * FROM shop_after_sales_cases WHERE id=%i LIMIT 1 FOR UPDATE', $id);
            if ($case === null || $confirmation !== $case['case_number']) {
                throw new InvalidArgumentException('Pro smazání přesně opiš číslo případu.');
            }
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                'shop_mail_outbox'
            ) > 0) {
                $rows = $this->db->query('SELECT id, state FROM shop_mail_outbox WHERE event_key LIKE %s FOR UPDATE',
                    'after-sales:' . $id . ':%');
                foreach ($rows as $row) {
                    if ($row['state'] === 'sending') {
                        throw new InvalidArgumentException('Odesílání zprávy právě probíhá. Ověř e-mailovou frontu a opakuj smazání.');
                    }
                }
                $this->db->query('DELETE FROM shop_mail_outbox WHERE event_key LIKE %s', 'after-sales:' . $id . ':%');
            }
            $this->db->query('DELETE FROM shop_after_sales_events WHERE case_id=%i', $id);
            $this->db->query('DELETE FROM shop_after_sales_cases WHERE id=%i', $id);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function withEvents(array $row): array
    {
        $row['events'] = $this->db->query(
            'SELECT id, actor, status, message, visible_to_customer, created_at
             FROM shop_after_sales_events WHERE case_id=%i ORDER BY id ASC', (int) $row['id']
        );
        return $row;
    }

    private static function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    private static function choice(mixed $value, array $choices, string $error): string
    {
        if (!is_string($value) || !isset($choices[$value])) throw new InvalidArgumentException($error);
        return $value;
    }

    private static function field(mixed $value, int $max): string
    {
        if (!is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Neplatný text formuláře.');
        }
        return trim($value);
    }
}
