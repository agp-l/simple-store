<?php
declare(strict_types=1);

namespace SimpleStore\Media;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/** Portable collections. Imported files are decoded again; ZIP paths are never extracted. */
final class MediaArchive
{
    public const FORMAT = 'simple-store.media.v1';
    public const MAX_BYTES = 104857600;
    public const MAX_FILES = 200;

    public function __construct(private string $root) {}

    /** Caller owns the returned temporary file and must unlink it after download. */
    public function export(array $folders, array $paths = []): string
    {
        self::zipAvailable();
        if ($folders === [] && $paths === []) throw new InvalidArgumentException('Vyber složky nebo obrázky k exportu.');
        $catalog = new MediaCatalog($this->root); $state = $catalog->state(); $labels = MediaCatalog::folderLabels($state);
        foreach ($folders as $id) if (!is_string($id) || (!isset(MediaCatalog::GROUPS[$id]) && !isset($labels[$id]))) throw new InvalidArgumentException('Neplatný výběr složek.');
        foreach ($paths as $path) if (!is_string($path) || $catalog->absolute($path) === null) throw new InvalidArgumentException('Neplatný výběr obrázků.');
        $selected = [];
        foreach ($folders as $id) {
            if (isset($labels[$id])) foreach ($labels as $child => $label) {
                if ($child === $id || str_starts_with($label, $labels[$id] . ' / ')) $selected[$child] = true;
            }
        }
        $files = array_values(array_filter($catalog->files($state), static fn (array $file): bool =>
            in_array('all', $folders, true) || in_array($file['path'], $paths, true) || isset($selected[$file['folder']]) ||
            in_array($file['group'], $folders, true) || ($file['folder'] === '' && in_array('unfiled', $folders, true))));
        if (count($files) > self::MAX_FILES || array_sum(array_column($files, 'bytes')) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Jeden export může obsahovat nejvýše 200 obrázků a 100 MiB. Vyber menší část knihovny.');
        }
        if (in_array('all', $folders, true)) $selected = array_fill_keys(array_keys($labels), true);
        foreach ($files as $file) if ($file['folder'] !== '') $selected[$file['folder']] = true;
        foreach (array_keys($selected) as $id) {
            while ($id !== '') { $selected[$id] = true; $id = $state['folders'][$id]['parent']; }
        }
        $manifest = ['format' => self::FORMAT, 'folders' => array_intersect_key($state['folders'], $selected), 'files' => []];
        $temp = tempnam(sys_get_temp_dir(), 'shop-media-export-');
        if ($temp === false) throw new RuntimeException('Nelze připravit export.');
        $zip = new ZipArchive();
        try {
            if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('ZIP nelze vytvořit.');
            foreach ($files as $index => $file) {
                $absolute = $catalog->absolute($file['path']);
                if ($absolute === null) throw new RuntimeException('Obrázek během exportu zmizel. Opakuj export.');
                $entry = 'photos/' . $index . '.' . strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
                if (!$zip->addFile($absolute, $entry)) throw new RuntimeException('Obrázek nelze přidat do ZIP.');
                $manifest['files'][] = ['entry' => $entry, 'source' => $file['path'], 'folder' => $file['folder'],
                    'sha256' => hash_file('sha256', $absolute)];
            }
            if (!$zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) || !$zip->close()) {
                throw new RuntimeException('Export nelze dokončit.');
            }
            return $temp;
        } catch (\Throwable $error) {
            try { $zip->close(); } catch (\Throwable) {}
            unlink($temp); throw $error;
        }
    }

    public function import(string $archive): array
    {
        self::zipAvailable();
        if (!is_file($archive) || filesize($archive) > self::MAX_BYTES) throw new InvalidArgumentException('ZIP může mít nejvýše 100 MiB.');
        $zip = new ZipArchive(); $staged = []; $new = []; $library = new MediaLibrary($this->root);
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) throw new InvalidArgumentException('Soubor není čitelný ZIP.');
        try {
            if ($zip->numFiles > self::MAX_FILES + 1) throw new InvalidArgumentException('ZIP obsahuje příliš mnoho souborů.');
            $info = $zip->statName('manifest.json');
            if (!$info || $info['size'] > 1048576) throw new InvalidArgumentException('Chybí platný manifest.json z exportu knihovny.');
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 32, JSON_THROW_ON_ERROR);
            self::validateManifest($manifest);
            $expected = array_fill_keys(array_column($manifest['files'], 'entry'), true); $expected['manifest.json'] = true;
            $seen = []; $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat || !isset($expected[$stat['name']]) || isset($seen[$stat['name']]) || ($stat['encryption_method'] ?? 0) !== 0 ||
                    ($total += $stat['size']) > self::MAX_BYTES + 1048576) throw new InvalidArgumentException('ZIP obsahuje neočekávané, duplicitní nebo příliš velké soubory.');
                $seen[$stat['name']] = true;
            }
            foreach ($manifest['files'] as $file) {
                $stat = $zip->statName($file['entry']);
                if (!$stat || $stat['size'] < 1 || $stat['size'] > MediaLibrary::MAX_BYTES) throw new InvalidArgumentException('Fotografie chybí nebo přesahuje 12 MiB.');
                $temp = tempnam(sys_get_temp_dir(), 'shop-media-import-');
                if ($temp === false) throw new RuntimeException('Nelze připravit import.');
                $staged[] = $file + ['temp' => $temp];
                $input = $zip->getStream($file['entry']); $output = fopen($temp, 'wb');
                if ($input === false || $output === false) throw new RuntimeException('Fotografii nelze přečíst.');
                try { $bytes = stream_copy_to_stream($input, $output, MediaLibrary::MAX_BYTES + 1); }
                finally { fclose($input); fclose($output); }
                if ($bytes !== $stat['size'] || hash_file('sha256', $temp) !== $file['sha256']) throw new InvalidArgumentException('Kontrolní součet fotografie nesouhlasí. Import byl zrušen.');
            }
            $catalog = new MediaCatalog($this->root);
            return $catalog->update(function (array &$state) use ($catalog, $manifest, $staged, $library, &$new): array {
                $mapping = ['' => '']; $remaining = $manifest['folders'];
                while ($remaining !== []) {
                    foreach ($remaining as $id => $folder) {
                        if (!isset($mapping[$folder['parent']])) continue;
                        $parent = $mapping[$folder['parent']]; $target = null;
                        foreach ($state['folders'] as $key => $existing) if ($existing['name'] === $folder['name'] && $existing['parent'] === $parent) { $target = $key; break; }
                        $target ??= bin2hex(random_bytes(16));
                        $state['folders'][$target] = ['name' => $folder['name'], 'parent' => $parent];
                        $mapping[$id] = $target; unset($remaining[$id]);
                    }
                }
                if (count($state['folders']) > 500) throw new InvalidArgumentException('Po importu by knihovna přesáhla 500 složek.');
                $hashes = [];
                foreach ($catalog->files($state) as $file) {
                    $absolute = $catalog->absolute($file['path']);
                    if ($absolute === null) continue;
                    $storedHash = hash_file('sha256', $absolute);
                    $hashes[$storedHash] = $file['path'];
                    $sourceHash = $state['files'][$file['path']]['source_hash'] ?? null;
                    if (is_string($sourceHash) && ($state['files'][$file['path']]['stored_hash'] ?? null) === $storedHash) $hashes[$sourceHash] = $file['path'];
                }
                $reused = 0;
                foreach ($staged as $file) {
                    if (isset($hashes[$file['sha256']])) { $path = $hashes[$file['sha256']]; $reused++; }
                    else {
                        $path = $library->storeFile('library', bin2hex(random_bytes(16)), $file['temp'], basename($file['source']));
                        $new[] = $path; $hashes[$file['sha256']] = $path;
                    }
                    $state['files'][$path] = ['folder' => $mapping[$file['folder']], 'source_hash' => $file['sha256'], 'stored_hash' => hash_file('sha256', $catalog->absolute($path))];
                }
                return ['added' => count($new), 'reused' => $reused, 'folders' => count($manifest['folders'])];
            });
        } catch (\Throwable $error) {
            $library->removeNew($new); throw $error;
        } finally {
            $zip->close();
            foreach ($staged as $file) if (is_file($file['temp'])) unlink($file['temp']);
        }
    }

    public static function validateManifest(mixed $manifest): void
    {
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT || !is_array($manifest['folders'] ?? null) ||
            !is_array($manifest['files'] ?? null) || count($manifest['folders']) > 500 || count($manifest['files']) > self::MAX_FILES) {
            throw new InvalidArgumentException('Neplatný formát zálohy médií. Použij ZIP vytvořený exportem knihovny.');
        }
        foreach ($manifest['folders'] as $id => $folder) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id) || !is_array($folder) || !is_string($folder['name'] ?? null) ||
                trim($folder['name']) === '' || strlen($folder['name']) > 120 || preg_match('/[\x00-\x1f\/\\\\]/u', $folder['name']) ||
                !is_string($folder['parent'] ?? null)) throw new InvalidArgumentException('Neplatná složka v záloze.');
        }
        MediaCatalog::folderLabels($manifest);
        $entries = [];
        foreach ($manifest['files'] as $file) {
            if (!is_array($file) || !is_string($file['entry'] ?? null) || !preg_match('~^photos/[0-9]+\.(?:jpg|jpeg|png|webp|gif|avif)$~D', $file['entry']) ||
                isset($entries[$file['entry']]) || !is_string($file['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256']) ||
                !is_string($file['source'] ?? null) || !\SimpleStore\Product\ProductDetails::imagePath($file['source']) ||
                !is_string($file['folder'] ?? null)) throw new InvalidArgumentException('Neplatná fotografie v záloze.');
            MediaCatalog::requireFolder($manifest, $file['folder']); $entries[$file['entry']] = true;
        }
    }

    private static function zipAvailable(): void
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Hosting nemá zapnuté rozšíření PHP ZIP.');
    }
}
