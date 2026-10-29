<?php
declare(strict_types=1);

namespace SimpleStore\Media;

use InvalidArgumentException;
use MeekroDB;

/** A file may be deleted only when no current product or document refers to it. */
final class MediaDeletion
{
    public function __construct(private MeekroDB $db, private MediaLibrary $library) {}

    public function withDeletionState(string $type, string $key, array $files): array
    {
        MediaPath::directory($type, $key);
        if ($files === []) return [];
        $used = $this->activeTokens($key);
        foreach ($files as &$file) {
            if (!MediaPath::isManaged($file['path']) || dirname($file['path']) !== MediaPath::directory($type, $key)) {
                $file['can_delete'] = false;
                $file['delete_reason'] = 'Soubor patří do jiné nebo společné knihovny. Z produktu ho lze odebrat.';
                continue;
            }
            $token = self::token($type, $key, $file['path']);
            $file['can_delete'] = ($file['uses'] ?? []) === [] && !isset($used[$token]);
            if (!$file['can_delete']) $file['delete_reason'] = 'Fotografie se stále používá v produktu, variantě, jiném obsahu nebo objednávce.';
            if (!$file['can_delete'] && ($file['uses'] ?? []) === []) $file['used_elsewhere'] = true;
        }
        unset($file);
        return $files;
    }

    public function deleteUnused(string $type, string $key, string $path): void
    {
        $token = self::token($type, $key, $path);
        if (isset($this->activeTokens($key)[$token])) {
            throw new InvalidArgumentException(
                'Fotografie se používá v produktu, variantě, jiném jazyce, dalším obsahu nebo objednávce. Nejdřív ji odtud odeber.'
            );
        }
        $this->library->deleteManaged($type, $key, $path);
    }

    private static function token(string $type, string $key, string $path): string
    {
        if (!MediaPath::isManaged($path) || dirname($path) !== MediaPath::directory($type, $key) ||
            preg_match('/--([a-f0-9]{24})\.(?:webp|jpg|png)$/D', $path, $match) !== 1) {
            throw new InvalidArgumentException('Fotografie nepatří do této knihovny.');
        }
        return $match[1];
    }

    /** Search only current revisions; old revisions and copied URLs are covered by the deletion warning. */
    private function activeTokens(string $key): array
    {
        $needle = '%' . $key . '%';
        $products = $this->db->query(
            'SELECT image_path, details_json, description, summary FROM shop_product_revisions
             WHERE active_product_key IS NOT NULL AND
             (image_path LIKE %s OR details_json LIKE %s OR description LIKE %s OR summary LIKE %s)',
            $needle, $needle, $needle, $needle
        );
        $documents = $this->db->query(
            'SELECT body, summary FROM shop_content_revisions WHERE active_document_key IS NOT NULL
             AND (body LIKE %s OR summary LIKE %s)', $needle, $needle
        );
        $variants = (new \SimpleStore\Product\ProductVariantRepository($this->db))->installed()
            ? $this->db->query('SELECT image_path FROM shop_product_variants WHERE image_path LIKE %s', $needle) : [];
        $orders = (int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'shop_orders') === 1
            ? $this->db->query('SELECT items_json FROM shop_orders WHERE items_json LIKE %s', $needle) : [];
        $tokens = [];
        foreach (array_merge($products, $documents, $variants, $orders) as $row) {
            foreach ($row as $value) {
                if (!is_string($value)) continue;
                preg_match_all('/--([a-f0-9]{24})(?:-(?:card|thumb))?\.(?:webp|jpg|png)/', $value, $matches);
                foreach ($matches[1] as $token) $tokens[$token] = true;
            }
        }
        return $tokens;
    }
}
