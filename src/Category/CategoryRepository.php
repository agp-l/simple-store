<?php
declare(strict_types=1);

namespace SimpleStore\Category;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Navigation\Slugger;

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

    /** Include hidden categories so an editor can turn them back on. */
    public function allForAdmin(string $language): array
    {
        $rows = $this->db->query(
            'SELECT path, title, sort_order, enabled FROM catalog_categories WHERE language=%s', $language
        );
        $children = [];
        foreach ($rows as $row) {
            $children[CategoryPath::parent($row['path'])][] = $row;
        }
        $ordered = [];
        $visit = static function (string $parent, int $depth) use (&$visit, &$ordered, $children): void {
            $siblings = $children[$parent] ?? [];
            usort($siblings, static fn (array $a, array $b): int =>
                ((int) $a['sort_order'] <=> (int) $b['sort_order']) ?: strcmp($a['title'], $b['title']));
            foreach ($siblings as $row) {
                $row['depth'] = $depth;
                $ordered[] = $row;
                $visit($row['path'], $depth + 1);
            }
        };
        $visit('', 0);
        return $ordered;
    }

    public function findForAdmin(string $language, string $path): ?array
    {
        if (!CategoryPath::valid($path)) return null;
        return $this->db->queryFirstRow(
            'SELECT path, title, sort_order, enabled FROM catalog_categories
             WHERE language=%s AND path=%s LIMIT 1', $language, $path
        );
    }

    public function create(string $language, string $parent, string $slug, string $title, int $order): string
    {
        $title = trim($title);
        $slug = trim($slug) === '' ? Slugger::fromTitle($title) : trim($slug);
        $path = $parent === '' ? $slug : $parent . '/' . $slug;
        $this->validate($language, $path, $title, $order);
        if (str_contains($slug, '/') || ($parent !== '' && $this->find($language, $parent) === null)) {
            throw new InvalidArgumentException('Vyber existující zapnutou nadřazenou kategorii.');
        }
        if ($this->findForAdmin($language, $path) !== null) {
            throw new InvalidArgumentException('Tato adresa kategorie se už používá.');
        }
        $this->db->insert('catalog_categories', [
            'language' => $language, 'path' => $path, 'title' => $title,
            'sort_order' => $order, 'enabled' => 1,
        ]);
        unset($this->cache[$language]);
        return $path;
    }

    public function update(string $language, string $path, string $title, int $order, bool $enabled): void
    {
        $title = trim($title);
        $this->validate($language, $path, $title, $order);
        if ($this->findForAdmin($language, $path) === null) {
            throw new InvalidArgumentException('Kategorie neexistuje.');
        }
        $this->db->query(
            'UPDATE catalog_categories SET title=%s, sort_order=%i, enabled=%i
             WHERE language=%s AND path=%s',
            $title, $order, (int) $enabled, $language, $path
        );
        unset($this->cache[$language]);
    }

    private function validate(string $language, string $path, string $title, int $order): void
    {
        if (preg_match('/^[a-z]{2}$/D', $language) !== 1 || !CategoryPath::valid($path) ||
            $title === '' || preg_match('/^.{1,160}$/usD', $title) !== 1 ||
            $order < 0 || $order > 65535) {
            throw new InvalidArgumentException('Zkontroluj název, adresu a pořadí kategorie.');
        }
    }
}
