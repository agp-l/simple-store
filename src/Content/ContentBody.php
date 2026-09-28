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
                return self::validate($data['sections']);
            } catch (InvalidArgumentException) {
                // A damaged snapshot is shown safely as plain text instead of being executed as HTML.
            }
        }

        // Pages written by the original editor and the CLI remain editable.
        return $body === '' ? [] : [['type' => 'text', 'heading' => '', 'body' => $body]];
    }

    public static function encode(array $sections): string
    {
        return json_encode(['format' => self::FORMAT, 'sections' => self::validate($sections)],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function validate(array $sections): array
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
                ($section['type'] === 'image' && !ProductDetails::imagePath($section['body']))) {
                throw new InvalidArgumentException('Blok obsahuje neplatný text, typ nebo adresu fotografie.');
            }
            $clean[] = ['type' => $section['type'], 'heading' => $section['heading'], 'body' => $section['body']];
        }
        return $clean;
    }
}
