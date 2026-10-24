<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Media\MediaCatalog;
use SimpleStore\Media\MediaArchive;
use SimpleStore\Media\MediaLibrary;
use SimpleStore\Media\MediaPath;

if (!extension_loaded('gd') || !class_exists(ZipArchive::class)) throw new RuntimeException('GD and ZIP are required for media archive integration tests.');
$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$root = sys_get_temp_dir() . '/media-archive-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$clean = static function (string $dir) use (&$clean): void { foreach (new DirectoryIterator($dir) as $entry) { if ($entry->isDot()) continue; if ($entry->isDir() && !$entry->isLink()) $clean($entry->getPathname()); else unlink($entry->getPathname()); } rmdir($dir); };
$export = null;
try {
    mkdir($root . '/source'); mkdir($root . '/target');
    $image = imagecreatetruecolor(1200, 800); imagefill($image, 0, 0, imagecolorallocate($image, 50, 80, 170)); imagepng($image, $root . '/source.png'); imagedestroy($image);
    $catalog = new MediaCatalog($root . '/source'); $library = new MediaLibrary($root . '/source');
    $folder = $catalog->saveFolder('Výpravy'); $child = $catalog->saveFolder('Podzim', $folder);
    $path = $library->storeFile('post', str_repeat('a', 32), $root . '/source.png', 'Příběh.png');
    $catalog->assign([$path], $child);
    $export = (new MediaArchive($root . '/source'))->export([$folder]);
    $zip = new ZipArchive(); $zip->open($export); $manifest = json_decode($zip->getFromName('manifest.json'), true); $zip->close();
    $check(count($manifest['folders']) === 2 && count($manifest['files']) === 1, 'Export lost hierarchy or included derivatives.');
    $targetArchive = new MediaArchive($root . '/target'); $target = new MediaCatalog($root . '/target');
    $first = $targetArchive->import($export); $second = $targetArchive->import($export);
    $check($first['added'] === 1 && $second['added'] === 0 && $second['reused'] === 1 && count($target->state()['folders']) === 2, 'Repeated import duplicated images or folders.');
    $files = $target->files(); $newPath = $files[0]['path'];
    $check(count($files) === 1 && is_file($root . '/target/' . MediaPath::variant($newPath, 'thumb')), 'Import failed to regenerate thumbnails.');
    $size = getimagesize($root . '/target/' . MediaPath::variant($newPath, 'thumb'));
    $check(max($size[0], $size[1]) === 240, 'Thumbnail dimensions wrong.');
    $same = (new MediaArchive($root . '/source'))->import($export);
    $check($same['added'] === 0 && $catalog->files()[0]['path'] === $path, 'Import into source changed existing URL.');
    // A second broken image must roll back folders and any images already decoded in this batch.
    $badRoot = $root . '/broken'; mkdir($badRoot); $badCatalog = new MediaCatalog($badRoot); $before = $badCatalog->state();
    $badManifest = $manifest;
    $badManifest['files'][] = ['entry' => 'photos/1.png', 'source' => 'images/broken.png', 'folder' => $child, 'sha256' => hash('sha256', 'not an image')];
    copy($export, $root . '/bad.zip'); $zip = new ZipArchive(); $zip->open($root . '/bad.zip'); $zip->addFromString('photos/1.png', 'not an image'); $zip->addFromString('manifest.json', json_encode($badManifest)); $zip->close();
    try { (new MediaArchive($badRoot))->import($root . '/bad.zip'); throw new LogicException('Corrupt image accepted.'); } catch (InvalidArgumentException) {}
    $check($badCatalog->files() === [] && $badCatalog->state() === $before, 'Failed batch left partial images or metadata.');
    copy($export, $root . '/traversal.zip'); $zip = new ZipArchive(); $zip->open($root . '/traversal.zip'); $zip->addFromString('../escape.php', '<?php echo 1;'); $zip->close();
    try { $targetArchive->import($root . '/traversal.zip'); throw new LogicException('Unexpected ZIP entry accepted.'); } catch (InvalidArgumentException) {}
    $check(!file_exists($root . '/escape.php') && $target->files() === $files, 'Unsafe ZIP changed files.');
} finally { if ($export !== null && is_file($export)) unlink($export); $clean($root); }
echo "Media ZIP integration: export/import, repeated restore, derivatives, rollback and traversal passed.\n";
