<?php
declare(strict_types=1);

namespace SimpleStore\Navigation;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Category\CategoryPath;
use SimpleStore\Category\CategoryRepository;
use RuntimeException;

/** Database overrides for named menu placements; config/menus.php remains the default. */
final class MenuDefinitionRepository
{
    public function __construct(private MeekroDB $db, private array $defaults,
        private ?CategoryRepository $categories = null)
    {
    }

    public function installed(): bool
    {
        return (int) $this->db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', 'navigation_menus'
        ) > 0;
    }

    public function settings(string $language): array
    {
        $settings = $this->defaults;
        if (!$this->installed()) return $settings;
        foreach ($this->db->query(
            'SELECT slot, source, parent_path, include_blog, items_json
             FROM navigation_menus WHERE language=%s', $language
        ) as $row) {
            if ($this->legacyFooter($row)) {
                $settings['footer'] = $this->defaults['footer'];
                continue;
            }
            $title = $this->title($row['items_json'], $settings[$row['slot']]['title'] ?? 'Informace');
            $settings[$row['slot']] = match ($row['source']) {
                'categories' => ['source' => 'categories', 'parent' => $row['parent_path'], 'title' => $title,
                    'items' => $this->buildTree($this->decode($row['items_json']))],
                'content' => ['source' => 'content', 'include_blog' => (bool) $row['include_blog'], 'title' => $title,
                    'items' => $this->buildTree($this->decode($row['items_json']))],
                'manual' => ['source' => 'manual', 'title' => $title,
                    'items' => $this->buildTree($this->decode($row['items_json']))],
                default => $settings[$row['slot']] ?? ['source' => 'manual', 'items' => []],
            };
        }
        return $settings;
    }

    /** Flat entries with IDs make the administrator's item editor straightforward. */
    public function itemsForAdmin(string $language, string $slot): array
    {
        $row = $this->row($language, $slot);
        if ($row === null || $this->legacyFooter(['slot' => $slot] + $row)) {
            return $this->sorted($this->defaultItems($slot));
        }
        return $this->sorted($this->decode($row['items_json']));
    }

    public function saveSlot(string $language, string $slot, string $source, string $parent, bool $blog,
        ?string $title = null): void
    {
        $this->validateSlot($language, $slot);
        if (!in_array($source, ['categories', 'content', 'manual'], true) ||
            ($parent === '@context' && $slot !== 'category_tabs') ||
            ($parent !== '' && $parent !== '@context' && !CategoryPath::valid($parent))) {
            throw new InvalidArgumentException('Neplatný zdroj nebo kořen menu.');
        }
        $existing = $this->row($language, $slot);
        $title ??= $existing === null ? ($this->defaults[$slot]['title'] ?? 'Informace')
            : $this->title($existing['items_json'], $this->defaults[$slot]['title'] ?? 'Informace');
        $title = trim($title);
        if ($title === '' || preg_match('/^.{1,80}$/usD', $title) !== 1) {
            throw new InvalidArgumentException('Nadpis menu musí mít 1 až 80 znaků.');
        }
        $items = $existing === null ? $this->defaultItems($slot) : $this->decode($existing['items_json']);
        if ($slot === 'footer' && $source === 'manual' && $existing !== null &&
            $existing['source'] !== 'manual' && $items === []) {
            $items = $this->defaultItems($slot);
        }
        $json = $this->encode($items, $title);
        $this->db->query(
            'INSERT INTO navigation_menus (language, slot, source, parent_path, include_blog, items_json)
             VALUES (%s, %s, %s, %s, %i, %s)
             ON DUPLICATE KEY UPDATE source=VALUES(source), parent_path=VALUES(parent_path),
             include_blog=VALUES(include_blog), items_json=VALUES(items_json)',
            $language, $slot, $source, $source === 'categories' ? $parent : '',
            $source === 'content' ? (int) $blog : 0, $json
        );
    }

    public function saveItem(string $language, string $slot, ?string $id, array $input): string
    {
        $row = $this->row($language, $slot);
        if ($row === null || $this->legacyFooter(['slot' => $slot] + $row)) {
            $default = $this->defaults[$slot] ?? null;
            if ($default === null) throw new InvalidArgumentException('Neznámé umístění menu.');
            $this->saveSlot($language, $slot, $default['source'], $default['parent'] ?? '',
                (bool) ($default['include_blog'] ?? false));
            $row = $this->row($language, $slot);
        }
        if ($row === null) {
            throw new InvalidArgumentException('Menu neexistuje.');
        }
        $items = $this->decode($row['items_json']);
        $label = trim((string) ($input['label'] ?? ''));
        $type = (string) ($input['target_type'] ?? '');
        $target = trim((string) ($input['target'] ?? ''));
        $parent = (string) ($input['parent_id'] ?? '');
        $order = filter_var($input['sort_order'] ?? null, FILTER_VALIDATE_INT);
        if ($label === '' || preg_match('/^.{1,80}$/usD', $label) !== 1 ||
            !in_array($type, ['category', 'path', 'external'], true) ||
            ($target === '' && $type !== 'path') ||
            ($type !== 'external' && $target !== '' && !CategoryPath::valid($target)) ||
            ($type === 'external' && !self::validExternal($target)) ||
            $order === false || $order < 0 || $order > 65535) {
            throw new InvalidArgumentException('Zkontroluj název, cíl a pořadí odkazu.');
        }
        if ($type === 'category' && $this->categories !== null &&
            $this->categories->find($language, $target) === null) {
            throw new InvalidArgumentException('Cílová kategorie neexistuje nebo není zapnutá.');
        }
        if ($id !== null && !isset($items[$id])) {
            throw new InvalidArgumentException('Odkaz k úpravě neexistuje.');
        }
        if ($parent !== '' && !isset($items[$parent])) {
            throw new InvalidArgumentException('Nadřazený odkaz neexistuje.');
        }
        for ($ancestor = $parent; $ancestor !== ''; $ancestor = $items[$ancestor]['parent_id']) {
            if ($ancestor === $id) {
                throw new InvalidArgumentException('Odkaz nelze vložit pod sebe nebo svého potomka.');
            }
        }
        if ($id === null && count($items) >= 100) {
            throw new InvalidArgumentException('Jedno menu může mít nejvýše 100 odkazů.');
        }
        $id ??= bin2hex(random_bytes(8));
        $items[$id] = [
            'id' => $id, 'label' => $label, 'target_type' => $type,
            'target' => $target, 'parent_id' => $parent, 'sort_order' => $order,
        ];
        $this->storeItems($language, $slot, $items);
        return $id;
    }

    /** Removing a parent moves its children up one level. */
    public function removeItem(string $language, string $slot, string $id): void
    {
        $row = $this->row($language, $slot);
        if ($row === null || $this->legacyFooter(['slot' => $slot] + $row)) {
            $default = $this->defaults[$slot] ?? null;
            if ($default === null) throw new InvalidArgumentException('Neznámé umístění menu.');
            $this->saveSlot($language, $slot, $default['source'], $default['parent'] ?? '',
                (bool) ($default['include_blog'] ?? false));
            $row = $this->row($language, $slot);
        }
        if ($row === null) {
            throw new InvalidArgumentException('Menu neexistuje.');
        }
        $items = $this->decode($row['items_json']);
        if (!isset($items[$id])) {
            throw new InvalidArgumentException('Odkaz neexistuje.');
        }
        $parent = $items[$id]['parent_id'];
        unset($items[$id]);
        foreach ($items as &$item) {
            if ($item['parent_id'] === $id) $item['parent_id'] = $parent;
        }
        unset($item);
        $this->storeItems($language, $slot, $items);
    }

    private function row(string $language, string $slot): ?array
    {
        $this->validateSlot($language, $slot);
        if (!$this->installed()) return null;
        return $this->db->queryFirstRow(
            'SELECT source, parent_path, items_json FROM navigation_menus WHERE language=%s AND slot=%s LIMIT 1',
            $language, $slot
        );
    }

    private function storeItems(string $language, string $slot, array $items): void
    {
        $row = $this->row($language, $slot);
        $this->db->query(
            'UPDATE navigation_menus SET items_json=%s WHERE language=%s AND slot=%s',
            $this->encode($items, $this->title($row['items_json'], $this->defaults[$slot]['title'] ?? 'Informace')),
            $language, $slot
        );
    }

    private function title(string $json, string $fallback): string
    {
        $data = json_decode($json, true);
        return is_array($data) && is_string($data['title'] ?? null) ? $data['title'] : $fallback;
    }

    /** The old footer default listed categories in two fixed columns. */
    private function legacyFooter(array $row): bool
    {
        return ($row['slot'] ?? '') === 'footer' && ($row['source'] ?? '') === 'categories' &&
            ($row['parent_path'] ?? '') === '' &&
            $this->decode($row['items_json'] ?? '[]') === [];
    }

    private function encode(array $items, string $title): string
    {
        return json_encode(['title' => $title, 'items' => array_values($items)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function defaultItems(string $slot): array
    {
        $items = [];
        $walk = static function (array $links, string $parent) use (&$walk, &$items, $slot): void {
            foreach ($links as $link) {
                $id = substr(hash('sha256', $slot . ':' . count($items)), 0, 16);
                $type = isset($link['category']) ? 'category' : (isset($link['external']) ? 'external' : 'path');
                $items[$id] = ['id' => $id, 'parent_id' => $parent, 'label' => $link['label'],
                    'target_type' => $type, 'target' => $link[$type === 'category' ? 'category' :
                        ($type === 'external' ? 'external' : 'path')],
                    'sort_order' => count($items) * 10];
                $walk($link['children'] ?? [], $id);
            }
        };
        $walk($this->defaults[$slot]['items'] ?? [], '');
        return $items;
    }

    public static function validExternal(string $url): bool
    {
        if (strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        if (str_starts_with($url, 'mailto:')) {
            return filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false;
        }
        return str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function decode(string $json): array
    {
        $rows = json_decode($json, true);
        if (isset($rows['items'])) $rows = $rows['items'];
        if (!is_array($rows)) throw new RuntimeException('Neplatná data menu v databázi.');
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) ||
                preg_match('/^[a-f0-9]{16}$/D', $row['id']) !== 1 ||
                !is_string($row['parent_id'] ?? null) || !is_string($row['label'] ?? null) ||
                !in_array($row['target_type'] ?? null, ['category', 'path', 'external'], true) ||
                !is_string($row['target'] ?? null) || !is_int($row['sort_order'] ?? null) ||
                isset($items[$row['id']])) {
                throw new RuntimeException('Neplatná položka menu v databázi.');
            }
            $items[$row['id']] = $row;
        }
        foreach ($items as $item) {
            $parent = $item['parent_id'];
            for ($depth = 0; $parent !== ''; $depth++) {
                if ($depth >= count($items) || !isset($items[$parent])) {
                    throw new RuntimeException('Neplatná struktura menu v databázi.');
                }
                $parent = $items[$parent]['parent_id'];
            }
        }
        return $items;
    }

    private function sorted(array $items): array
    {
        $list = array_values($items);
        usort($list, static fn (array $a, array $b): int =>
            ((int) $a['sort_order'] <=> (int) $b['sort_order']) ?: strcmp($a['label'], $b['label']));
        $ordered = [];
        $visit = static function (string $parent, int $depth) use (&$visit, &$ordered, $list): void {
            foreach ($list as $row) {
                if ($row['parent_id'] !== $parent) continue;
                $row['depth'] = $depth;
                $ordered[] = $row;
                $visit($row['id'], $depth + 1);
            }
        };
        $visit('', 0);
        return $ordered;
    }

    private function buildTree(array $items): array
    {
        $ordered = $this->sorted($items);
        $build = static function (string $parent) use (&$build, $ordered): array {
            $links = [];
            foreach ($ordered as $row) {
                if ($row['parent_id'] !== $parent) continue;
                $link = ['label' => $row['label'], 'children' => $build($row['id'])];
                $link[$row['target_type']] = $row['target'];
                $links[] = $link;
            }
            return $links;
        };
        return $build('');
    }

    private function validateSlot(string $language, string $slot): void
    {
        if (preg_match('/^[a-z]{2}$/D', $language) !== 1 ||
            preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $slot) !== 1) {
            throw new InvalidArgumentException('Neplatný jazyk nebo umístění menu.');
        }
    }
}
