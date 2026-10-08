<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use Throwable;

/** Ordered, language-specific homepage picks keyed by permanent product identity. */
final class HomepageProductSelection
{
    public const LIMIT = 24;

    public function __construct(private MeekroDB $db, private array $languages = ['cs'])
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_homepage_selections') > 0;
    }

    /** Null means the original catalog behavior; an empty array is an intentional empty selection. */
    public function keys(string $language): ?array
    {
        $this->validateLanguage($language);
        if (!$this->installed()) return null;
        $row = $this->db->queryFirstRow(
            'SELECT product_keys_json FROM shop_homepage_selections WHERE language=%s', $language);
        return $row === null ? null : self::decode((string) $row['product_keys_json']);
    }

    public function products(string $language, array $keys, ?ProductStockRepository $stock = null,
        bool $includeHidden = false): array
    {
        $this->validateLanguage($language);
        if ($keys === []) return [];
        $keys = self::validateKeys($keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
        $rows = $this->db->query(
            'SELECT product_key, slug, name, brand, summary, details_json, category, subcategory,
                    price_czk, image_path, sizes, stock_status, published
             FROM shop_product_revisions WHERE language=%s AND active_product_key IS NOT NULL
             AND product_key IN (' . $placeholders . ')', $language, ...$keys);
        $byKey = [];
        foreach ($rows as $row) $byKey[$row['product_key']] = $row;
        $selected = [];
        foreach ($keys as $key) {
            if (!isset($byKey[$key])) {
                if ($includeHidden) $selected[] = ['product_key' => $key, 'name' => 'Odstraněný produkt',
                    'slug' => '', 'published' => 0];
            } elseif ($includeHidden || (int) $byKey[$key]['published'] === 1) {
                $selected[] = $byKey[$key];
            }
        }
        return $stock === null || $includeHidden ? $selected : $stock->decorate($selected);
    }

    public function change(string $language, string $operation, string $key = ''): void
    {
        $this->validateLanguage($language);
        if (!in_array($operation, ['add', 'remove', 'up', 'down', 'reset'], true) ||
            ($operation !== 'reset' && preg_match('/^[a-f0-9]{32}$/D', $key) !== 1)) {
            throw new InvalidArgumentException('Vyber platný produkt a akci.');
        }
        if (!$this->installed()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        $this->db->startTransaction();
        try {
            if ($operation === 'reset') {
                $this->db->query('DELETE FROM shop_homepage_selections WHERE language=%s', $language);
            } else {
                // The row also serializes the first edit made by two simultaneous administrators.
                $this->db->query('INSERT IGNORE INTO shop_homepage_selections (language, product_keys_json)
                    VALUES (%s, %s)', $language, '[]');
                $row = $this->db->queryFirstRow(
                    'SELECT product_keys_json FROM shop_homepage_selections WHERE language=%s FOR UPDATE', $language);
                $keys = self::decode((string) $row['product_keys_json']);
                $index = array_search($key, $keys, true);
                if ($operation === 'add') {
                    $published = $this->db->queryFirstField(
                        'SELECT COUNT(*) FROM shop_product_revisions WHERE product_key=%s AND language=%s
                         AND active_product_key IS NOT NULL AND published=1', $key, $language);
                    if ((int) $published !== 1) throw new InvalidArgumentException('Na úvodní stránku lze přidat jen zveřejněný produkt.');
                    if ($index === false) {
                        if (count($keys) >= self::LIMIT) throw new InvalidArgumentException('Na úvodní stránce může být nejvýše 24 produktů.');
                        $keys[] = $key;
                    }
                } elseif ($index === false) {
                    throw new InvalidArgumentException('Produkt už ve výběru není. Obnov stránku.');
                } elseif ($operation === 'remove') {
                    array_splice($keys, $index, 1);
                } else {
                    $neighbor = $operation === 'up' ? $index - 1 : $index + 1;
                    if (isset($keys[$neighbor])) [$keys[$index], $keys[$neighbor]] = [$keys[$neighbor], $keys[$index]];
                }
                $this->db->query('UPDATE shop_homepage_selections SET product_keys_json=%s,
                    updated_at=UTC_TIMESTAMP() WHERE language=%s',
                    json_encode($keys, JSON_THROW_ON_ERROR), $language);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function validateLanguage(string $language): void
    {
        if (!in_array($language, $this->languages, true)) throw new InvalidArgumentException('Neplatný jazyk.');
    }

    private static function decode(string $json): array
    {
        $keys = json_decode($json, true);
        if (!is_array($keys) || array_values($keys) !== $keys) {
            throw new RuntimeException('Výběr produktů na úvodní stránce je poškozený.');
        }
        return self::validateKeys($keys);
    }

    private static function validateKeys(array $keys): array
    {
        if (count($keys) > self::LIMIT) {
            throw new RuntimeException('Výběr produktů na úvodní stránce je poškozený.');
        }
        foreach ($keys as $key) {
            if (!is_string($key) || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
                throw new RuntimeException('Výběr produktů na úvodní stránce je poškozený.');
            }
        }
        if (count($keys) !== count(array_unique($keys))) {
            throw new RuntimeException('Výběr produktů na úvodní stránce je poškozený.');
        }
        return $keys;
    }
}
