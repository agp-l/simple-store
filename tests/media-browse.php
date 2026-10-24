<?php
declare(strict_types=1);
class MeekroDB {
    public array $queries = [];
    public array $rows = [];
    public function query(string $sql, mixed ...$parameters): array { $this->queries[] = [$sql, $parameters]; return $this->rows; }
}
require dirname(__DIR__) . '/src/bootstrap.php';
use SimpleStore\Media\MediaBrowse;
use SimpleStore\Media\MediaOwners;
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call): void { try { $call(); } catch (InvalidArgumentException) { return; } throw new RuntimeException('Invalid media folder selection was accepted.'); };
$first = str_repeat('a', 32); $second = str_repeat('b', 32); $orphan = str_repeat('c', 32);
$files = [];
foreach ([[$first, 'front', 30], [$first, 'back', 20], [$second, 'detail', 10], [$orphan, 'old', 5]] as [$key, $label, $created]) {
    $path = 'images/media/products/' . $key . '/' . $label . '--' . str_repeat('d', 24) . '.webp';
    $files[] = ['path' => $path, 'label' => $label, 'thumb' => $path, 'card' => $path, 'created' => $created, 'bytes' => 1000, 'group' => 'products', 'folder' => ''];
}
$owners = ['products:' . $first => ['title' => 'Červený batoh', 'image_path' => $files[1]['path']], 'products:' . $second => ['title' => 'Modrý stan', 'image_path' => '']];
$state = ['folders' => [], 'files' => []];
$options = MediaBrowse::options(['folder' => 'products']);
$result = MediaBrowse::listing($files, $state, $owners, $options);
$check($result['total'] === 3 && $result['photoTotal'] === 4 && $result['files'] === [], 'Product view must start with directories, not flat photos.');
$check($result['directories'][0]['count'] === 2 && $result['directories'][0]['thumb'] === $files[1]['thumb'], 'Folder count or main-photo cover is wrong.');
$check($result['directories'][2]['owner'] === null, 'Deleted products must retain their upload directories.');
$search = MediaBrowse::listing($files, $state, $owners, MediaBrowse::options(['folder' => 'products', 'q' => 'CERVENY']));
$check($search['total'] === 1 && $search['directories'][0]['count'] === 2, 'Search must find Czech titles without case or diacritics.');
$search = MediaBrowse::listing($files, $state, $owners, MediaBrowse::options(['folder' => 'products', 'q' => 'back']));
$check($search['total'] === 1 && $search['directories'][0]['count'] === 2, 'Filename search must open complete product folders.');
$open = MediaBrowse::listing($files, $state, $owners, MediaBrowse::options(['folder' => 'products', 'owner' => $first, 'sort' => 'name']));
$check(array_column($open['files'], 'label') === ['back', 'front'] && $open['active']['count'] === 2, 'Opening product folder mixed owners or lost sorting.');
$emptySearch = MediaBrowse::listing($files, $state, $owners, MediaBrowse::options(['folder' => 'products', 'owner' => $first, 'q' => 'unknown']));
$check($emptySearch['total'] === 0 && $emptySearch['active']['count'] === 2, 'Empty search must retain folder breadcrumb and owner actions.');
$flat = MediaBrowse::listing($files, $state, $owners, MediaBrowse::options(['folder' => 'products', 'view' => 'photos', 'offset' => '96']), 2);
$check($flat['total'] === 4 && $flat['offset'] === 2 && count($flat['files']) === 2, 'Flat view pagination must clamp old offsets.');
$check(MediaBrowse::selectedPaths($files, ['products:' . $first]) === array_column(array_slice($files, 0, 2), 'path'), 'Folder export must include only the selected product.');
$reject(fn () => MediaBrowse::selectedPaths($files, ['products:../escape']));
$reject(fn () => MediaBrowse::selectedPaths($files, ['products:' . str_repeat('e', 32)]));
foreach ([['folder' => 'all', 'owner' => $first], ['folder' => 'products', 'owner' => '../bad'], ['sort' => 'sql'], ['view' => []], ['offset' => '-1']] as $bad) $reject(fn () => MediaBrowse::options($bad));
// Displaying titles must use bounded current-revision queries; translations share one folder.
$db = new MeekroDB(); $db->rows = [['owner_key' => $first, 'title' => 'Červený batoh', 'language' => 'cs'], ['owner_key' => $first, 'title' => 'Red backpack', 'language' => 'en']];
$names = (new MediaOwners($db, ['cs', 'en'], 'cs'))->names($files);
$check(count($db->queries) === 1 && count($names) === 1 && $names['products:' . $first]['title'] === 'Červený batoh', 'Titles caused per-image queries or duplicate translation folders.');
$check(str_contains($db->queries[0][0], 'active_product_key IS NOT NULL') && str_contains($db->queries[0][0], 'language IN (%s,%s)') && end($db->queries[0][1]) === 'cs', 'Title query lost active/language scope.');
echo "Media folders: named product groups, search, sorting, pagination, orphan retention and bounded queries passed.\n";
