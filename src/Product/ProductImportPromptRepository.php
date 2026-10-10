<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;

/** A store-wide editable prompt; code updates never replace the administrator's saved copy. */
final class ProductImportPromptRepository
{
    public const LIMIT = 131072;
    private const CREATE_TABLE = 'CREATE TABLE IF NOT EXISTS shop_product_import_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        prompt_text LONGTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function __construct(private MeekroDB $db) {}

    public static function defaultText(): string
    {
        $text = file_get_contents(__DIR__ . '/import-prompt.txt');
        if (!is_string($text)) throw new RuntimeException('Výchozí zadání pro AI nelze načíst.');
        return $text;
    }

    public function load(): string
    {
        $exists = (int) $this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_product_import_settings') === 1;
        if (!$exists) return self::defaultText();
        $text = $this->db->queryFirstField('SELECT prompt_text FROM shop_product_import_settings WHERE id=%i', 1);
        return is_string($text) ? $text : self::defaultText();
    }

    public function save(string $text): void
    {
        if (trim($text) === '' || strlen($text) > self::LIMIT || preg_match('//u', $text) !== 1 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) {
            throw new InvalidArgumentException('Zadání nesmí být prázdné, musí být platný text a může mít nejvýše 128 KiB.');
        }
        // Only this module's additive table is created, on the first authorized save.
        $this->db->query(self::CREATE_TABLE);
        $this->db->query('INSERT INTO shop_product_import_settings (id, prompt_text) VALUES (%i, %s)
            ON DUPLICATE KEY UPDATE prompt_text=VALUES(prompt_text)', 1, $text);
    }
}
