<?php
declare(strict_types=1);

namespace SimpleStore\Content;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;

/** Short storefront copy shared by all pages of one language. */
final class SiteCopyRepository
{
    public const DEFAULTS = [
        'hero_title' => 'Otestováno dobrodruhy.',
        'hero_subtitle' => 'Náš výběr toho nejlepšího vybavení na cesty. Lehké. Odolné. Osvědčené.',
        'catalog_home_title' => 'Náš výběr vybavení',
        'catalog_title' => 'Objevte vybavení',
        'catalog_home_intro' => 'Lehké, odolné a osvědčené vybavení na cesty.',
        'catalog_all_intro' => 'Poctivý výběr pro pohodlí na stezce i mimo ni.',
        'catalog_category_intro' => 'Vybavení na každou cestu. Vyberte si z nabídky níže.',
        'footer_intro' => 'Výběr nejlepšího turistického vybavení. Lehké. Odolné. Osvědčené.',
        'footer_closing' => 'Na další cestu připraveni.',
    ];

    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'shop_site_copy'
        ) === 1;
    }

    public function load(string $language): array
    {
        self::language($language);
        if (!$this->installed()) return self::DEFAULTS;
        $row = $this->db->queryFirstRow('SELECT copy_json FROM shop_site_copy WHERE language=%s', $language);
        $saved = $row === null ? null : json_decode((string) $row['copy_json'], true);
        if (!is_array($saved)) return self::DEFAULTS;
        $copy = self::DEFAULTS;
        foreach (self::DEFAULTS as $field => $fallback) {
            if (is_string($saved[$field] ?? null) && self::valid($saved[$field], 200)) {
                $copy[$field] = $saved[$field];
            }
        }
        return $copy;
    }

    public function save(string $language, array $input): void
    {
        self::language($language);
        if (!$this->installed()) throw new RuntimeException('Nejdřív aktualizuj SQL tabulky v sekci Databáze.');
        $copy = [];
        foreach (self::DEFAULTS as $field => $fallback) {
            $value = $input[$field] ?? null;
            if (!is_string($value)) throw new InvalidArgumentException('Vyplň všechny texty obchodu.');
            $value = trim($value);
            if (!self::valid($value, in_array($field, ['hero_title', 'catalog_home_title', 'catalog_title'], true) ? 90 : 200)) {
                throw new InvalidArgumentException('Texty musí být na jednom řádku a mít nejvýše 200 znaků (nadpisy 90).');
            }
            $copy[$field] = $value;
        }
        $this->db->query('INSERT INTO shop_site_copy (language, copy_json) VALUES (%s,%s)
            ON DUPLICATE KEY UPDATE copy_json=VALUES(copy_json)', $language,
            json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function valid(string $text, int $limit): bool
    {
        return preg_match('/^.{1,' . $limit . '}$/usD', $text) === 1 &&
            preg_match('/\p{C}/u', $text) === 0;
    }

    private static function language(string $language): void
    {
        if (preg_match('/^[a-z]{2}$/D', $language) !== 1) {
            throw new InvalidArgumentException('Vyber platný jazyk.');
        }
    }
}
