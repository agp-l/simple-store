<?php
declare(strict_types=1);
namespace SimpleStore\Product;
use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Category\CategoryPath;
use Throwable;

/** Recommendations belong to a specific category, not implicitly to its parents. */
final class CategoryProductSelection
{
    public const SCHEMA = "CREATE TABLE IF NOT EXISTS shop_category_product_selections (
        language CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        category_path VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        product_keys_json LONGTEXT NOT NULL,
        PRIMARY KEY (language, category_path)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    public function __construct(private MeekroDB $db, private array $languages = ['cs']) {}

    public function keys(string $language, string $path): array
    {
        $this->validate($language, $path);
        if (!(int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_category_product_selections')) return [];
        return $this->read($language, $path);
    }

    public function change(string $language, string $path, string $operation, string $key = '', array $order = [], array $expected = []): void
    {
        $this->validate($language, $path);
        if (!in_array($operation, ['add', 'remove', 'reorder'], true)) throw new InvalidArgumentException('Neplatná akce.');
        if ($operation !== 'reorder' && !preg_match('/^[a-f0-9]{32}$/D', $key)) throw new InvalidArgumentException('Neplatný produkt.');
        // Additive installation is restricted to authenticated administrator writes.
        $this->db->query(self::SCHEMA);
        $this->db->startTransaction();
        try {
            $this->db->query('INSERT IGNORE INTO shop_category_product_selections (language, category_path, product_keys_json) VALUES (%s,%s,%s)', $language, $path, '[]');
            $keys = $this->read($language, $path, true);
            if ($operation === 'reorder') {
                $keys = SelectionOrder::validate($keys, $order, $expected);
            } elseif ($operation === 'remove') {
                $keys = array_values(array_diff($keys, [$key]));
            } else {
                $row = $this->db->queryFirstRow('SELECT category, subcategory FROM shop_product_revisions WHERE product_key=%s AND language=%s AND active_product_key IS NOT NULL AND published=1', $key, $language);
                $productPath = $row === null ? '' : CategoryPath::fromProduct($row);
                if ($productPath !== $path && !str_starts_with($productPath, $path . '/')) throw new InvalidArgumentException('Vyber zveřejněný produkt z této kategorie nebo její podkategorie.');
                if (!in_array($key, $keys, true)) $keys[] = $key;
                if (count($keys) > HomepageProductSelection::LIMIT) throw new InvalidArgumentException('V jedné kategorii lze vybrat nejvýše 24 tipů.');
            }
            $this->db->query('UPDATE shop_category_product_selections SET product_keys_json=%s WHERE language=%s AND category_path=%s', json_encode($keys, JSON_THROW_ON_ERROR), $language, $path);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }

    private function read(string $language, string $path, bool $lock = false): array
    {
        $json = $this->db->queryFirstField('SELECT product_keys_json FROM shop_category_product_selections WHERE language=%s AND category_path=%s' . ($lock ? ' FOR UPDATE' : ''), $language, $path);
        $keys = $json === null ? [] : json_decode((string) $json, true, 32, JSON_THROW_ON_ERROR);
        return SelectionOrder::validate($keys, $keys, $keys);
    }

    private function validate(string $language, string $path): void
    {
        if (!in_array($language, $this->languages, true) || !CategoryPath::valid($path) || strlen($path) > 500) throw new InvalidArgumentException('Neplatný jazyk nebo kategorie.');
    }
}
