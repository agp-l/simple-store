<?php
declare(strict_types=1);

// Exercise the repository's write boundaries without touching a real database.
class MeekroDB
{
    public ?string $sourceType = null;
    public ?int $currentRevision = null;
    public ?array $currentPage = null;
    public array $inserted = [];
    public bool $rolledBack = false;
    public array $deletions = [];

    public function queryFirstField(string $sql, mixed ...$values): int
    {
        return 1;
    }

    public function query(string $sql, mixed ...$values): array
    {
        if (str_contains($sql, 'DELETE FROM')) {
            $this->deletions[] = [$sql, $values];
        }
        if (str_contains($sql, 'FROM catalog_categories')) {
            return [['path' => 'boty', 'title' => 'Boty', 'sort_order' => 1]];
        }
        return [];
    }

    public function startTransaction(): void {}
    public function rollback(): void { $this->rolledBack = true; }
    public function commit(): void {}
    public function insert(string $table, array $fields): void { $this->inserted[] = [$table, $fields]; }
    public function insertId(): int { return 1; }

    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        if (str_contains($sql, 'SELECT * FROM content_revisions') &&
            str_contains($sql, 'active_document_key IS NOT NULL')) {
            return $this->currentPage;
        }
        if (str_contains($sql, 'SELECT type FROM content_revisions')) {
            return $this->sourceType === null ? null : ['type' => $this->sourceType];
        }
        if (str_contains($sql, 'SELECT product_key FROM product_revisions WHERE product_key=%s')) {
            return $this->sourceType === null ? null : ['product_key' => $values[0]];
        }
        if (str_contains($sql, 'SELECT id, type, revision_number FROM content_revisions') &&
            $this->currentRevision !== null) {
            return ['id' => 1, 'type' => 'page', 'revision_number' => $this->currentRevision];
        }
        if (str_contains($sql, 'SELECT id, revision_number FROM product_revisions') &&
            $this->currentRevision !== null) {
            return ['id' => 1, 'revision_number' => $this->currentRevision];
        }
        return null;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Product\ProductRepository;

$key = str_repeat('a', 32);
$page = ['type' => 'page', 'language' => 'en', 'title' => 'About',
    'slug' => 'about', 'body' => 'Hello', 'published' => false];
$db = new MeekroDB();
$content = new ContentRepository($db, ['cs', 'en']);
try {
    $content->saveRevision($page, $key, 0);
    throw new RuntimeException('A translation of a missing document was accepted.');
} catch (InvalidArgumentException $expected) {
    if (!$db->rolledBack || $db->inserted !== []) {
        throw new RuntimeException('An invalid translation started writing a revision.');
    }
}

$db->sourceType = 'page';
$saved = $content->saveRevision($page, $key, 0);
if ($saved['revision_number'] !== 1 ||
    $db->inserted[0][1]['document_key'] !== $key ||
    $db->inserted[0][1]['language'] !== 'en') {
    throw new RuntimeException('The valid translation lost its document identity.');
}

$db->inserted = [];
$db->currentRevision = 2;
try {
    $content->saveRevision($page, $key, 1);
    throw new RuntimeException('A stale edit was accepted.');
} catch (RuntimeException $expected) {
    if ($db->inserted !== []) throw new RuntimeException('A stale edit inserted a revision.');
}

$db->currentPage = [
    'type' => 'page', 'language' => 'en', 'slug' => 'about', 'title' => 'About',
    'summary' => 'Intro', 'body' => 'Original body', 'published' => 1,
    'visible_in_menu' => 0, 'menu_order' => 0, 'revision_number' => 2,
];
$content->saveMenuPosition($key, 'en', 2, true, 3);
if ($db->inserted[0][1]['body'] !== 'Original body' ||
    $db->inserted[0][1]['visible_in_menu'] !== 1 ||
    $db->inserted[0][1]['menu_order'] !== 3) {
    throw new RuntimeException('Menu order must create a full page revision without rewriting its body.');
}

$db = new MeekroDB();
$products = new ProductRepository($db, ['cs'], new CategoryRepository($db));
$product = ['language' => 'cs', 'name' => 'Bota', 'slug' => 'bota',
    'category_path' => 'boty', 'price_czk' => '3990',
    'image_path' => 'images/batoh.webp', 'stock_status' => 'in_stock'];
try {
    $products->saveRevision($product, $key, 0);
    throw new RuntimeException('A translation of a missing product was accepted.');
} catch (InvalidArgumentException $expected) {
    if (!$db->rolledBack || $db->inserted !== []) {
        throw new RuntimeException('An invalid product translation inserted a revision.');
    }
}

$db = new MeekroDB();
$db->sourceType = 'page';
$db->currentRevision = 2;
$content = new ContentRepository($db, ['cs', 'en'], 2);
$saved = $content->saveRevision($page, $key, 2);
if ($saved['revision_number'] !== 3 || count($db->deletions) !== 1 ||
    !str_contains($db->deletions[0][0], 'active_document_key IS NULL') ||
    $db->deletions[0][1] !== [$key, 'en', 1]) {
    throw new RuntimeException('Content pruning must only remove inactive old revisions in the same language.');
}

$db = new MeekroDB();
$db->sourceType = 'product';
$db->currentRevision = 2;
$products = new ProductRepository($db, ['cs'], new CategoryRepository($db), 2);
$saved = $products->saveRevision($product, $key, 2);
if ($saved['revision_number'] !== 3 || count($db->deletions) !== 1 ||
    !str_contains($db->deletions[0][0], 'active_product_key IS NULL') ||
    $db->deletions[0][1] !== [$key, 'cs', 1]) {
    throw new RuntimeException('Product pruning must only remove inactive old revisions in the same language.');
}

echo "Revision guard tests passed.\n";
