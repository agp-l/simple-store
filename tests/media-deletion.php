<?php
declare(strict_types=1);

class MeekroDB
{
    public function __construct(public array $products = [], public array $documents = []) {}

    public function query(string $sql, mixed ...$values): array
    {
        if (str_contains($sql, 'FROM shop_product_revisions')) {
            if (!str_contains($sql, 'active_product_key IS NOT NULL')) {
                throw new RuntimeException('Deletion must check only current product revisions.');
            }
            return $this->products;
        }
        if (!str_contains($sql, 'active_document_key IS NOT NULL')) {
            throw new RuntimeException('Deletion must check only current documents.');
        }
        return $this->documents;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Media\MediaDeletion;
use SimpleStore\Media\MediaLibrary;
use SimpleStore\Media\MediaPath;

$key = str_repeat('a', 32);
$otherKey = str_repeat('b', 32);
$directory = MediaPath::directory('product', $key);
$paths = [];
foreach (['jiny-jazyk', 'clanek', 'nepouzita'] as $i => $label) {
    $paths[] = $directory . '/' . $label . '--' . str_pad(dechex($i + 1), 24, '0', STR_PAD_LEFT) . '.webp';
}
$temp = sys_get_temp_dir() . '/simple-store-delete-' . bin2hex(random_bytes(6));
mkdir($temp . '/' . $directory, 0700, true);
try {
    foreach ($paths as $path) {
        foreach ([$path, MediaPath::variant($path, 'card'), MediaPath::variant($path, 'thumb')] as $file) {
            file_put_contents($temp . '/' . $file, 'test image');
        }
    }
    $db = new MeekroDB(
        [['image_path' => MediaPath::variant($paths[0], 'card'), 'details_json' => null,
            'description' => '', 'summary' => '']],
        [['body' => json_encode(['photo' => $paths[1]], JSON_THROW_ON_ERROR), 'summary' => null]]
    );
    $library = new MediaLibrary($temp);
    $deletion = new MediaDeletion($db, $library);
    $files = $deletion->withDeletionState('product', $key, array_map(static fn (string $path): array =>
        ['path' => $path, 'uses' => []], $paths));
    if ($files[0]['can_delete'] || $files[1]['can_delete'] || !$files[2]['can_delete'] ||
        !$files[0]['used_elsewhere'] || !$files[1]['used_elsewhere']) {
        throw new RuntimeException('An image used in another current language or document was marked deletable.');
    }
    try {
        $deletion->deleteUnused('product', $key, $paths[0]);
        throw new RuntimeException('A current product image was deleted.');
    } catch (InvalidArgumentException $expected) {}
    try {
        $deletion->deleteUnused('product', $key, $paths[1]);
        throw new RuntimeException('An image in a current document was deleted.');
    } catch (InvalidArgumentException $expected) {}
    try {
        $deletion->deleteUnused('product', $otherKey, $paths[2]);
        throw new RuntimeException('A different product deleted this image.');
    } catch (InvalidArgumentException $expected) {}
    $outside = $temp . '/outside';
    file_put_contents($outside, 'keep this file');
    $symlink = $directory . '/odkaz--' . str_repeat('d', 24) . '.webp';
    if (function_exists('symlink') && @symlink($outside, $temp . '/' . $symlink)) {
        try {
            $deletion->deleteUnused('product', $key, $symlink);
            throw new RuntimeException('A symlink was accepted as an uploaded image.');
        } catch (RuntimeException $expected) {
            if ($expected->getMessage() === 'A symlink was accepted as an uploaded image.') throw $expected;
        }
        if (file_get_contents($outside) !== 'keep this file') {
            throw new RuntimeException('Deleting a symlink touched its target.');
        }
    }
    $deletion->deleteUnused('product', $key, $paths[2]);
    foreach ([$paths[2], MediaPath::variant($paths[2], 'card'), MediaPath::variant($paths[2], 'thumb')] as $file) {
        if (file_exists($temp . '/' . $file)) throw new RuntimeException('Unused image or thumbnail remains on disk.');
    }
    if (!is_file($temp . '/' . $paths[0]) || !is_file($temp . '/' . $paths[1])) {
        throw new RuntimeException('Deleting an unused image touched another file.');
    }
} finally {
    foreach (glob($temp . '/' . $directory . '/*') ?: [] as $file) @unlink($file);
    @unlink($temp . '/outside');
    @rmdir($temp . '/' . $directory);
    @rmdir($temp . '/images/media/products');
    @rmdir($temp . '/images/media');
    @rmdir($temp . '/images');
    @rmdir($temp);
}

echo "Media deletion tests passed.\n";
