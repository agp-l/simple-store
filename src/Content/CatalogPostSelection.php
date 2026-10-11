<?php
declare(strict_types=1);
namespace SimpleStore\Content;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Three ordered, language-specific article picks for the catalog's next-stop panel. */
final class CatalogPostSelection
{
    public const SCHEMA = 'CREATE TABLE IF NOT EXISTS shop_catalog_post_selections (
        language CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        document_keys_json TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    public function __construct(private MeekroDB $db, private array $languages = ['cs']) {}

    public function keys(string $language): array
    {
        $this->validateLanguage($language);
        $installed = (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_catalog_post_selections') > 0;
        $json = $installed ? $this->db->queryFirstField('SELECT document_keys_json FROM shop_catalog_post_selections WHERE language=%s', $language) : null;
        return $json === null ? $this->latest($language) : json_decode($json, true, 8, JSON_THROW_ON_ERROR);
    }

    private function latest(string $language): array
    {
        return array_column((new ContentRepository($this->db, $this->languages))->publishedPostsPage($language, 0, 3)['items'], 'document_key');
    }

    public function posts(string $language): array
    {
        $keys = $this->keys($language);
        if ($keys === []) return [];
        $rows = $this->db->query('SELECT document_key,slug,title,summary,body FROM shop_content_revisions
            WHERE language=%s AND type=%s AND published=1 AND active_document_key IS NOT NULL
            AND document_key IN (' . implode(',', array_fill(0, count($keys), '%s')) . ')', $language, 'post', ...$keys);
        $byKey = array_column($rows, null, 'document_key'); $posts = [];
        foreach ($keys as $key) if (isset($byKey[$key])) $posts[] = $byKey[$key];
        return $posts;
    }

    public function change(string $language, string $operation, string $key, array $expected): void
    {
        $this->validateLanguage($language);
        if (!in_array($operation, ['add', 'remove', 'up', 'down'], true) || !preg_match('/^[a-f0-9]{32}$/D', $key)) throw new InvalidArgumentException('Neplatný výběr článku.');
        $this->db->query(self::SCHEMA);
        $this->db->startTransaction();
        try {
            $defaults = $this->latest($language);
            $this->db->query('INSERT IGNORE INTO shop_catalog_post_selections (language,document_keys_json) VALUES (%s,%s)', $language, json_encode($defaults, JSON_THROW_ON_ERROR));
            $keys = json_decode($this->db->queryFirstField('SELECT document_keys_json FROM shop_catalog_post_selections WHERE language=%s FOR UPDATE', $language), true, 8, JSON_THROW_ON_ERROR);
            if ($keys !== $expected) throw new InvalidArgumentException('Výběr se mezitím změnil. Obnov stránku.');
            $index = array_search($key, $keys, true);
            if ($operation === 'remove') $keys = array_values(array_diff($keys, [$key]));
            elseif ($operation === 'add') {
                if (!$this->db->queryFirstField('SELECT document_key FROM shop_content_revisions WHERE document_key=%s AND language=%s AND type=%s AND published=1 AND active_document_key IS NOT NULL', $key, $language, 'post')) throw new InvalidArgumentException('Nejprve článek zveřejni.');
                if ($index === false) $keys[] = $key;
                if (count($keys) > 3) throw new InvalidArgumentException('V boxu mohou být nejvýše tři články. Nejprve některý odeber v boxu Kam dál? pod produkty.');
            } elseif ($index !== false) {
                $target = $index + ($operation === 'up' ? -1 : 1);
                if (isset($keys[$target])) [$keys[$index], $keys[$target]] = [$keys[$target], $keys[$index]];
            }
            $this->db->query('UPDATE shop_catalog_post_selections SET document_keys_json=%s WHERE language=%s', json_encode($keys, JSON_THROW_ON_ERROR), $language);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }

    private function validateLanguage(string $language): void
    {
        if (!in_array($language, $this->languages, true)) throw new InvalidArgumentException('Neplatný jazyk.');
    }
}
