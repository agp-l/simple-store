<?php
declare(strict_types=1);

namespace SimpleStore\Category;

use MeekroDB;

/** Read a small tree of paths from one table; no parent IDs or joins. */
final class CategoryRepository
{
    private array $cache = [];
    private ?bool $installed = null;

    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        if ($this->installed === null) {
            $this->installed = (int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'catalog_categories'
            ) > 0;
        }
        return $this->installed;
    }

    public function all(string $language): array
    {
        if (!isset($this->cache[$language])) {
            $rows = $this->installed() ? $this->db->query(
                'SELECT path, title, sort_order FROM catalog_categories
                 WHERE language=%s AND enabled=1 ORDER BY sort_order, title', $language
            ) : [];
            $this->cache[$language] = [];
            foreach ($rows as $row) {
                $this->cache[$language][$row['path']] = $row;
            }
        }
        return $this->cache[$language];
    }

    public function find(string $language, string $path): ?array
    {
        if (!CategoryPath::valid($path)) {
            return null;
        }
        $all = $this->all($language);
        if (!isset($all[$path])) {
            return null;
        }
        // Do not expose an orphaned category whose parent was disabled or removed.
        $parent = CategoryPath::parent($path);
        while ($parent !== '') {
            if (!isset($all[$parent])) {
                return null;
            }
            $parent = CategoryPath::parent($parent);
        }
        return $all[$path];
    }

    public function children(string $language, string $parent = ''): array
    {
        if ($parent !== '' && $this->find($language, $parent) === null) {
            return [];
        }
        $children = [];
        foreach ($this->all($language) as $row) {
            if (CategoryPath::parent($row['path']) === $parent) {
                $children[] = $row;
            }
        }
        return $children;
    }

    public function tree(string $language, string $parent = ''): array
    {
        $tree = [];
        foreach ($this->children($language, $parent) as $row) {
            $row['children'] = $this->tree($language, $row['path']);
            $tree[] = $row;
        }
        return $tree;
    }

    public function trail(string $language, string $path): array
    {
        if ($this->find($language, $path) === null) {
            return [];
        }
        $trail = [];
        do {
            array_unshift($trail, $this->all($language)[$path]);
            $path = CategoryPath::parent($path);
        } while ($path !== '');
        return $trail;
    }
}
