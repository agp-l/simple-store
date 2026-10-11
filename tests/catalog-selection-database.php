<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Product\CategoryProductSelection;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\CatalogSelection;
use SimpleStore\Product\ProductWeight;
use SimpleStore\Product\HomepageProductSelection;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
$tips = new CategoryProductSelection($db);
$catalog = new CatalogSelection(new ProductRepository($db));
$postKeys = []; $keys = []; $prefix = 'selection-' . bin2hex(random_bytes(8));
$category = 'test-' . bin2hex(random_bytes(6));
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    foreach (range(0, 26) as $i) {
        $key = bin2hex(random_bytes(16)); $keys[] = $key;
        $db->insert('shop_product_revisions', ['product_key' => $key, 'active_product_key' => $key, 'language' => 'cs',
            'revision_number' => 1, 'slug' => $prefix . '-' . $i, 'active_slug' => $prefix . '-' . $i, 'name' => $prefix . '-' . $i,
            'description' => '', 'category' => $category, 'subcategory' => $i % 2 ? 'stany' : 'spacaky', 'price_czk' => 100 + $i, 'image_path' => '',
            'details_json' => json_encode(['specifications' => [['name' => 'Hmotnost', 'value' => $i === 26 ? '500–900 g' : ($i + 1) . ' kg']]]), 'published' => $i === 25 ? 0 : 1]);
    }
    $tips->change('cs', $category, 'add', $keys[2]);
    $tips->change('cs', $category, 'add', $keys[3]);
    $tips->change('cs', $category, 'add', $keys[2]);
    $assert($tips->keys('cs', $category) === [$keys[2], $keys[3]], 'Duplicate tips.');
    try { $tips->change('cs', $category, 'add', $keys[25]); throw new RuntimeException('Draft tip accepted.'); } catch (InvalidArgumentException $expected) {}
    try { $tips->change('cs', $category . '/stany', 'add', $keys[2]); throw new RuntimeException('Wrong category tip accepted.'); } catch (InvalidArgumentException $expected) {}
    $tips->change('cs', $category, 'reorder', '', [$keys[3], $keys[2]], [$keys[2], $keys[3]]);
    try { $tips->change('cs', $category, 'reorder', '', [$keys[2], $keys[3]], [$keys[2], $keys[3]]); throw new RuntimeException('Stale sort accepted.'); } catch (InvalidArgumentException $expected) {}
    $picks = $tips->keys('cs', $category);
    $seen = []; $offset = 0;
    do {
        $page = $catalog->page('cs', $category, '', 'random', $offset, '0123456789abcdef', $picks, null);
        if ($offset === 0) {
            $assert(array_slice(array_column($page['items'], 'product_key'), 0, 2) === $picks, 'Tips not first.');
            $assert($page['items'][0]['is_tip'] && !$page['items'][2]['is_tip'], 'Bad tip badges.');
        }
        array_push($seen, ...array_column($page['items'], 'product_key'));
        $offset = $page['nextOffset'];
    } while ($offset !== null);
    $assert(count($seen) === 26 && count(array_unique($seen)) === 26 && !in_array($keys[25], $seen, true), 'Random pagination duplicated or leaked products.');
    foreach (['price-asc', 'price-desc', 'name', 'default'] as $explicitSort) {
        foreach ([null, 20000] as $weightLimit) {
            $actual = []; $cursor = 0;
            do {
                $page = $catalog->page('cs', $category, '', $explicitSort, $cursor, '0123456789abcdef', $picks, $weightLimit);
                foreach ($page['items'] as $item) {
                    $assert($item['is_tip'] === in_array($item['product_key'], $picks, true), 'Explicit sort lost recommendation badges.');
                }
                array_push($actual, ...array_column($page['items'], 'product_key'));
                $cursor = $page['nextOffset'];
            } while ($cursor !== null);
            $indices = $weightLimit === null ? array_merge(range(0, 24), [26]) : range(0, 19);
            if (in_array($explicitSort, ['price-desc', 'default'], true)) $indices = array_reverse($indices);
            if ($explicitSort === 'name') usort($indices, static fn (int $a, int $b): int => strcmp($prefix . '-' . $a, $prefix . '-' . $b));
            $assert($actual === array_map(static fn (int $i): string => $keys[$i], $indices), 'Recommendations overrode explicit sort or filtered pagination.');
        }
    }
    foreach ([-1, 100001] as $invalidOffset) {
        try { $catalog->page('cs', $category, '', 'weight-asc', $invalidOffset, '', [], null); throw new RuntimeException('Invalid weight page accepted.'); }
        catch (InvalidArgumentException $expected) {}
    }
    foreach (['weight-asc', 'weight-desc'] as $weightSort) {
        $weighted = []; $cursor = 0;
        do {
            $weightPage = $catalog->page('cs', $category, '', $weightSort, $cursor, '0123456789abcdef', $picks, null);
            array_push($weighted, ...array_column($weightPage['items'], 'product_key'));
            $cursor = $weightPage['nextOffset'];
        } while ($cursor !== null);
        $expectedKeys = array_slice($keys, 0, 25);
        if ($weightSort === 'weight-desc') $expectedKeys = array_reverse($expectedKeys);
        $expectedKeys[] = $keys[26];
        $assert($weighted === $expectedKeys, 'Whole-catalog weight order or unknown-last pagination failed.');
    }
    $weightLimited = $catalog->page('cs', $category, '', 'weight-desc', 0, '0123456789abcdef', $picks, 2000);
    $assert(array_column($weightLimited['items'], 'product_key') === [$keys[1], $keys[0]], 'Weight sorting ignored the weight limit.');
    $repeat = $catalog->page('cs', $category, '', 'random', 0, '0123456789abcdef', $picks, null);
    $assert(array_column($repeat['items'], 'product_key') === array_slice($seen, 0, 12), 'Seed not stable.');
    $filtered = $catalog->page('cs', $category, '', 'price-asc', 0, '0123456789abcdef', $picks, 2000);
    $assert(array_column($filtered['items'], 'product_key') === [$keys[0], $keys[1]], 'Weight filter failed or included heavy tips.');
    $assert(ProductWeight::grams('{"specifications":[{"name":"Hmotnost","value":"1,25 kg"}]}') === 1250.0, 'Decimal kilograms failed.');
    $assert(ProductWeight::grams('{"specifications":[{"name":"Hmotnost","value":"500–900 g"}]}') === null, 'Ambiguous weight guessed.');
    $home = new HomepageProductSelection($db); $home->change('cs', 'add', $keys[0]); $home->change('cs', 'add', $keys[1]);
    $before = $home->keys('cs'); $after = array_reverse($before); $home->reorder('cs', $after, $before);
    $assert($home->keys('cs') === $after, 'Homepage drag order not persisted.');
    $home->change('cs', 'remove', $keys[0]); $home->change('cs', 'remove', $keys[1]);
    // A separate test language keeps selection fixtures isolated from catalog content.
    $posts = new \SimpleStore\Content\CatalogPostSelection($db, ['zz']);
    foreach (range(0, 4) as $i) {
        $key = bin2hex(random_bytes(16)); $postKeys[] = $key;
        $db->insert('shop_content_revisions', ['document_key' => $key, 'active_document_key' => $key,
            'language' => 'zz', 'revision_number' => 1, 'type' => 'post', 'slug' => $key, 'active_slug' => $key,
            'title' => 'Story ' . $i, 'summary' => '', 'body' => '', 'published' => $i < 4 ? 1 : 0]);
    }
    $defaults = [$postKeys[3], $postKeys[2], $postKeys[1]];
    $assert($posts->keys('zz') === $defaults, 'Default stories must be the latest published articles.');
    try { $posts->change('zz', 'add', $postKeys[0], $defaults); throw new RuntimeException('Fourth card accepted.'); }
    catch (InvalidArgumentException $expected) {}
    $posts->change('zz', 'remove', $postKeys[1], $defaults);
    $posts->change('zz', 'add', $postKeys[0], [$postKeys[3], $postKeys[2]]);
    try { $posts->change('zz', 'remove', $postKeys[0], $defaults); throw new RuntimeException('Stale card selection accepted.'); }
    catch (InvalidArgumentException $expected) {}
    $posts->change('zz', 'up', $postKeys[0], [$postKeys[3], $postKeys[2], $postKeys[0]]);
    $assert(array_column($posts->posts('zz'), 'document_key') === [$postKeys[3], $postKeys[0], $postKeys[2]], 'Story ordering failed.');
    $db->query('UPDATE shop_content_revisions SET published=0 WHERE document_key=%s', $postKeys[3]);
    $assert(array_column($posts->posts('zz'), 'document_key') === [$postKeys[0], $postKeys[2]], 'Hidden story leaked.');
    foreach ($posts->keys('zz') as $key) $posts->change('zz', 'remove', $key, $posts->keys('zz'));
    $assert($posts->posts('zz') === [], 'Explicit empty selection must not restore automatic picks.');
    try { $posts->change('zz', 'add', $postKeys[4], []); throw new RuntimeException('Draft story accepted.'); }
    catch (InvalidArgumentException $expected) {}
    echo "Catalog recommendations, pagination, ordering and weight tests passed.\n";
} finally {
    $db->query('DELETE FROM shop_catalog_post_selections WHERE language=%s', 'zz');
    foreach ($postKeys as $key) $db->query('DELETE FROM shop_content_revisions WHERE document_key=%s', $key);
    $db->query('DELETE FROM shop_category_product_selections WHERE category_path=%s OR category_path LIKE %s', $category, $category . '/%');
    foreach ($keys as $key) $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s', $key);
}
