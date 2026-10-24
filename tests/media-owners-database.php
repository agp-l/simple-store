<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentInlineEditor;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductInlineEditor;
use SimpleStore\Media\MediaOwners;
use SimpleStore\Media\MediaBrowse;

$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root', 'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$categories = new CategoryRepository($db);
$category = 'media-test-' . bin2hex(random_bytes(6));
$products = new ProductRepository($db, ['cs', 'en'], $categories); $documents = new ContentRepository($db, ['cs', 'en']);
$keys = []; $files = [];
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    foreach (['cs', 'en'] as $language) $categories->create($language, '', $category, 'Media test', 99);
    $form = ProductInlineEditor::starter('cs', $category); $form['name'] = 'Původní batoh';
    $saved = $products->saveRevision($form); $key = $saved['product_key']; $keys['products'][] = $key;
    $products->saveRevision(array_replace($form, ['language' => 'en', 'name' => 'Backpack']), $key, 0);
    $products->saveRevision(array_replace($form, ['name' => 'Nový název batohu']), $key, 1);
    foreach (['page', 'post'] as $type) {
        $data = ContentInlineEditor::starter($type, 'cs'); $data['title'] = $type === 'page' ? 'O nás' : 'Náš příběh';
        $row = $documents->saveRevision($data); $keys[$type === 'page' ? 'pages' : 'posts'][] = $row['document_key'];
    }
    foreach ($keys as $group => $ids) foreach ($ids as $id) {
        $path = 'images/media/' . $group . '/' . $id . '/photo--' . str_repeat('f', 24) . '.webp';
        $files[] = ['path' => $path, 'label' => 'photo', 'thumb' => $path, 'card' => $path, 'bytes' => 500, 'created' => 100, 'group' => $group, 'folder' => ''];
    }
    $owners = (new MediaOwners($db, ['cs', 'en'], 'cs'))->names($files);
    $check(count($owners) === 3 && $owners['products:' . $key]['title'] === 'Nový název batohu', 'Folder names used history or duplicated translations.');
    $check((int) $owners['products:' . $key]['revision_number'] === 2, 'Gallery upload must use the current revision.');
    $english = (new MediaOwners($db, ['cs', 'en'], 'en'))->names($files);
    $check($english['products:' . $key]['title'] === 'Backpack' && count($english) === 3, 'Preferred-language selection or fallback failed.');
    $check($owners['pages:' . $keys['pages'][0]]['title'] === 'O nás' && $owners['posts:' . $keys['posts'][0]]['title'] === 'Náš příběh', 'Page/post folders were mixed.');
    $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s', $key);
    $owners = (new MediaOwners($db, ['cs', 'en'], 'cs'))->names($files);
    $listing = MediaBrowse::listing($files, ['folders' => []], $owners, MediaBrowse::options(['folder' => 'products']));
    $check($listing['total'] === 1 && $listing['directories'][0]['owner'] === null, 'Deleted product made existing photos disappear.');
} finally {
    foreach ($keys['products'] ?? [] as $key) {
        $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s', $key);
        $db->query('DELETE FROM shop_product_inventory WHERE product_key=%s', $key);
    }
    $db->query('DELETE FROM shop_catalog_categories WHERE path=%s', $category);
    foreach (array_merge($keys['pages'] ?? [], $keys['posts'] ?? []) as $key) $db->query('DELETE FROM shop_content_revisions WHERE document_key=%s', $key);
}
echo "Media owner database: active names, translations, safe gallery revisions and deleted-owner folders passed.\n";
