<?php
declare(strict_types=1);

class MeekroDB
{
    public ?array $page = null;
    public array $snapshots = [];

    public function queryFirstField(string $sql, mixed ...$args): int
    {
        if (str_contains($sql, 'information_schema.TABLES')) return 1;
        throw new RuntimeException('Unexpected field query: ' . $sql);
    }

    public function queryFirstRow(string $sql, mixed ...$args): ?array
    {
        if (str_contains($sql, 'FROM content_revisions')) {
            return $args[1] === 'obchodni-podminky' ? $this->page : null;
        }
        if (str_contains($sql, 'FROM shop_order_legal_snapshots')) return $this->snapshots[$args[0]] ?? null;
        throw new RuntimeException('Unexpected row query: ' . $sql);
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (!str_contains($sql, 'INSERT IGNORE INTO shop_order_legal_snapshots')) {
            throw new RuntimeException('Unexpected query: ' . $sql);
        }
        $this->snapshots[$args[0]] ??= ['terms_text' => $args[4], 'language' => $args[1],
            'terms_revision' => $args[3]];
        return [];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Accounting\OrderLegalDocuments;
use SimpleStore\Content\ContentBody;

$db = new MeekroDB();
$documents = new OrderLegalDocuments($db);
$items = [['language' => 'cs']];
$db->page = ['document_key' => str_repeat('a', 32), 'revision_number' => 3,
    'body' => ContentBody::encode([['type' => 'text', 'heading' => 'Práva zákazníka',
        'body' => 'Znění při nákupu <původní>.']])];
$documents->capture(19, $items);
$db->page['revision_number'] = 4;
$db->page['body'] = 'Zcela nové podmínky';
$stored = $documents->forOrder(['id' => 19, 'items' => $items]);
if ($stored['terms'] !== "Práva zákazníka\n\nZnění při nákupu <původní>." ||
    $db->snapshots[19]['terms_revision'] !== 3 ||
    $stored['slugs'] !== ['obchodni-podminky']) {
    throw new RuntimeException('An edited terms page replaced the order snapshot.');
}
$db->page = null;
$unpublished = $documents->forOrder(['id' => 19, 'items' => $items]);
if ($unpublished['terms'] !== $stored['terms'] || $unpublished['slugs'] !== []) {
    throw new RuntimeException('Unpublishing a page lost an existing snapshot or leaked its link.');
}
$legacy = $documents->forOrder(['id' => 20, 'items' => $items]);
if ($legacy['terms'] !== '') throw new RuntimeException('An older order was assigned a later legal version.');

$db->page = ['document_key' => str_repeat('b', 32), 'revision_number' => 1,
    'body' => ContentBody::encode([['type' => 'image', 'heading' => 'Pravidla', 'body' => 'images/terms.png']])];
try {
    $documents->capture(21, $items);
    throw new RuntimeException('Image-only terms were accepted as immutable text.');
} catch (InvalidArgumentException $expected) {}

echo "Order legal snapshot OK\n";
