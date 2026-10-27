<?php
declare(strict_types=1);

namespace SimpleStore\Content;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Blog layout is language-specific and independent of article revisions/imports. */
final class BlogPlacement
{
    public const EMPTY = ['featured' => null, 'pinned' => []];
    public const SCHEMA = 'CREATE TABLE IF NOT EXISTS shop_blog_placements (
        language CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        placement_json TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function __construct(private MeekroDB $db, private array $languages = ['cs']) {}

    public function load(string $language): array
    {
        $this->language($language);
        $installed = (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_blog_placements');
        if (!$installed) return self::EMPTY;
        $json = $this->db->queryFirstField('SELECT placement_json FROM shop_blog_placements WHERE language=%s', $language);
        return $json === null ? self::EMPTY : self::validate(json_decode($json, true, 8, JSON_THROW_ON_ERROR));
    }

    public static function validate(array $state): array
    {
        if (array_diff(array_keys($state), ['featured', 'pinned']) !== [] ||
            !array_key_exists('featured', $state) ||
            ($state['featured'] !== null && !self::key($state['featured'])) ||
            !isset($state['pinned']) || !is_array($state['pinned']) ||
            array_values($state['pinned']) !== $state['pinned'] || count($state['pinned']) > 1000) {
            throw new InvalidArgumentException('Neplatné připnutí článků.');
        }
        foreach ($state['pinned'] as $key) if (!self::key($key)) throw new InvalidArgumentException('Neplatný článek.');
        if (count(array_unique($state['pinned'])) !== count($state['pinned'])) throw new InvalidArgumentException('Článek je připnutý vícekrát.');
        return ['featured' => $state['featured'], 'pinned' => $state['pinned']];
    }

    public static function fingerprint(array $state): string
    {
        return hash('sha256', json_encode(self::validate($state), JSON_THROW_ON_ERROR));
    }

    public function change(string $language, string $operation, string $key, string $expected): void
    {
        $this->language($language);
        if (!self::key($key) || !in_array($operation, ['pin', 'unpin', 'feature', 'unfeature'], true) ||
            preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) throw new InvalidArgumentException('Neplatná úprava připnutí.');
        // Like catalog selections, setup is allowed only in an authenticated write path.
        $this->db->query(self::SCHEMA);
        $this->db->startTransaction();
        try {
            // A no-op upsert takes the exclusive row lock immediately, including the first write.
            $this->db->query('INSERT INTO shop_blog_placements (language,placement_json) VALUES (%s,%s)
                ON DUPLICATE KEY UPDATE language=VALUES(language)',
                $language, json_encode(self::EMPTY, JSON_THROW_ON_ERROR));
            $state = self::validate(json_decode($this->db->queryFirstField(
                'SELECT placement_json FROM shop_blog_placements WHERE language=%s FOR UPDATE', $language), true, 8, JSON_THROW_ON_ERROR));
            if (!hash_equals(self::fingerprint($state), $expected)) throw new InvalidArgumentException('Připnutí se mezitím změnilo. Obnov stránku a zkus to znovu.');
            if (in_array($operation, ['pin', 'feature'], true) && !$this->db->queryFirstField(
                'SELECT document_key FROM shop_content_revisions WHERE document_key=%s AND language=%s AND type=%s AND published=1 AND active_document_key IS NOT NULL',
                $key, $language, 'post')) throw new InvalidArgumentException('Nejprve článek zveřejni.');
            if ($operation === 'pin' && !in_array($key, $state['pinned'], true)) $state['pinned'][] = $key;
            if ($operation === 'unpin') $state['pinned'] = array_values(array_diff($state['pinned'], [$key]));
            if ($operation === 'feature') $state['featured'] = $key;
            if ($operation === 'unfeature' && $state['featured'] === $key) $state['featured'] = null;
            // A single locked row guarantees at most one featured article per language.
            $this->db->query('UPDATE shop_blog_placements SET placement_json=%s WHERE language=%s',
                json_encode(self::validate($state), JSON_THROW_ON_ERROR), $language);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private static function key(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[a-f0-9]{32}$/D', $key) === 1;
    }

    private function language(string $language): void
    {
        if (!in_array($language, $this->languages, true)) throw new InvalidArgumentException('Neplatný jazyk.');
    }
}
