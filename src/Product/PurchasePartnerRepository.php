<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;

/** Editable catalog identities. Orders retain their own immutable seller snapshot. */
final class PurchasePartnerRepository
{
    private ?bool $ready = null;
    public const FIELDS = ['name' => 190, 'ico' => 8, 'address' => 500, 'email' => 190, 'terms_url' => 1000];

    public function __construct(private MeekroDB $db) {}

    public function installed(): bool
    {
        return $this->ready ??= (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
             AND TABLE_NAME=%s AND COLUMN_NAME IN (%s,%s)', 'shop_purchase_partners', 'confirmed', 'version') === 2;
    }

    public function all(): array
    {
        if (!$this->installed()) return [];
        return $this->db->query('SELECT p.*, (SELECT COUNT(*) FROM shop_product_sales s
            WHERE s.partner_id=p.id) AS product_count FROM shop_purchase_partners p ORDER BY p.name, p.id');
    }

    public function find(int $id): ?array
    {
        return $this->installed() ? $this->db->queryFirstRow('SELECT * FROM shop_purchase_partners WHERE id=%i', $id) : null;
    }

    /** Missing or unfinished contact data is valid draft data, never a checkout-ready identity. */
    public function save(int $id, array $input): int
    {
        if (!$this->installed()) throw new InvalidArgumentException('Nejprve aktualizuj databázi v administraci.');
        $values = [];
        foreach (self::FIELDS as $field => $limit) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || strlen(trim($value)) > $limit || preg_match('//u', $value) !== 1 ||
                preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new InvalidArgumentException('Údaj prodávajícího je příliš dlouhý nebo obsahuje nepovolené znaky.');
            }
            $values[$field] = trim($value);
        }
        if ($values['ico'] !== '' && preg_match('/^[0-9]{1,8}$/D', $values['ico']) !== 1) {
            throw new InvalidArgumentException('IČO může obsahovat nejvýše osm číslic.');
        }
        $values['confirmed'] = ($input['confirmed'] ?? '') === '1' ? 1 : 0;
        if ($id === 0) {
            if (implode('', array_intersect_key($values, self::FIELDS)) === '') {
                throw new InvalidArgumentException('Vyplň alespoň jeden údaj nového prodávajícího.');
            }
            $this->db->insert('shop_purchase_partners', $values + ['version' => 1]);
            return (int) $this->db->insertId();
        }
        $version = filter_var($input['version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id < 1 || $version === false) throw new InvalidArgumentException('Neplatná verze prodávajícího. Obnov stránku.');
        $this->db->update('shop_purchase_partners', $values + ['version' => $version + 1],
            'id=%i AND version=%i', $id, $version);
        if ($this->db->affectedRows() !== 1) {
            throw new InvalidArgumentException('Prodávajícího mezitím někdo změnil. Tvé údaje zůstaly ve formuláři; před dalším uložením otevři aktuální profil v nové kartě a porovnej změny.');
        }
        return $id;
    }

    public static function missing(array $partner): array
    {
        $missing = [];
        if (trim((string) ($partner['name'] ?? '')) === '') $missing[] = 'jméno / obchodní firma';
        if (preg_match('/^[0-9]{8}$/D', (string) ($partner['ico'] ?? '')) !== 1) $missing[] = 'osmimístné IČO';
        if (trim((string) ($partner['address'] ?? '')) === '') $missing[] = 'sídlo a adresa pro stížnosti';
        if (filter_var($partner['email'] ?? '', FILTER_VALIDATE_EMAIL) === false) $missing[] = 'platný e-mail';
        $url = (string) ($partner['terms_url'] ?? '');
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https' ||
            parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            $missing[] = 'HTTPS adresa obchodních podmínek';
        }
        if ((int) ($partner['confirmed'] ?? 0) !== 1) $missing[] = 'ověření nákupu jménem zákazníka';
        return $missing;
    }

    public static function label(array $partner): string
    {
        return trim((string) ($partner['name'] ?? '')) !== '' ? $partner['name'] : 'Prodávající #' . $partner['id'] . ' (bez názvu)';
    }
}
