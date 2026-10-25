<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Portable, additive directory backups. Product links and order snapshots are never written. */
final class PurchasePartnerTransfer
{
    public const FORMAT = 'simple-store-purchase-partners';
    public const LIMIT = 2097152;
    public const MAX_PARTNERS = 1000;

    public function __construct(private MeekroDB $db) {}

    public static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public function snapshot(): array
    {
        $partners = [];
        foreach ($this->rows() as $row) {
            if (!is_string($row['partner_key']) || preg_match('/^[a-f0-9]{32}$/D', $row['partner_key']) !== 1) {
                throw new InvalidArgumentException('Prodávajícímu chybí stálý identifikátor. Aktualizuj databázi před exportem.');
            }
            $partners[] = ['key' => $row['partner_key']] + self::profile($row);
        }
        return ['format' => self::FORMAT, 'version' => 1, 'partners' => $partners];
    }

    public static function decode(string $json): array
    {
        if ($json === '' || strlen($json) > self::LIMIT) throw new InvalidArgumentException('Vyber JSON prodávajících do 2 MiB.');
        try { $data = json_decode($json, true, 12, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new InvalidArgumentException('Soubor neobsahuje platný JSON.'); }
        if (!is_array($data) || ($data['format'] ?? '') !== self::FORMAT || ($data['version'] ?? null) !== 1 ||
            !is_array($data['partners'] ?? null) || array_values($data['partners']) !== $data['partners'] ||
            count($data['partners']) > self::MAX_PARTNERS) {
            throw new InvalidArgumentException('Vyber zálohu prodávajících ve verzi 1, nejvýše 1 000 profilů.');
        }
        self::keys($data, ['format', 'version', 'partners']);
        $identities = [];
        foreach ($data['partners'] as $index => &$partner) {
            if (!is_array($partner)) throw new InvalidArgumentException('Neplatný profil prodávajícího na řádku ' . ($index + 1) . '.');
            self::keys($partner, array_merge(['key', 'confirmed'], array_keys(PurchasePartnerRepository::FIELDS)));
            foreach (PurchasePartnerRepository::FIELDS as $field => $_) {
                if (!is_string($partner[$field] ?? null)) throw new InvalidArgumentException('Profil č. ' . ($index + 1) . ' musí obsahovat textové pole ' . $field . ' (může být prázdné).');
            }
            if (!is_bool($partner['confirmed'] ?? null)) throw new InvalidArgumentException('Pole confirmed musí být true nebo false.');
            $key = $partner['key'] ?? '';
            if (!is_string($key) || ($key !== '' && preg_match('/^[a-f0-9]{32}$/D', $key) !== 1)) throw new InvalidArgumentException('Neplatný stálý identifikátor prodávajícího.');
            $values = PurchasePartnerRepository::values(array_replace($partner, ['confirmed' => $partner['confirmed'] ? '1' : '0']));
            if ($key === '' && preg_match('/^[0-9]{8}$/D', $values['ico']) !== 1) {
                throw new InvalidArgumentException('Profil bez osmimístného IČO musí mít stálý key z exportu. To brání duplicitám rozpracovaných profilů.');
            }
            if ($key !== '' && isset($identities[$key])) throw new InvalidArgumentException('Soubor obsahuje dvakrát stejný identifikátor prodávajícího.');
            if ($key !== '') $identities[$key] = true;
            $partner = ['key' => $key] + self::profile($values);
        }
        unset($partner);
        return $data;
    }

    public function plan(array $data): array
    {
        return self::compare($data, $this->rows());
    }

    public static function token(array $data, string $revision, string $csrf): string
    {
        return hash_hmac('sha256', self::json($data) . $revision, $csrf);
    }

    public function apply(array $data, string $token, string $csrf): array
    {
        // Revalidate even when invoked outside the controller.
        $data = self::decode(self::json($data));
        $lock = 'partner-import:' . sha1((string) $this->db->queryFirstField('SELECT DATABASE()'));
        if ((int) $this->db->queryFirstField('SELECT GET_LOCK(%s, %i)', $lock, 5) !== 1) {
            throw new InvalidArgumentException('Jiný import prodávajících právě probíhá. Zkus znovu náhled.');
        }
        try {
            $this->db->startTransaction();
            try {
                $plan = self::compare($data, $this->rows(true));
                if ($csrf === '' || !hash_equals(self::token($data, $plan['revision'], $csrf), $token)) {
                    throw new InvalidArgumentException('Seznam nebo obsah importu se od náhledu změnil. Zobraz nový náhled; nic se neuložilo.');
                }
                foreach ($plan['changes'] as $change) {
                    if ($change['state'] === 'same') continue;
                    $values = PurchasePartnerRepository::values(array_replace($change['after'], ['confirmed' => $change['after']['confirmed'] ? '1' : '0']));
                    if ($change['state'] === 'new') {
                        $this->db->insert('shop_purchase_partners', $values + [
                            'partner_key' => $change['key'] ?: bin2hex(random_bytes(16)), 'version' => 1]);
                    } else {
                        $this->db->update('shop_purchase_partners', $values + ['version' => $change['version'] + 1],
                            'id=%i AND version=%i', $change['id'], $change['version']);
                        if ($this->db->affectedRows() !== 1) throw new InvalidArgumentException('Prodávajícího mezitím někdo změnil. Zobraz nový náhled.');
                    }
                }
                $this->db->commit();
                return $plan['counts'];
            } catch (Throwable $error) { $this->db->rollback(); throw $error; }
        } finally {
            $this->db->queryFirstField('SELECT RELEASE_LOCK(%s)', $lock);
        }
    }

    private function rows(bool $lock = false): array
    {
        if (!(new PurchasePartnerRepository($this->db))->transferReady()) throw new InvalidArgumentException('Nejprve aktualizuj databázi v administraci.');
        return $this->db->query('SELECT * FROM shop_purchase_partners ORDER BY id' . ($lock ? ' FOR UPDATE' : ''));
    }

    /** Public for deterministic validation tests; never accepts local IDs from a backup. */
    public static function compare(array $data, array $rows): array
    {
        $data = self::decode(self::json($data));
        $byKey = []; $byIco = []; $revision = [];
        foreach ($rows as $row) {
            if ($row['partner_key'] !== null) $byKey[$row['partner_key']] = $row;
            if (preg_match('/^[0-9]{8}$/D', $row['ico']) === 1) $byIco[$row['ico']][] = $row;
            $revision[] = [(int) $row['id'], $row['partner_key'], (int) $row['version'], self::profile($row)];
        }
        $changes = []; $counts = ['new' => 0, 'changed' => 0, 'same' => 0]; $usedIds = []; $newIcos = []; $claimedIcos = [];
        foreach ($data['partners'] as $partner) {
            $key = $partner['key']; $ico = $partner['ico'];
            $current = $key === '' ? null : ($byKey[$key] ?? null);
            if ($current !== null && preg_match('/^[0-9]{8}$/D', $current['ico']) === 1 && $current['ico'] !== $ico) {
                throw new InvalidArgumentException('Identifikátor profilu „' . $current['name'] . '“ patří jinému IČO. Změnu firmy neprováděj importem.');
            }
            if ($current === null && isset($byIco[$ico])) {
                if (count($byIco[$ico]) !== 1) throw new InvalidArgumentException('IČO ' . $ico . ' má více profilů. Použij stálý key konkrétního profilu z exportu.');
                $current = $byIco[$ico][0];
            } elseif ($current !== null && $current['ico'] !== $ico && isset($byIco[$ico])) {
                throw new InvalidArgumentException('Doplňované IČO ' . $ico . ' již patří jinému profilu.');
            }
            if (preg_match('/^[0-9]{8}$/D', $ico) === 1) {
                // Existing duplicate profiles can round-trip only by their exact keys.
                if (isset($claimedIcos[$ico]) && ($current === null || $current['ico'] !== $ico ||
                    !isset($byKey[$key]) || !$claimedIcos[$ico])) {
                    throw new InvalidArgumentException('Soubor obsahuje konfliktní profily s IČO ' . $ico . '.');
                }
                $claimedIcos[$ico] = $current !== null && $current['ico'] === $ico && isset($byKey[$key]);
            }
            if ($current !== null) {
                if (isset($usedIds[$current['id']])) throw new InvalidArgumentException('Dva profily v souboru odkazují na stejného prodávajícího.');
                $usedIds[$current['id']] = true;
            } elseif (preg_match('/^[0-9]{8}$/D', $ico) === 1) {
                if (isset($newIcos[$ico])) throw new InvalidArgumentException('Soubor obsahuje více nových prodávajících s IČO ' . $ico . '.');
                $newIcos[$ico] = true;
            }
            $after = self::profile($partner);
            $before = $current === null ? null : self::profile($current);
            $state = $current === null ? 'new' : ($before === $after ? 'same' : 'changed');
            $counts[$state]++;
            $changes[] = ['state' => $state, 'id' => (int) ($current['id'] ?? 0), 'key' => $current['partner_key'] ?? $key,
                'version' => (int) ($current['version'] ?? 0), 'before' => $before, 'after' => $after];
        }
        return ['revision' => hash('sha256', self::json($revision)), 'counts' => $counts, 'changes' => $changes];
    }

    private static function profile(array $row): array
    {
        $profile = [];
        foreach (PurchasePartnerRepository::FIELDS as $field => $_) $profile[$field] = (string) ($row[$field] ?? '');
        return $profile + ['confirmed' => (bool) ($row['confirmed'] ?? false)];
    }

    private static function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) throw new InvalidArgumentException('JSON obsahuje nepodporovaná pole. Použij formát exportu prodávajících; číselná ID ani vazby na produkty se nepřenášejí.');
    }
}
