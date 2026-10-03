<?php
declare(strict_types=1);

class MeekroDB
{
    public ?array $current = ['type' => 'post', 'revision_number' => 3];
    public array $lookups = [];
    public array $deletions = [];
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
        if ($this->failDelete) throw new RuntimeException('Database write failed.');
        $this->deletions[] = [$sql, $values];
        return [];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Content\ContentRepository;

$db = new MeekroDB();
$repository = new ContentRepository($db, ['cs', 'en']);
$key = str_repeat('a', 32);
$repository->deleteDocument($key, 'cs', 'post', 3);
if ($db->begun !== 1 || $db->committed !== 1 || $db->rolledBack !== 0 ||
    count($db->lookups) !== 1 || !str_contains($db->lookups[0][0], 'FOR UPDATE') ||
    $db->lookups[0][1] !== [$key, 'cs'] || count($db->deletions) !== 1 ||
    !str_contains($db->deletions[0][0], 'DELETE FROM content_revisions') ||
    $db->deletions[0][1] !== [$key, 'cs']) {
    throw new RuntimeException('Deleting a document must lock the current revision and remove only one language.');
}

foreach ([['bad-key', 'cs', 'post', 3], [$key, 'de', 'post', 3],
    [$key, 'cs', 'other', 3], [$key, 'cs', 'post', 0]] as $args) {
    try {
        $repository->deleteDocument(...$args);
        throw new RuntimeException('Invalid deletion arguments were accepted.');
    } catch (InvalidArgumentException $expected) {
        if ($db->begun !== 1 || count($db->deletions) !== 1) {
            throw new RuntimeException('Invalid deletion arguments reached the database.');
        }
    }
}

$db->current = ['type' => 'page', 'revision_number' => 3];
try {
    $repository->deleteDocument($key, 'cs', 'post', 3);
    throw new RuntimeException('A mismatched document type was deleted.');
} catch (InvalidArgumentException $expected) {
    if (count($db->deletions) !== 1 || $db->rolledBack !== 1) {
        throw new RuntimeException('A mismatched document type changed revisions.');
    }
}

$db->current = ['type' => 'page', 'revision_number' => 4];
try {
    $repository->deleteDocument($key, 'cs', 'page', 3);
    throw new RuntimeException('A stale document was deleted.');
} catch (RuntimeException $expected) {
    if (count($db->deletions) !== 1 || $db->rolledBack !== 2) {
        throw new RuntimeException('A stale deletion changed revisions.');
    }
}

$db->current = null;
try {
    $repository->deleteDocument($key, 'cs', 'page', 3);
    throw new RuntimeException('A missing document was deleted.');
} catch (InvalidArgumentException $expected) {
    if (count($db->deletions) !== 1 || $db->rolledBack !== 3) {
        throw new RuntimeException('A missing document changed revisions.');
    }
}

$db->current = ['type' => 'page', 'revision_number' => 3];
$db->failDelete = true;
try {
    $repository->deleteDocument($key, 'cs', 'page', 3);
    throw new RuntimeException('A failed SQL delete was committed.');
} catch (RuntimeException $expected) {
    if ($db->committed !== 1 || $db->rolledBack !== 4) {
        throw new RuntimeException('A failed SQL delete must roll back.');
    }
}

echo "Content deletion tests passed.\n";
