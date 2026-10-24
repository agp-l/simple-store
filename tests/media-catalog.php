<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Media\MediaCatalog;
use SimpleStore\Media\MediaArchive;
use SimpleStore\Media\MediaPath;

$check = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$reject = static function (callable $call): void { try { $call(); } catch (InvalidArgumentException) { return; } throw new RuntimeException('Invalid media operation was accepted.'); };
$root = sys_get_temp_dir() . '/media-catalog-' . bin2hex(random_bytes(8));
mkdir($root . '/images/legacy', 0700, true);
$clean = static function (string $dir) use (&$clean): void { foreach (new DirectoryIterator($dir) as $entry) { if ($entry->isDot()) continue; if ($entry->isDir() && !$entry->isLink()) $clean($entry->getPathname()); else unlink($entry->getPathname()); } rmdir($dir); };
try {
    file_put_contents($root . '/images/legacy/old.jpg', 'legacy image');
    $catalog = new MediaCatalog($root);
    $parent = $catalog->saveFolder('Výpravy'); $child = $catalog->saveFolder('Podzim', $parent);
    $catalog->assign(['images/legacy/old.jpg'], $child);
    $check($catalog->listing($parent)['total'] === 1 && $catalog->listing('unfiled')['total'] === 0, 'Parent listing must include descendants.');
    $catalog->saveFolder('Výlety', '', $parent);
    $check(file_get_contents($root . '/images/legacy/old.jpg') === 'legacy image' && $catalog->listing($child)['files'][0]['path'] === 'images/legacy/old.jpg', 'Folder rename must not touch URL or bytes.');
    $catalog->assign(['images/legacy/old.jpg'], '');
    $check($catalog->listing('unfiled')['total'] === 1, 'Removing folder membership lost file.');
    $before = $catalog->state();
    $reject(fn () => $catalog->saveFolder('Cycle', $child, $parent));
    $reject(fn () => $catalog->saveFolder('Výlety'));
    $reject(fn () => $catalog->saveFolder('../escape'));
    $reject(fn () => $catalog->assign(['images/legacy/old.jpg', 'images/missing.jpg'], $child));
    $check($before === $catalog->state(), 'Failed assignment partly changed catalog.');
    $managed = MediaPath::directory('library', str_repeat('a', 32)) . '/image--' . str_repeat('b', 24) . '.png';
    mkdir(dirname($root . '/' . $managed), 0700, true);
    foreach ([$managed, MediaPath::variant($managed, 'thumb'), MediaPath::variant($managed, 'card')] as $path) file_put_contents($root . '/' . $path, 'fixture');
    $check($catalog->listing()['total'] === 2 && $catalog->listing('all', 'image--')['total'] === 1, 'Derivatives must not duplicate results.');
    symlink($root . '/images/legacy/old.jpg', $root . '/images/link.jpg');
    $check($catalog->absolute('images/link.jpg') === null && $catalog->absolute('images/../secret.jpg') === null && $catalog->listing()['total'] === 2, 'Symlink or traversal accepted.');
    $manifest = ['format' => MediaArchive::FORMAT, 'folders' => $catalog->state()['folders'], 'files' => [
        ['entry' => 'photos/0.jpg', 'source' => 'images/legacy/old.jpg', 'folder' => $child, 'sha256' => str_repeat('c', 64)],
    ]];
    MediaArchive::validateManifest($manifest);
    foreach (['../evil.jpg', '/photos/0.jpg', 'photos/0.php', 'photos/a/../0.jpg'] as $entry) {
        $bad = $manifest; $bad['files'][0]['entry'] = $entry; $reject(fn () => MediaArchive::validateManifest($bad));
    }
    $bad = $manifest; $bad['folders'][$parent]['parent'] = $child; $reject(fn () => MediaArchive::validateManifest($bad));
    $bad = $manifest; $bad['files'][] = $bad['files'][0]; $reject(fn () => MediaArchive::validateManifest($bad));
} finally { $clean($root); }
echo "Media catalog: immutable URLs, descendants, validation, atomic assignment and symlinks passed.\n";
