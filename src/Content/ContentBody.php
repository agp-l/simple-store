<?php
declare(strict_types=1);

namespace SimpleStore\Content;

use InvalidArgumentException;
use SimpleStore\Product\ProductDetails;

/** Store the editable blocks in the existing body column without changing older rows. */
final class ContentBody
{
    private const FORMAT = 'simple-store-blocks-v1';
    public const TYPES = ['text' => 'Text', 'list' => 'Seznam', 'table' => 'Tabulka', 'image' => 'Fotografie'];

    public static function decode(string $body): array
    {
        $data = json_decode($body, true);
        if (is_array($data) && ($data['format'] ?? null) === self::FORMAT &&
            isset($data['sections']) && is_array($data['sections'])) {
            try {
                return self::validate($data['sections'], true);
            } catch (InvalidArgumentException) {
                // A damaged snapshot is shown safely as plain text instead of being executed as HTML.
            }
        }

        // Pages written by the original editor and the CLI remain editable.
        return $body === '' ? [] : [['type' => 'text', 'heading' => '', 'body' => $body]];
    }

    /** null = automatic first image, empty string = deliberately no cover. */
    public static function coverSetting(string $body): ?string
    {
        $data = json_decode($body, true);
        $cover = is_array($data) && ($data['format'] ?? null) === self::FORMAT ? ($data['cover_path'] ?? null) : null;
        return is_string($cover) && ($cover === '' || ProductDetails::readableImagePath($cover)) ? $cover : null;
    }

    public static function cover(string $body): string
    {
        $cover = self::coverSetting($body);
        if ($cover !== null) return $cover;
        foreach (self::decode($body) as $section) {
            if ($section['type'] === 'image') return $section['body'];
        }
        return '';
    }

    public static function topic(string $body): string
    {
        $data = json_decode($body, true);
        $topic = is_array($data) && ($data['format'] ?? null) === self::FORMAT ? ($data['topic'] ?? '') : '';
        return is_string($topic) && isset(BlogTopics::LABELS[$topic]) ? $topic : 'zapisnik';
    }

    public static function encode(array $sections, ?string $cover = null, string $topic = 'zapisnik'): string
    {
        if ($cover !== null && $cover !== '' && !ProductDetails::imagePath($cover)) {
            throw new InvalidArgumentException('Náhled musí odkazovat na obrázek ve složce images/.');
        }
        $data = ['format' => self::FORMAT, 'sections' => self::validate($sections)];
        if ($cover !== null) $data['cover_path'] = $cover;
        if (BlogTopics::validate($topic) !== 'zapisnik') $data['topic'] = $topic;
        return json_encode($data,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function validate(array $sections, bool $reading = false): array
    {
        if (count($sections) > 60) {
            throw new InvalidArgumentException('Stránka může mít nejvýše 60 bloků.');
        }
        $clean = [];
        foreach ($sections as $section) {
            if (!is_array($section) || !isset($section['type'], $section['heading'], $section['body']) ||
                !is_string($section['type']) || !isset(self::TYPES[$section['type']]) ||
                !is_string($section['heading']) || !is_string($section['body']) ||
                strlen($section['heading']) > 255 || strlen($section['body']) > 300000 ||
                ($section['type'] === 'image' && !($reading ? ProductDetails::readableImagePath($section['body']) : ProductDetails::imagePath($section['body'])))) {
                throw new InvalidArgumentException('Blok obsahuje neplatný text, typ nebo adresu fotografie.');
            }
            $clean[] = ['type' => $section['type'], 'heading' => $section['heading'], 'body' => $section['body']];
        }
        return $clean;
    }
}
