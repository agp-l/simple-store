<?php
declare(strict_types=1);

// Exercise the deletion boundary without connecting to a production database.
class MeekroDB
{
    public ?array $current = ['revision_number' => 3];
    public array $lookups = [];
    public array $deletions = [];
    public array $listings = [];
    public array $listingRows = [];
    public bool $failDelete = false;
    public int $begun = 0;
    public int $committed = 0;
    public int $rolledBack = 0;

    public function startTransaction(): void { $this->begun++; }
    public function commit(): void { $this->committed++; }
    public function rollback(): void { $this->rolledBack++; }

    public function queryFirstRow(string $sql, mixed ...$values): ?array
    {
        $this->lookups[] = [$sql, $values];
        return $this->current;
    }

    public function query(string $sql, mixed ...$values): array
    {
        if (str_contains($sql, 'SELECT product_key, language, slug')) {
            $this->listings[] = [$sql, $values];
            return $this->listingRows;
        }
        if ($this->failDelete) throw new RuntimeException('Database write failed.');
        $this->deletions[] = [$sql, $values];
        return [];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Product\ProductRepository;

$db = new MeekroDB();
$repository = new ProductRepository($db, ['cs', 'en']);
$key = str_repeat('a', 32);
$repository->deleteProduct($key, 'cs', 3);
if ($db->begun !== 1 || $db->committed !== 1 || $db->rolledBack !== 0 ||
    count($db->lookups) !== 1 || !str_contains($db->lookups[0][0], 'FOR UPDATE') ||
    $db->lookups[0][1] !== [$key, 'cs'] || count($db->deletions) !== 1 ||
    !str_contains($db->deletions[0][0], 'DELETE FROM shop_product_revisions') ||
    $db->deletions[0][1] !== [$key, 'cs']) {
    throw new RuntimeException('Deleting a product must lock the current revision and remove only that language.');
}

foreach ([['bad-key', 'cs', 3], [$key, 'fr', 3], [$key, 'cs', 0]] as $args) {
    try {
        $repository->deleteProduct(...$args);
        throw new RuntimeException('Invalid deletion arguments were accepted.');
    } catch (InvalidArgumentException $expected) {
        if ($db->begun !== 1 || count($db->deletions) !== 1) {
            throw new RuntimeException('Invalid deletion arguments reached the database.');
        }
    }
}

$db->current = ['revision_number' => 4];
try {
    $repository->deleteProduct($key, 'cs', 3);
    throw new RuntimeException('A stale delete was accepted.');
} catch (RuntimeException $expected) {
    if (count($db->deletions) !== 1 || $db->rolledBack !== 1 || $db->committed !== 1) {
        throw new RuntimeException('A stale delete changed product revisions.');
    }
}

$db->current = null;
try {
    $repository->deleteProduct($key, 'cs', 3);
    throw new RuntimeException('A missing product was deleted.');
} catch (InvalidArgumentException $expected) {
    if (count($db->deletions) !== 1 || $db->rolledBack !== 2) {
        throw new RuntimeException('A missing product changed product revisions.');
    }
}

$db->current = ['revision_number' => 3];
$db->failDelete = true;
try {
    $repository->deleteProduct($key, 'cs', 3);
    throw new RuntimeException('A failed SQL delete was committed.');
} catch (RuntimeException $expected) {
    if ($db->committed !== 1 || $db->rolledBack !== 3) {
        throw new RuntimeException('A failed SQL delete must roll back.');
    }
}

$db = new MeekroDB();
$repository = new ProductRepository($db, ['cs', 'en']);
$db->listingRows = array_fill(0, 13, ['slug' => 'produkt']);
$batch = $repository->managementPage('cs', 'spani/spacaky', 'Spacák', 'draft', 12);
[$sql, $parameters] = $db->listings[0];
if (count($batch['items']) !== 12 || $batch['nextOffset'] !== 24 ||
    !str_contains($sql, 'active_product_key IS NOT NULL') ||
    !str_contains($sql, 'published=0') || !str_contains($sql, 'ORDER BY id DESC') ||
    !str_contains($sql, 'subcategory LIKE %s') ||
    $parameters !== ['cs', 'spani', 'spacaky', 'spacaky/%', 'spacaky',
        'Spacák', 'Spacák', 'Spacák', 13, 12]) {
    throw new RuntimeException('Draft browser lost its language, category, search, or pagination filters.');
}

$db->listingRows = [['slug' => 'jeden']];
$batch = $repository->managementPage('en', null, '', 'published');
if (count($batch['items']) !== 1 || $batch['nextOffset'] !== null ||
    !str_contains($db->listings[1][0], 'published=1') ||
    $db->listings[1][1] !== ['en', 13, 0]) {
    throw new RuntimeException('Published management filter returned the wrong slice.');
}
$repository->managementPage('cs');
if (str_contains($db->listings[2][0], 'published=')) {
    throw new RuntimeException('The all-products browser must include published and hidden products.');
}
foreach ([['cs', null, '', 'invalid'], ['fr'], ['cs', '../elsewhere'],
    ['cs', null, '', 'all', -1], ['cs', null, '', 'all', 0, 49]] as $args) {
    try {
        $repository->managementPage(...$args);
        throw new RuntimeException('Invalid management filters were accepted.');
    } catch (InvalidArgumentException $expected) {
        if (count($db->listings) !== 3) {
            throw new RuntimeException('Invalid management filters reached the database.');
        }
    }
}

echo "Product deletion and management tests passed.\n";
