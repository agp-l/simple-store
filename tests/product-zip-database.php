<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Database\SchemaUpdater;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Media\MediaLibrary;
use SimpleStore\Product\ProductImport;
use SimpleStore\Product\ProductPackageImporter;
use SimpleStore\Product\ProductRepository;
use SimpleStore\Product\ProductStockRepository;
use SimpleStore\Product\ProductSupplierLinkRepository;
use SimpleStore\Product\ProductZipArchive;
use SimpleStore\Product\ProductImportJob;
use SimpleStore\Product\ProductDetails;

if (!class_exists(ZipArchive::class) || !extension_loaded('gd')) throw new RuntimeException('ZIP/GD required for package integration tests.');
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$db = ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
(new SchemaUpdater($db, dirname(__DIR__) . '/database/schema.sql'))->apply();
$root = sys_get_temp_dir() . '/zip-fixture-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$media = new MediaLibrary($root);
$categories = new CategoryRepository($db);
$products = new ProductRepository($db, ['cs'], $categories, 50, new ProductStockRepository($db));
$links = new ProductSupplierLinkRepository($db);
$importer = new ProductPackageImporter($products, $categories, $media, ['cs']);
$slug = 'zip-test-' . bin2hex(random_bytes(8));
$data = ['format' => ProductImport::FORMAT, 'language' => 'cs', 'product' => [
    'name' => 'ZIP import test', 'slug' => $slug, 'category_path' => 'batohy', 'price_czk' => 100,
    'suppliers' => [['label' => 'Dodavatel', 'url' => 'https://example.org/product']]]];
$image = imagecreatetruecolor(20, 20);
ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
$zipPath = $root . '/input.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);
$zip->addFromString('01-broken/product.json', '{broken');
$zip->addFromString('02-good/product.json', json_encode($data));
for ($i = 0; $i < 15; $i++) $zip->addFromString('02-good/images/' . sprintf('%02d', $i) . '.png', $png);
$bad = $data; $bad['product']['slug'] .= '-bad-image';
$zip->addFromString('03-bad-image/product.json', json_encode($bad));
$zip->addFromString('03-bad-image/images/01.png', $png);
$zip->addFromString('03-bad-image/images/02.png', 'not an image');
$last = $data; $last['product']['slug'] .= '-last';
$zip->addFromString('04-last/product.json', json_encode($last));
$zip->close();
$keys = []; $jobs = [];
try {
    $job = new ProductImportJob(ProductImportJob::fromFile($zipPath)); $jobs[] = $job;
    for ($i = 0; $i < 4; $i++) $state = $job->step($importer);
    $check(array_column($state['items'], 'status') === ['error', 'success', 'error', 'success'], 'One bad product blocked the rest.');
    foreach ($state['items'] as $item) if (isset($item['saved'])) $keys[] = $item['saved']['product_key'];
    $good = $products->current($keys[0], 'cs');
    $check(count(ProductDetails::decode($good['details_json'])['gallery']) === 14, 'The gallery still has a 12-image limit.');
    $check(count($media->files('product', $keys[0])) === 15, 'ZIP photos were not stored locally.');
    $check(is_file($root . '/' . $good['image_path']) && count($links->forProduct($keys[0])) === 1, 'Image or private supplier missing.');
    $check($products->findCurrentBySlug($bad['product']['slug'], 'cs') === null && $media->files('product', $state['items'][2]['new_key']) === [], 'Failed photo left partial product or files.');
    $check($job->step($importer) === $state, 'Repeated step replayed completed work.');
    $export = ProductImport::decode(ProductImport::export($good, $links->forProduct($keys[0])));
    $export['product'] = ['price_czk' => 200, 'suppliers' => [['label' => 'New', 'url' => 'https://example.org/new']]];
    $products->saveRevision(ProductImport::fields($export, $good), $keys[0], 1, ProductImport::suppliers($export));
    $updated = $products->current($keys[0], 'cs');
    $check((int) $updated['price_czk'] === 200 && $links->forProduct($keys[0])[0]['label'] === 'New', 'Supplier replacement failed.');
    // Supplier validation fails after snapshot insert: the entire revision must roll back.
    try {
        $products->saveRevision(ProductImport::fields($export, $good), $keys[0], 2, [['label' => '', 'url' => 'bad']]);
        throw new LogicException('Bad supplier was accepted.');
    } catch (InvalidArgumentException $expected) {}
    $check((int) $products->current($keys[0], 'cs')['revision_number'] === 2 && count($links->forProduct($keys[0])) === 1, 'Supplier failure did not roll back the revision.');
    $patch = ['format' => ProductImport::FORMAT, 'language' => 'cs', 'product_key' => $keys[0], 'revision' => 2,
        'product' => ['description' => 'Updated by ZIP']];
    $zip = new ZipArchive(); $zip->open($root . '/update.zip', ZipArchive::CREATE);
    $zip->addFromString('01-update/product.json', json_encode($patch));
    $zip->addFromString('02-stale/product.json', json_encode($patch)); $zip->close();
    $updateJob = new ProductImportJob(ProductImportJob::fromFile($root . '/update.zip', 'update')); $jobs[] = $updateJob;
    $updateJob->step($importer); $updateState = $updateJob->step($importer);
    $check(array_column($updateState['items'], 'status') === ['success', 'skipped'], 'ZIP update failed or stale revision was accepted.');
    $afterZip = $products->current($keys[0], 'cs');
    $check($afterZip['description'] === 'Updated by ZIP' && $afterZip['image_path'] === $good['image_path'] && $links->forProduct($keys[0])[0]['label'] === 'New', 'ZIP partial update lost photos or private suppliers.');
    // Exact URL matching supports AI packages without keys; a duplicate never blocks the next item.
    foreach (['skip', 'update', 'copy'] as $mode) {
        $duplicate = $data;
        $duplicate['product']['price_czk'] = 450;
        $duplicate['product']['published'] = true;
        $next = $data; $next['product']['slug'] .= '-following-' . $mode;
        $modePath = $root . '/policy-' . $mode . '.zip';
        $zip = new ZipArchive(); $zip->open($modePath, ZipArchive::CREATE);
        $zip->addFromString('01-duplicate/product.json', json_encode($duplicate));
        $zip->addFromString('01-duplicate/images/01.png', $png);
        $zip->addFromString('02-new/product.json', json_encode($next));
        $zip->addFromString('03-invalid/product.json', '{invalid'); $zip->close();
        $before = $products->current($keys[0], 'cs');
        $photoCount = count($media->files('product', $keys[0]));
        $modeJob = new ProductImportJob(ProductImportJob::fromFile($modePath, $mode)); $jobs[] = $modeJob;
        for ($i = 0; $i < 3; $i++) $modeState = $modeJob->step($importer);
        foreach ($modeState['items'] as $item) if (isset($item['saved']) && !in_array($item['saved']['product_key'], $keys, true)) $keys[] = $item['saved']['product_key'];
        $check($modeState['items'][1]['status'] === 'success' && $modeState['items'][2]['status'] === 'error', 'Duplicate mode blocked a later new product or hid malformed JSON.');
        $first = $modeState['items'][0];
        if ($mode === 'skip') {
            $check($first['status'] === 'skipped' && $products->current($keys[0], 'cs')['revision_number'] === $before['revision_number'], 'Skip modified the product.');
            $check(count($media->files('product', $keys[0])) === $photoCount, 'Skipped duplicate stored unused photos.');
        } elseif ($mode === 'update') {
            $check($first['status'] === 'success' && $first['saved']['product_key'] === $keys[0], 'Keyless update failed to match the exact slug.');
            $check((int) $products->current($keys[0], 'cs')['price_czk'] === 450, 'Keyless update did not apply fields.');
        } else {
            $copy = $products->current($first['saved']['product_key'], 'cs');
            $check($copy['product_key'] !== $keys[0] && $copy['slug'] !== $slug && (int) $copy['published'] === 0, 'Copy reused the identity or was publicly published.');
            $check(count($media->files('product', $copy['product_key'])) === 1, 'Copy photos were not stored under its own key.');
            $check($products->current($keys[0], 'cs')['revision_number'] === $before['revision_number'], 'Copy modified the original.');
        }
        $check($modeJob->step($importer) === $modeState, 'Completed duplicate mode was replayed.');
    }
    $future = $patch; $future['revision'] = 9999;
    $zip = new ZipArchive(); $zip->open($root . '/future.zip', ZipArchive::CREATE);
    $zip->addFromString('future/product.json', json_encode($future)); $zip->close();
    $futureJob = new ProductImportJob(ProductImportJob::fromFile($root . '/future.zip', 'update')); $jobs[] = $futureJob;
    $check($futureJob->step($importer)['items'][0]['status'] === 'skipped', 'Future revision bypassed optimistic locking.');
    // Simulate a process dying after the durable processing checkpoint.
    $interruptedToken = ProductImportJob::fromFile($zipPath);
    $interrupted = new ProductImportJob($interruptedToken); $jobs[] = $interrupted;
    $checkpoint = $interrupted->state(); $checkpoint['items'][0]['status'] = 'processing';
    file_put_contents(sys_get_temp_dir() . '/simple-store-import-' . $interruptedToken . '/state.json', json_encode($checkpoint));
    $resumed = $interrupted->step($importer);
    $check($resumed['items'][0]['status'] === 'interrupted' && $resumed['items'][1]['status'] === 'skipped', 'Interrupted item was replayed or blocked the following duplicate check.');
    $zip = new ZipArchive(); $zip->open($root . '/unsafe.zip', ZipArchive::CREATE);
    $zip->addFromString('../outside.php', '<?php'); $zip->close();
    try { new ProductZipArchive($root . '/unsafe.zip'); throw new LogicException('Traversal accepted.'); } catch (InvalidArgumentException $expected) {}
    $zip = new ZipArchive(); $zip->open($root . '/symlink.zip', ZipArchive::CREATE);
    $zip->addFromString('product/product.json', json_encode($data));
    $zip->addFromString('product/images/link.png', '/etc/passwd');
    $zip->setExternalAttributesName('product/images/link.png', ZipArchive::OPSYS_UNIX, 0120777 << 16); $zip->close();
    try { (new ProductZipArchive($root . '/symlink.zip'))->images('product'); throw new LogicException('Symlink accepted.'); } catch (InvalidArgumentException $expected) {}
    $check(!ProductDetails::imagePath('https://example.org/photo.png'), 'External image still accepted.');
    echo "ZIP products: partial success, 15 photos, supplier transaction, idempotent steps and unsafe archives passed.\n";
} finally {
    foreach ($jobs as $job) $job->remove();
    foreach ($keys as $key) {
        $db->query('DELETE FROM shop_product_supplier_links WHERE product_key=%s', $key);
        $db->query('DELETE FROM shop_product_revisions WHERE product_key=%s', $key);
        $db->query('DELETE FROM shop_product_inventory WHERE product_key=%s', $key);
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($root);
}
