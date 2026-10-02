<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Product\HomepageProductSelection;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$homepage = new HomepageProductSelection($db, ['cs', 'en']);
if (!$homepage->installed() || $homepage->keys('cs') !== null) {
    throw new RuntimeException('Existing storefront must use its original catalog until a selection is saved.');
}

$keys = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
foreach ($keys as $index => $key) {
    $db->insert('product_revisions', [
        'product_key' => $key, 'active_product_key' => $key, 'language' => 'cs',
        'revision_number' => 1, 'slug' => 'home-test-' . $key, 'active_slug' => 'home-test-' . $key,
        'name' => 'Homepage item ' . $index, 'description' => '', 'category' => 'batohy',
        'price_czk' => 1000 + $index, 'image_path' => 'images/test.webp',
        'published' => $index === 2 ? 0 : 1,
    ]);
}
$homepage->change('cs', 'add', $keys[0]);
$homepage->change('cs', 'add', $keys[1]);
$homepage->change('cs', 'add', $keys[0]);
$homepage->change('cs', 'up', $keys[1]);
if ($homepage->keys('cs') !== [$keys[1], $keys[0]] || $homepage->keys('en') !== null ||
    array_column($homepage->products('cs', $homepage->keys('cs')), 'product_key') !== [$keys[1], $keys[0]]) {
    throw new RuntimeException('Homepage selection lost order, duplicated an item or crossed languages.');
}
try {
    $homepage->change('cs', 'add', $keys[2]);
    throw new RuntimeException('A draft was added to the homepage.');
} catch (InvalidArgumentException $expected) {
    if (!str_contains($expected->getMessage(), 'zveřejněný')) throw $expected;
}
$db->query('UPDATE product_revisions SET published=0 WHERE product_key=%s AND language=%s', $keys[1], 'cs');
if (array_column($homepage->products('cs', $homepage->keys('cs')), 'product_key') !== [$keys[0]] ||
    count($homepage->products('cs', $homepage->keys('cs'), null, true)) !== 2) {
    throw new RuntimeException('Unpublished items must disappear publicly but remain removable by the administrator.');
}
$homepage->change('cs', 'remove', $keys[1]);
$homepage->change('cs', 'remove', $keys[0]);
if ($homepage->keys('cs') !== [] || $homepage->products('cs', []) !== []) {
    throw new RuntimeException('An explicitly empty selection must remain distinct from the default catalog.');
}
$homepage->change('cs', 'reset');
if ($homepage->keys('cs') !== null) throw new RuntimeException('Reset did not restore the automatic catalog.');

echo "Homepage product selection OK\n";
