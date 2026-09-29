<?php
declare(strict_types=1);

namespace SimpleStore\Media;

/** Immutable public paths. The content key, unlike a slug or category, never changes. */
final class MediaPath
{
    public static function directory(string $type, string $key): string
    {
        $folder = ['product' => 'products', 'page' => 'pages', 'post' => 'posts'][$type] ?? null;
        if ($folder === null || preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Neplatný vlastník fotografie.');
        }
        return 'images/media/' . $folder . '/' . $key;
    }

    public static function isManaged(string $path): bool
    {
        return preg_match('~^images/media/(?:products|pages|posts)/[a-f0-9]{32}/[a-z0-9-]{1,50}--[a-f0-9]{24}\.webp$~D', $path) === 1;
    }

    public static function isAsset(string $path): bool
    {
        return self::isManaged($path) ||
            preg_match('~^images/media/(?:products|pages|posts)/[a-f0-9]{32}/[a-z0-9-]{1,50}--[a-f0-9]{24}-(?:card|thumb)\.webp$~D', $path) === 1;
    }

    public static function variant(string $path, string $size): string
    {
        if (!in_array($size, ['card', 'thumb'], true) || !self::isManaged($path)) {
            return $path;
        }
        return substr($path, 0, -5) . '-' . $size . '.webp';
    }

    public static function label(string $path): string
    {
        $filename = basename($path);
        return preg_replace('/--[a-f0-9]{24}\.webp$/D', '', $filename) ?: $filename;
    }
}
