<?php
declare(strict_types=1);

namespace SimpleStore\Media;

use InvalidArgumentException;

/** Resolve immutable upload directories to current names, without one query per photograph. */
final class MediaOwners
{
    public const TYPES = ['products' => 'product', 'pages' => 'page', 'posts' => 'post'];

    public function __construct(private \MeekroDB $db, private array $languages, private string $preferredLanguage) {}

    public static function scope(string $path): ?string
    {
        return preg_match('~^images/media/(products|pages|posts)/([a-f0-9]{32})/[^/]+$~D', $path, $match)
            ? $match[1] . ':' . $match[2] : null;
    }

    public static function parse(string $scope): array
    {
        if (!preg_match('/^(products|pages|posts):([a-f0-9]{32})$/D', $scope, $match)) {
            throw new InvalidArgumentException('Neplatná složka produktu nebo dokumentu.');
        }
        return ['group' => $match[1], 'key' => $match[2], 'type' => self::TYPES[$match[1]]];
    }

    public function names(array $files): array
    {
        $groups = []; $owners = [];
        foreach ($files as $file) {
            $scope = self::scope($file['path']);
            if ($scope === null) continue;
            $owner = self::parse($scope); $groups[$owner['group']][$owner['key']] = true;
        }
        foreach ($groups as $group => $keys) {
            foreach (array_chunk(array_keys($keys), 200) as $batch) {
                $keySlots = implode(',', array_fill(0, count($batch), '%s'));
                $languageSlots = implode(',', array_fill(0, count($this->languages), '%s'));
                if ($group === 'products') {
                    $sql = "SELECT product_key AS owner_key, name AS title, language, slug, image_path, published, revision_number
                        FROM shop_product_revisions WHERE active_product_key IS NOT NULL AND product_key IN ($keySlots)";
                    $parameters = $batch;
                } else {
                    $sql = "SELECT document_key AS owner_key, title, language, slug, '' AS image_path, published, revision_number
                        FROM shop_content_revisions WHERE active_document_key IS NOT NULL AND document_key IN ($keySlots) AND type=%s";
                    $parameters = array_merge($batch, [self::TYPES[$group]]);
                }
                $sql .= " AND language IN ($languageSlots) ORDER BY (language=%s) DESC, language ASC";
                foreach ($this->db->query($sql, ...array_merge($parameters, $this->languages, [$this->preferredLanguage])) as $row) {
                    $scope = $group . ':' . $row['owner_key'];
                    if (!isset($owners[$scope])) $owners[$scope] = $row + ['type' => self::TYPES[$group], 'key' => $row['owner_key']];
                }
            }
        }
        return $owners;
    }
}
