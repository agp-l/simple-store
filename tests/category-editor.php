<?php
declare(strict_types=1);

class MeekroDB
{
    public array $categories = [
        'cs:spani' => ['path' => 'spani', 'title' => 'Spaní', 'sort_order' => 1, 'enabled' => 1],
        'cs:spani/spacaky' => ['path' => 'spani/spacaky', 'title' => 'Spacáky', 'sort_order' => 2, 'enabled' => 1],
    ];

    private array $saved = [];
    public function startTransaction(): void { $this->saved = $this->categories; }
    public function commit(): void {}
    public function rollback(): void { $this->categories = $this->saved; }

    public function queryFirstField(string $sql, mixed ...$args): int { return 1; }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, 'SELECT path, title, sort_order, enabled')) {
            return array_values($this->categories);
        }
        if (str_contains($sql, 'SELECT path, title, sort_order FROM shop_catalog_categories')) {
            return array_values(array_filter($this->categories,
                static fn (array $row): bool => $row['enabled'] === 1));
        }
        if (str_contains($sql, 'UPDATE shop_catalog_categories SET sort_order')) {
            $this->categories[$args[1] . ':' . $args[2]]['sort_order'] = $args[0];
            return [];
        }
        if (str_contains($sql, 'UPDATE shop_catalog_categories')) {
            $this->categories[$args[3] . ':' . $args[4]] = [
                'path' => $args[4], 'title' => $args[0], 'sort_order' => $args[1], 'enabled' => $args[2],
            ];
        }
        return [];
    }

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        return $this->categories[$args[0] . ':' . $args[1]] ?? null;
    }

    public function insert(string $table, array $fields): void
    {
        $this->categories[$fields['language'] . ':' . $fields['path']] = $fields;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Category\CategoryRepository;

$db = new MeekroDB();
$categories = new CategoryRepository($db);
$path = $categories->create('cs', 'spani', '', 'Zimní spacáky', 1);
if ($path !== 'spani/zimni-spacaky' || $categories->find('cs', $path) === null ||
    array_column($categories->allForAdmin('cs'), 'path') !== ['spani', $path, 'spani/spacaky']) {
    throw new RuntimeException('Creating a subcategory must preserve the parent and sibling order.');
}
$categories->update('cs', $path, 'Zimní výbava', 3, false);
if ($categories->find('cs', $path) !== null ||
    $categories->findForAdmin('cs', $path)['title'] !== 'Zimní výbava') {
    throw new RuntimeException('Hidden categories must leave public menus but remain editable.');
}
try {
    $categories->create('cs', 'spani', 'spacaky', 'Druhé spacáky', 2);
    throw new RuntimeException('Duplicate category path was accepted.');
} catch (InvalidArgumentException $expected) {
}

echo "Category editor tests passed.\n";

$rows = $categories->allForAdmin('cs');
$version = CategoryRepository::orderVersion($rows);
$positions = array_column($rows, 'sort_order', 'path');
$positions['spani/spacaky'] = 0;
$before = $db->categories;
$categories->saveOrder('cs', $positions, $version);
if ($db->categories['cs:spani/spacaky']['sort_order'] !== 0 ||
    array_keys($db->categories) !== array_keys($before) ||
    $db->categories['cs:' . $path]['enabled'] !== 0) throw new RuntimeException('Reordering changed paths or visibility.');
$rejectOrder = static function (array $values, string $version) use ($categories, $db): void {
    $before = $db->categories;
    try { $categories->saveOrder('cs', $values, $version); }
    catch (InvalidArgumentException $expected) {
        if ($db->categories !== $before) throw new RuntimeException('Rejected ordering changed the tree.');
        return;
    }
    throw new RuntimeException('Invalid category ordering accepted.');
};
$rejectOrder($positions, $version); // stale page
$version = CategoryRepository::orderVersion($categories->allForAdmin('cs'));
$bad = $positions; unset($bad['spani/spacaky']); $rejectOrder($bad, $version);
$bad = $positions; $bad['unknown'] = 1; $rejectOrder($bad, $version);
foreach (['1.5', '-2', '65536', ['not a number']] as $value) {
    $bad = $positions; $bad['spani'] = $value; $rejectOrder($bad, $version);
}
echo "Category reorder: whole-tree validation, stable paths and stale edits OK\n";
