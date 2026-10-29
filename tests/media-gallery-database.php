<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductInlineEditor;
use SimpleStore\Media\MediaPath;

$db = ConnectionFactory::create(['host'=>'127.0.0.1', 'user'=>'root', 'password'=>(string)getenv('MYSQL_TEST_PASSWORD'), 'database'=>'simple_store', 'port'=>3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$category = 'gallery-test-' . bin2hex(random_bytes(5));
$categories = new CategoryRepository($db);
$products = new ProductRepository($db, ['cs'], $categories);
$key = ''; $paths = [];
$check = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$api = static function(array $input, string $method = 'POST') use ($db): array {
    $site = ['languages'=>['cs'], 'revision_limit'=>50, 'debug'=>false]; $basePath = '/';
    $content = new ContentRepository($db, ['cs']); $_POST = $input; $_GET = $input;
    http_response_code(200); ob_start();
    require dirname(__DIR__) . '/src/Admin/media-api.php';
    return [http_response_code(), json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR)];
};
try {
    $categories->create('cs', '', $category, 'Gallery test', 99);
    $form = ProductInlineEditor::starter('cs', $category);
    $saved = $products->saveRevision($form); $key = $saved['product_key'];
    $directory = dirname(__DIR__) . '/' . MediaPath::directory('product', $key);
    mkdir($directory, 0700, true);
    $path = MediaPath::directory('product', $key) . '/photo--' . str_repeat('e', 24) . '.webp';
    foreach ([$path, MediaPath::variant($path,'card'), MediaPath::variant($path,'thumb')] as $file) {
        $paths[] = dirname(__DIR__) . '/' . $file; file_put_contents(end($paths), 'fixture image');
    }
    $form['image_path'] = $path; $form['gallery'] = 'images/kresadlo.webp';
    $products->saveRevision($form, $key, 1);
    $input = ['action'=>'media-detach', 'key'=>$key, 'type'=>'product', 'language'=>'cs', 'revision'=>2, 'path'=>$path];
    [$status,$result] = $api($input);
    $check($status===200 && $result['revision']===3 && $result['detached']===$path, 'Detach API failed.');
    $current = $products->current($key,'cs');
    $check($current['image_path']==='images/kresadlo.webp' && is_file($paths[0]), 'Detach did not promote gallery image or deleted the file.');
    [$status,$result] = $api($input);
    $check($status===409 && str_contains($result['error'],'mezitím'), 'Stale revision was not rejected with an actionable error.');
    [$status,$result] = $api(array_replace($input,['action'=>'media-delete','revision'=>3,'path'=>'images/kresadlo.webp']));
    $check($status===422, 'Unmanaged shared file was deletable.');
    [$status,$result] = $api(array_replace($input,['action'=>'media-delete','revision'=>3]));
    $check($status===200 && $result['deleted']===$path, 'Delete after detach failed.');
    foreach($paths as $file) $check(!file_exists($file), 'Original or derivative remains after delete.');
    [$status,$result] = $api(['type'=>'product','key'=>$key,'language'=>'cs'],'GET');
    $check($status===200 && in_array('images/kresadlo.webp',array_column($result['files'],'path'),true), 'Referenced legacy image missing from picker.');
} finally {
    foreach($paths as $file) @unlink($file);
    if (isset($directory)) @rmdir($directory);
    if ($key!=='') $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s',$key);
    $db->query('DELETE FROM shop_catalog_categories WHERE path=%s',$category);
}
echo "Media gallery API database tests passed.\n";
