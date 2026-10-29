<?php
declare(strict_types=1);
namespace SimpleStore\Product;

/** A colour photo is shared across sizes; an explicit combination photo wins. */
final class VariantImages
{
    /** Public photo metadata also works while the product still uses shared inventory. */
    public static function previews(array $variants, string $base): array
    {
        $result = [];
        foreach ($variants as $variant) {
            if (empty($variant['enabled'])) continue;
            $result[] = ['choices' => $variant['choices'], 'image' => self::resolve($variant, $variants, $base)];
        }
        return $result;
    }

    public static function color(array $choices): ?array
    {
        foreach ($choices as $name => $value) {
            if (in_array(mb_strtolower(trim((string) $name), 'UTF-8'), ['barva', 'color', 'colour'], true)) {
                return ['name' => $name, 'value' => $value];
            }
        }
        return null;
    }

    public static function resolve(array $variant, array $variants, string $base): string
    {
        if (($variant['image_path'] ?? '') !== '') return $variant['image_path'];
        $color = self::color($variant['choices']);
        if ($color !== null) foreach ($variants as $other) {
            if (!empty($other['enabled']) && self::color($other['choices']) === $color && ($other['image_path'] ?? '') !== '') return $other['image_path'];
        }
        return $base;
    }
}
