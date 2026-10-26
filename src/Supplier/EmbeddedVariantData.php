<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** A server-rendered JSON literal used by supported shops, never executed as JavaScript. */
final class EmbeddedVariantData
{
    public static function read(string $body): ?array
    {
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is', $body, $scripts);
        $definitions = [];
        foreach ($scripts[1] as $script) {
            if (!preg_match('~^\s*(?://<!--\s*)?var\s+data\s*=\s*(\{.*\})\s*;\s*(?://-->\s*)?$~sD', $script, $match)) continue;
            $definitions[] = json_decode($match[1], true, 40);
        }
        return count($definitions) === 1 && is_array($definitions[0]) ? $definitions[0] : null;
    }
}
