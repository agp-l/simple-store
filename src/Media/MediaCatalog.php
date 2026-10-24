<?php
declare(strict_types=1);

namespace SimpleStore\Media;

use InvalidArgumentException;
use RuntimeException;
use SimpleStore\Product\ProductDetails;

/** Folders are labels, never filesystem moves: URLs and content revisions stay intact. */
final class MediaCatalog
{
    public const GROUPS = ['all' => 'Všechny obrázky', 'products' => 'Nahráno k produktům',
        'pages' => 'Nahráno ke stránkám', 'posts' => 'Nahráno k článkům', 'unfiled' => 'Bez vlastní složky'];

    public function __construct(private string $root) { $this->root = rtrim($root, '/'); }

    public function state(): array
    {
        $file = $this->directory() . '/.library.json';
        if (is_link($file)) throw new RuntimeException('Neplatné úložiště médií.');
        if (!file_exists($file)) return ['version' => 1, 'folders' => [], 'files' => []];
        $state = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['version'] ?? null) !== 1 || !is_array($state['folders'] ?? null) || !is_array($state['files'] ?? null)) {
            throw new RuntimeException('Knihovnu médií nelze načíst. Soubor nebyl přepsán.');
        }
        return $state;
    }

    /** Serialize mutations and publish an entire new index atomically. */
    public function update(callable $change): mixed
    {
        $directory = $this->directory();
        $lockPath = $directory . '/.library.lock';
        if (is_link($lockPath)) throw new RuntimeException('Neplatný zámek knihovny.');
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Knihovna je nedostupná.');
        $temp = null;
        try {
            $state = $this->state();
            $result = $change($state);
            $temp = tempnam($directory, '.library-');
            if ($temp === false || file_put_contents($temp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false ||
                !rename($temp, $directory . '/.library.json')) throw new RuntimeException('Změny knihovny nelze uložit.');
            return $result;
        } finally {
            if (is_string($temp) && is_file($temp)) unlink($temp);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public function saveFolder(string $name, string $parent = '', string $id = ''): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 120 || preg_match('/[\x00-\x1f\/\\\\]/u', $name)) {
            throw new InvalidArgumentException('Název složky vyplň bez lomítek, nejvýše 120 bajtů.');
        }
        return $this->update(static function (array &$state) use ($name, $parent, $id): string {
            self::requireFolder($state, $parent);
            if ($id !== '') self::requireFolder($state, $id);
            if ($id === '' && count($state['folders']) >= 500) throw new InvalidArgumentException('Knihovna může mít nejvýše 500 složek.');
            $id = $id === '' ? bin2hex(random_bytes(16)) : $id;
            $cursor = $parent;
            while ($cursor !== '') {
                if ($cursor === $id) throw new InvalidArgumentException('Složku nelze vložit do sebe ani do její podsložky.');
                $cursor = $state['folders'][$cursor]['parent'];
            }
            foreach ($state['folders'] as $key => $folder) {
                if ($key !== $id && $folder['parent'] === $parent && $folder['name'] === $name) {
                    throw new InvalidArgumentException('Taková složka už existuje. Vyber ji ze seznamu.');
                }
            }
            $state['folders'][$id] = ['name' => $name, 'parent' => $parent];
            return $id;
        });
    }

    public static function requireFolder(array $state, string $folder): void
    {
        if ($folder !== '' && !isset($state['folders'][$folder])) throw new InvalidArgumentException('Složka už neexistuje. Obnov stránku.');
    }

    public static function folderLabels(array $state): array
    {
        $labels = [];
        foreach ($state['folders'] as $id => $folder) {
            $names = [$folder['name']]; $parent = $folder['parent']; $seen = [$id];
            while ($parent !== '') {
                if (!isset($state['folders'][$parent]) || in_array($parent, $seen, true)) throw new InvalidArgumentException('Neplatný strom složek.');
                $seen[] = $parent; array_unshift($names, $state['folders'][$parent]['name']); $parent = $state['folders'][$parent]['parent'];
            }
            $labels[$id] = implode(' / ', $names);
        }
        natcasesort($labels);
        return $labels;
    }

    public function assign(array $paths, string $folder): void
    {
        if ($paths === [] || count($paths) > 200) throw new InvalidArgumentException('Vyber 1 až 200 obrázků.');
        foreach ($paths as $path) if (!is_string($path) || $this->absolute($path) === null) throw new InvalidArgumentException('Obrázek už neexistuje.');
        $this->update(static function (array &$state) use ($paths, $folder): void {
            self::requireFolder($state, $folder);
            foreach ($paths as $path) $state['files'][$path]['folder'] = $folder;
        });
    }

    public function upload(array $files, string $folder): array
    {
        $library = new MediaLibrary($this->root); $paths = [];
        try {
            return $this->update(function (array &$state) use ($files, $folder, $library, &$paths): array {
                self::requireFolder($state, $folder);
                if (count($files['name'] ?? []) > 20) throw new InvalidArgumentException('Nahraj nejvýše 20 fotografií najednou.');
                $paths = $library->storeUploaded('library', bin2hex(random_bytes(16)), $files);
                foreach ($paths as $path) $state['files'][$path] = ['folder' => $folder];
                return $paths;
            });
        } catch (\Throwable $error) { $library->removeNew($paths); throw $error; }
    }

    public function absolute(string $path): ?string
    {
        if (!ProductDetails::imagePath($path) || !preg_match('/\.(?:jpe?g|png|webp|gif|avif)$/iD', $path)) return null;
        $current = $this->root;
        foreach (explode('/', $path) as $part) {
            $current .= '/' . $part;
            if (is_link($current)) return null;
        }
        return is_file($current) ? $current : null;
    }

    public function files(?array $state = null): array
    {
        $state ??= $this->state();
        if (!is_dir($this->root . '/images') || is_link($this->root . '/images')) return [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/images', \FilesystemIterator::SKIP_DOTS));
        $files = []; $visited = 0;
        foreach ($iterator as $entry) {
            if (++$visited > 40000) throw new RuntimeException('Knihovna přesáhla limit pro tento přehled.');
            $path = substr($entry->getPathname(), strlen($this->root) + 1);
            if ($entry->isLink() || !$entry->isFile() || (MediaPath::isAsset($path) && !MediaPath::isManaged($path)) || $this->absolute($path) === null) continue;
            $group = preg_match('~^images/media/(products|pages|posts)/~', $path, $match) ? $match[1] : 'library';
            $thumb = MediaPath::variant($path, 'thumb'); $card = MediaPath::variant($path, 'card');
            $files[] = ['path' => $path, 'label' => MediaPath::label($path), 'thumb' => $this->absolute($thumb) !== null ? $thumb : $path,
                'card' => $this->absolute($card) !== null ? $card : $path, 'created' => $entry->getMTime(), 'bytes' => $entry->getSize(),
                'folder' => $state['files'][$path]['folder'] ?? '', 'group' => $group];
        }
        usort($files, static fn (array $a, array $b): int => ($b['created'] <=> $a['created']) ?: strcmp($a['path'], $b['path']));
        return $files;
    }

    public function listing(string $folder = 'all', string $search = '', int $offset = 0, int $limit = 48): array
    {
        $state = $this->state();
        if (!isset(self::GROUPS[$folder])) self::requireFolder($state, $folder);
        $labels = self::folderLabels($state);
        $files = array_values(array_filter($this->files($state), static function (array $file) use ($folder, $search, $labels): bool {
            $match = $folder === 'all' || ($folder === 'unfiled' && $file['folder'] === '') || $file['group'] === $folder ||
                (isset($labels[$folder], $labels[$file['folder']]) && ($file['folder'] === $folder || str_starts_with($labels[$file['folder']], $labels[$folder] . ' / ')));
            return $match && ($search === '' || stripos($file['label'] . ' ' . $file['path'], $search) !== false);
        }));
        return ['files' => array_slice($files, $offset, $limit), 'total' => count($files), 'folders' => $labels];
    }

    private function directory(): string
    {
        $dir = $this->root;
        foreach (['images', 'media'] as $part) {
            $dir .= '/' . $part;
            if (is_link($dir)) throw new RuntimeException('Úložiště nesmí být symbolický odkaz.');
            if (!is_dir($dir) && !mkdir($dir, 0755) && !is_dir($dir)) throw new RuntimeException('Úložiště nelze vytvořit.');
        }
        return $dir;
    }
}
