<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** One canonical undirected pair per language; product keys survive URL changes. */
final class ProductRelationRepository
{
    public const SCHEMA = 'CREATE TABLE IF NOT EXISTS shop_product_relations (
        language CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        product_a CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        product_b CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        note VARCHAR(500) NOT NULL DEFAULT \'\',
        PRIMARY KEY (language, product_a, product_b),
        KEY product_relation_reverse (language, product_b)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function __construct(private MeekroDB $db, private array $languages = ['cs']) {}

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_product_relations') > 0;
    }

    public function pair(string $language, string $a, string $b, string $note = ''): array
    {
        if (!in_array($language, $this->languages, true) || !preg_match('/^[a-f0-9]{32}$/D', $a) || !preg_match('/^[a-f0-9]{32}$/D', $b) || $a === $b) throw new InvalidArgumentException('Vyber dva různé existující produkty a platný jazyk.');
        if (preg_match('/^.{0,500}$/usD', $note) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $note)) throw new InvalidArgumentException('Poznámka může mít nejvýše 500 znaků na jednom řádku.');
        return strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
    }

    public function note(string $language, string $a, string $b): ?string
    {
        [$a, $b] = $this->pair($language, $a, $b);
        if (!$this->installed()) return null;
        return $this->db->queryFirstField('SELECT note FROM shop_product_relations WHERE language=%s AND product_a=%s AND product_b=%s', $language, $a, $b);
    }

    public function products(string $language, string $key, bool $includeHidden = false): array
    {
        if (!$this->installed()) return [];
        return $this->db->query('SELECT p.product_key, p.name, p.slug, p.image_path, p.price_czk, p.published, r.note
            FROM shop_product_relations r JOIN shop_product_revisions p
            ON p.product_key=IF(r.product_a=%s,r.product_b,r.product_a) AND p.language=r.language AND p.active_product_key IS NOT NULL
            WHERE r.language=%s AND (r.product_a=%s OR r.product_b=%s)' . ($includeHidden ? '' : ' AND p.published=1') . ' ORDER BY p.name, p.product_key', $key, $language, $key, $key);
    }

    /** Export each pair once, even when both ends are selected. */
    public function exportDocuments(array $rows): array
    {
        $selected = []; $documents = [];
        foreach ($rows as $row) $selected[$row['language']][$row['product_key']] = $row;
        if (!$this->installed()) return [];
        foreach ($selected as $language => $products) {
            $keys = array_keys($products);
            $holders = implode(',', array_fill(0, count($keys), '%s'));
            $pairs = $this->db->query('SELECT r.* FROM shop_product_relations r
                JOIN shop_product_revisions a ON a.product_key=r.product_a AND a.language=r.language AND a.active_product_key IS NOT NULL
                JOIN shop_product_revisions b ON b.product_key=r.product_b AND b.language=r.language AND b.active_product_key IS NOT NULL
                WHERE r.language=%s AND (r.product_a IN (' . $holders . ') OR r.product_b IN (' . $holders . ')) ORDER BY r.product_a,r.product_b', $language, ...array_merge($keys, $keys));
            foreach ($pairs as $pair) {
                $key = isset($products[$pair['product_a']]) ? $pair['product_a'] : $pair['product_b'];
                $target = $key === $pair['product_a'] ? $pair['product_b'] : $pair['product_a'];
                $id = $language . ':' . $key;
                if (!isset($documents[$id])) $documents[$id] = ['product_key' => $key, 'language' => $language, 'name' => $products[$key]['name'], 'related' => []];
                $documents[$id]['related'][] = ['product_key' => $target, 'note' => $pair['note']];
            }
        }
        return array_values($documents);
    }

    /** All requested edges for one source are atomic and preserve unrelated edges. */
    public function apply(string $language, string $key, array $links, bool $remove = false): void
    {
        foreach ($links as $link) $this->pair($language, $key, $link['product_key'], $link['note']);
        // Installation is restricted to authenticated administrator writes.
        $this->db->query(self::SCHEMA);
        $this->db->startTransaction();
        try {
            $keys = array_unique(array_merge([$key], array_column($links, 'product_key'))); sort($keys);
            $found = $this->db->query('SELECT product_key FROM shop_product_revisions WHERE language=%s AND active_product_key IS NOT NULL AND product_key IN (' . implode(',', array_fill(0, count($keys), '%s')) . ') ORDER BY product_key FOR UPDATE', $language, ...$keys);
            if (count($found) !== count($keys)) throw new InvalidArgumentException('Některý produkt již neexistuje v tomto jazyce.');
            foreach ($links as $link) {
                [$a, $b] = $this->pair($language, $key, $link['product_key'], $link['note']);
                $old = $this->db->queryFirstField('SELECT note FROM shop_product_relations WHERE language=%s AND product_a=%s AND product_b=%s FOR UPDATE', $language, $a, $b);
                $alreadyApplied = $remove ? $old === null : $old === $link['note'];
                if ($old !== $link['expected'] && !$alreadyApplied) throw new InvalidArgumentException('Propojení se mezitím změnilo. Obnov stránku nebo připrav import znovu.');
                if ($remove) $this->db->query('DELETE FROM shop_product_relations WHERE language=%s AND product_a=%s AND product_b=%s', $language, $a, $b);
                else $this->db->query('INSERT INTO shop_product_relations (language,product_a,product_b,note) VALUES (%s,%s,%s,%s) ON DUPLICATE KEY UPDATE note=VALUES(note)', $language, $a, $b, $link['note']);
            }
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }
}
