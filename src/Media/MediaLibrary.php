<?php
declare(strict_types=1);

namespace SimpleStore\Media;

use GdImage;
use InvalidArgumentException;
use RuntimeException;

/** Re-encode untrusted uploads and make three immutable sizes. No original upload is served. */
final class MediaLibrary
{
    private const MAX_FILES = 12;
    private const MAX_BYTES = 12 * 1024 * 1024;
    private const MAX_PIXELS = 20000000;

    public function __construct(private string $root) {}

    public function files(string $type, string $key): array
    {
        $relative = MediaPath::directory($type, $key);
        $folder = $this->root . '/' . $relative;
        if (!is_dir($folder) || is_link($folder)) return [];
        $files = [];
        foreach (new \DirectoryIterator($folder) as $entry) {
            if (!$entry->isFile() || $entry->isLink()) continue;
            $path = $relative . '/' . $entry->getFilename();
            if (!MediaPath::isManaged($path)) continue;
            $files[] = [
                'path' => $path,
                'card' => MediaPath::variant($path, 'card'),
                'thumb' => MediaPath::variant($path, 'thumb'),
                'label' => MediaPath::label($path),
                'created' => $entry->getMTime(),
            ];
        }
        usort($files, static fn (array $a, array $b): int =>
            ($b['created'] <=> $a['created']) ?: strcmp($b['path'], $a['path']));
        return $files;
    }

    /** PHP's multiple-file $_FILES entry. All files are accepted or none are kept. */
    public function storeUploaded(string $type, string $key, array $files): array
    {
        MediaPath::directory($type, $key);
        if (!isset($files['name'], $files['tmp_name'], $files['error'], $files['size']) ||
            !is_array($files['name']) || count($files['name']) < 1 ||
            count($files['name']) > self::MAX_FILES) {
            throw new InvalidArgumentException('Vyber 1 až 12 fotografií najednou.');
        }
        $count = count($files['name']);
        foreach (['tmp_name', 'error', 'size'] as $field) {
            if (!is_array($files[$field]) || count($files[$field]) !== $count) {
                throw new InvalidArgumentException('Nahrávání fotografií nebylo úplné.');
            }
        }
        $paths = [];
        try {
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK ||
                    !is_string($files['tmp_name'][$i]) || !is_uploaded_file($files['tmp_name'][$i]) ||
                    !is_int($files['size'][$i]) || $files['size'][$i] < 1 ||
                    $files['size'][$i] > self::MAX_BYTES || !is_string($files['name'][$i])) {
                    throw new InvalidArgumentException('Fotografii se nepodařilo nahrát. Každý soubor může mít nejvýše 12 MB.');
                }
                $paths[] = $this->storeFile($type, $key, $files['tmp_name'][$i], $files['name'][$i]);
            }
            return $paths;
        } catch (\Throwable $error) {
            $this->removeNew($paths);
            throw $error;
        }
    }

    /** Also accepts a local fixture in tests. The HTTP entry point must call storeUploaded. */
    public function storeFile(string $type, string $key, string $source, string $filename): string
    {
        $relative = MediaPath::directory($type, $key);
        if (!extension_loaded('gd')) {
            throw new InvalidArgumentException('Nahrávání vyžaduje zapnuté rozšíření PHP GD.');
        }
        if (!is_file($source) || filesize($source) === false || filesize($source) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Soubor je prázdný nebo příliš velký (nejvýše 12 MB).');
        }
        $info = @getimagesize($source);
        if ($info === false || $info[0] < 1 || $info[1] < 1 ||
            $info[0] * $info[1] > self::MAX_PIXELS ||
            !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('Použij JPG, PNG nebo WebP s rozlišením nejvýše 20 megapixelů.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        $expected = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
        if ($mime !== $expected[$info[2]]) {
            throw new InvalidArgumentException('Soubor neodpovídá formátu fotografie.');
        }
        if ($info[2] === IMAGETYPE_WEBP && !function_exists('imagecreatefromwebp')) {
            throw new InvalidArgumentException('Toto PHP neumí číst WebP. Nahraj fotografii jako JPG nebo PNG.');
        }
        if (($info[2] === IMAGETYPE_JPEG && !function_exists('imagecreatefromjpeg')) ||
            ($info[2] === IMAGETYPE_PNG && !function_exists('imagecreatefrompng'))) {
            throw new InvalidArgumentException('Toto PHP neumí číst vybraný formát fotografie.');
        }
        $format = function_exists('imagewebp') ? 'webp' : ($info[2] === IMAGETYPE_JPEG ? 'jpg' : 'png');
        if (!function_exists('image' . ($format === 'jpg' ? 'jpeg' : $format))) {
            throw new InvalidArgumentException('Toto PHP neumí uložit vybraný formát fotografie.');
        }
        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        };
        if (!$image instanceof GdImage) {
            throw new InvalidArgumentException('Fotografii se nepodařilo přečíst.');
        }
        try {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $metadata = @exif_read_data($source);
                $orientation = is_array($metadata) ? (int) ($metadata['Orientation'] ?? 1) : 1;
                if ($orientation === 3 || $orientation === 6 || $orientation === 8) {
                    $rotated = imagerotate($image, [3 => 180, 6 => -90, 8 => 90][$orientation], 0);
                    if ($rotated instanceof GdImage) {
                        imagedestroy($image);
                        $image = $rotated;
                    }
                }
            }
            $label = $this->slug($filename);
            $path = $relative . '/' . $label . '--' . bin2hex(random_bytes(12)) . '.' . $format;
            $folder = $this->root . '/' . $relative;
            $this->prepareDirectory($relative);
            $created = [];
            try {
                foreach ([[$path, 1800], [MediaPath::variant($path, 'card'), 960],
                    [MediaPath::variant($path, 'thumb'), 240]] as [$target, $size]) {
                    $this->writeSize($image, $folder . '/' . basename($target), $size, $format);
                    $created[] = $target;
                }
            } catch (\Throwable $error) {
                foreach ($created as $target) @unlink($this->root . '/' . $target);
                throw $error;
            }
            return $path;
        } finally {
            imagedestroy($image);
        }
    }

    /** Only paths freshly created in this request may be removed after a failed revision. */
    public function removeNew(array $paths): void
    {
        foreach ($paths as $path) {
            if (!MediaPath::isManaged($path)) continue;
            foreach ([$path, MediaPath::variant($path, 'card'), MediaPath::variant($path, 'thumb')] as $file) {
                @unlink($this->root . '/' . $file);
            }
        }
    }

    /** Permanently remove one owned original and both generated sizes. */
    public function deleteManaged(string $type, string $key, string $path): void
    {
        $relative = MediaPath::directory($type, $key);
        if (!MediaPath::isManaged($path) || dirname($path) !== $relative) {
            throw new InvalidArgumentException('Fotografie nepatří do této knihovny.');
        }
        $folder = $this->root;
        foreach (explode('/', $relative) as $part) {
            $folder .= '/' . $part;
            if (is_link($folder)) throw new RuntimeException('Složka fotografií nesmí být symbolický odkaz.');
        }
        if (!is_dir($folder) || !is_writable($folder)) {
            throw new RuntimeException('Složku fotografií nelze upravit. Zkontroluj práva k images/.');
        }
        $targets = [$path, MediaPath::variant($path, 'card'), MediaPath::variant($path, 'thumb')];
        foreach ($targets as $target) {
            $file = $folder . '/' . basename($target);
            if (is_link($file) || (file_exists($file) && !is_file($file))) {
                throw new RuntimeException('Fotografie nesmí být symbolický odkaz ani složka.');
            }
        }
        if (!is_file($folder . '/' . basename($path))) {
            throw new InvalidArgumentException('Fotografie už v knihovně není. Obnov stránku.');
        }
        foreach (array_reverse($targets) as $target) {
            $file = $folder . '/' . basename($target);
            if (is_file($file) && !@unlink($file)) {
                throw new RuntimeException('Fotografii nelze smazat z disku. Zkontroluj práva k images/.');
            }
        }
    }

    private function prepareDirectory(string $relative): void
    {
        $folder = $this->root;
        $path = '';
        foreach (explode('/', $relative) as $part) {
            $path .= ($path === '' ? '' : '/') . $part;
            $folder .= '/' . $part;
            if (is_link($folder)) throw new RuntimeException('Složka fotografií nesmí být symbolický odkaz.');
            if (!is_dir($folder) && !@mkdir($folder, 0775) && !is_dir($folder)) {
                throw new RuntimeException('Složku ' . $path . '/ nelze vytvořit. Zkontroluj práva k její nadřazené složce.');
            }
        }
    }

    private function writeSize(GdImage $source, string $target, int $max, string $format): void
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $max / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $result = imagecreatetruecolor($newWidth, $newHeight);
        if (!$result instanceof GdImage) throw new RuntimeException('Fotografii nelze zpracovat.');
        try {
            if ($format !== 'jpg') {
                imagealphablending($result, false);
                imagesavealpha($result, true);
                $transparent = imagecolorallocatealpha($result, 0, 0, 0, 127);
                imagefilledrectangle($result, 0, 0, $newWidth, $newHeight, $transparent);
            }
            if (!imagecopyresampled($result, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height)) {
                throw new RuntimeException('Fotografii nelze zmenšit.');
            }
            $temporary = tempnam(dirname($target), '.image-');
            if ($temporary === false) throw new RuntimeException('Fotografii nelze uložit.');
            try {
                $saved = match ($format) {
                    'webp' => imagewebp($result, $temporary, 86),
                    'jpg' => imagejpeg($result, $temporary, 86),
                    'png' => imagepng($result, $temporary, 6),
                };
                if (!$saved || !is_file($temporary) || filesize($temporary) === 0 ||
                    !chmod($temporary, 0644) || !rename($temporary, $target)) {
                    throw new RuntimeException('Fotografii nelze uložit.');
                }
            } finally {
                if (is_file($temporary)) @unlink($temporary);
            }
        } finally {
            imagedestroy($result);
        }
    }

    private function slug(string $filename): string
    {
        $name = pathinfo(basename(str_replace('\\', '/', $filename)), PATHINFO_FILENAME);
        if (function_exists('iconv')) $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $name = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
        return trim(substr($name, 0, 42), '-') ?: 'fotografie';
    }
}
