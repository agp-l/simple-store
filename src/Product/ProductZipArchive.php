<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use ZipArchive;

/** Never extract an archive tree: only bounded, validated entries are streamed to private temporary files. */
final class ProductZipArchive
{
    public const MAX_ARCHIVE = 128 * 1024 * 1024;
    public const MAX_EXPANDED = 512 * 1024 * 1024;
    private ZipArchive $zip;
    private bool $opened = false;
    private array $entries = [];

    public function __construct(string $file)
    {
        if (!class_exists(ZipArchive::class)) throw new InvalidArgumentException('ZIP import vyžaduje PHP rozšíření zip (ZipArchive). JSON import funguje i bez něj.');
        if (!is_file($file) || filesize($file) > self::MAX_ARCHIVE) throw new InvalidArgumentException('ZIP může mít nejvýše 128 MiB.');
        $this->zip = new ZipArchive();
        if ($this->zip->open($file, ZipArchive::RDONLY) !== true) throw new InvalidArgumentException('ZIP nelze otevřít.');
        $this->opened = true;
        if ($this->zip->numFiles > 5000) throw new InvalidArgumentException('ZIP má příliš mnoho položek (nejvýše 5 000).');
        $total = 0;
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            if ($stat === false) throw new InvalidArgumentException('ZIP obsahuje nečitelnou položku.');
            $name = $stat['name'];
            if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') continue;
            if (strlen($name) > 500 || preg_match('~[\\\\\x00-\x1f\x7f]|^/|(^|/)\.\.?(/|$)|:~', $name)) {
                throw new InvalidArgumentException('ZIP obsahuje nebezpečnou cestu. Použij pouze složky produktů a images/.');
            }
            if (str_ends_with($name, '/')) continue;
            if (isset($this->entries[$name])) throw new InvalidArgumentException('ZIP obsahuje duplicitní cestu: ' . $name);
            $total += $stat['size'];
            if ($total > self::MAX_EXPANDED) throw new InvalidArgumentException('Rozbalená data ZIP přesahují 512 MiB.');
            $opsys = $attributes = 0;
            $this->zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $stat['unsafe'] = (($attributes >> 16) & 0170000) === 0120000 || ($stat['encryption_method'] ?? 0) !== 0;
            $this->entries[$name] = $stat;
        }
    }

    public function __destruct() { if ($this->opened) $this->zip->close(); }

    public function products(): array
    {
        $folders = [];
        foreach (array_keys($this->entries) as $name) {
            $parts = explode('/', $name);
            if (count($parts) > 1) $folders[$parts[0]] = true;
        }
        if ($folders === [] || count($folders) > 200) throw new InvalidArgumentException('ZIP musí mít 1 až 200 složek produktů, každou s product.json.');
        $names = array_keys($folders);
        sort($names, SORT_STRING);
        return $names;
    }

    public function images(string $folder): array
    {
        $images = [];
        foreach ($this->entries as $name => $stat) {
            if (!str_starts_with($name, $folder . '/')) continue;
            $relative = substr($name, strlen($folder) + 1);
            if ($relative === 'product.json') continue;
            if (!preg_match('~^images/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)$~iD', $relative)) {
                throw new InvalidArgumentException('Nepodporovaný soubor produktu: ' . $relative);
            }
            if ($stat['unsafe'] || $stat['size'] > 12 * 1024 * 1024) throw new InvalidArgumentException('Fotografie je šifrovaná, odkaz nebo přesahuje 12 MiB: ' . $relative);
            $images[] = $relative;
        }
        sort($images, SORT_STRING);
        return $images;
    }

    public function read(string $name, int $limit): string
    {
        $stat = $this->entries[$name] ?? null;
        if ($stat === null || $stat['unsafe'] || $stat['size'] > $limit) throw new InvalidArgumentException('Chybějící, příliš velký nebo nepodporovaný soubor: ' . $name);
        $stream = $this->zip->getStream($name);
        if ($stream === false) throw new InvalidArgumentException('Soubor v ZIP nelze přečíst: ' . $name);
        try { $data = stream_get_contents($stream, $limit + 1); } finally { fclose($stream); }
        if ($data === false || strlen($data) !== $stat['size'] || strlen($data) > $limit ||
            sprintf('%u', crc32($data)) !== sprintf('%u', $stat['crc'])) {
            throw new InvalidArgumentException('Soubor v ZIP je poškozený: ' . $name);
        }
        return $data;
    }
}
