<?php
declare(strict_types=1);

// Capture SQL and bound parameters without requiring a local database server.
class MeekroDB
{
    public string $sql = '';
    public array $parameters = [];
    public array $rows = [];

    public function query(string $sql, mixed ...$parameters): array
    {
        $this->sql = $sql;
        $this->parameters = $parameters;
        return $this->rows;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Content\ContentRepository;
use SimpleStore\Product\ProductRepository;

$db = new MeekroDB();
$db->rows = array_fill(0, 13, ['slug' => 'bota']);
$products = new ProductRepository($db);
$page = $products->publishedPage('cs', 'spani/spacaky', 'Topo', 'price-asc', 12);
if (count($page['items']) !== 12 || $page['nextOffset'] !== 24 ||
    !str_contains($db->sql, 'category=%s AND (subcategory=%s OR subcategory LIKE %s)') ||
    !str_contains($db->sql, 'OR category=%s') ||
    !str_contains($db->sql, 'LOCATE(%s, name)>0') ||
    !str_contains($db->sql, 'ORDER BY price_czk ASC, id DESC LIMIT %i OFFSET %i') ||
    $db->parameters !== ['cs', 'spani', 'spacaky', 'spacaky/%', 'spacaky',
        'Topo', 'Topo', 'Topo', 13, 12]) {
    throw new RuntimeException('SQL catalog filtering must include old sleeping bags, search and stable ordering.');
}

$db->rows = array_fill(0, 2, ['slug' => 'batoh']);
$page = $products->publishedPage('cs', 'batohy/batohy-do-25-l');
if ($page['nextOffset'] !== null ||
    !str_contains($db->sql, '(category=%s AND subcategory=%s)') ||
    !in_array('do-25', $db->parameters, true)) {
    throw new RuntimeException('Legacy backpack subcategories must match the canonical catalog.');
}

$db->rows = array_fill(0, 7, ['slug' => 'clanek']);
$posts = new ContentRepository($db);
$page = $posts->publishedPostsPage('cs');
if (count($page['items']) !== 6 || $page['nextOffset'] !== 6 ||
    !str_contains($db->sql, 'active_document_key IS NOT NULL AND published=1') ||
    !str_contains($db->sql, 'ORDER BY id DESC LIMIT %i OFFSET %i') ||
    $db->parameters !== ['post', 'cs', 7, 0]) {
    throw new RuntimeException('Blog must request only one bounded batch of published posts.');
}

echo "Listing page tests passed.\n";
