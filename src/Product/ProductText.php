<?php
declare(strict_types=1);

namespace SimpleStore\Product;

/** Render two small formatting shortcuts without accepting raw HTML from the editor. */
final class ProductText
{
    public static function inline(string $text): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $parts = preg_split('/(\*\*[^*\r\n]+\*\*|\[[^\]\r\n]+\]\(https:\/\/[^\s)]+\))/u',
            $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';
        foreach ($parts as $part) {
            if (preg_match('/^\*\*([^*]+)\*\*$/uD', $part, $match)) {
                $html .= '<strong>' . $escape($match[1]) . '</strong>';
            } elseif (preg_match('~^\[([^\]]+)\]\((https://[^\s)]+)\)$~uD', $part, $match) &&
                strlen($match[2]) <= 2000 && filter_var($match[2], FILTER_VALIDATE_URL)) {
                $html .= '<a href="' . $escape($match[2]) . '">' . $escape($match[1]) . '</a>';
            } else {
                $html .= $escape($part);
            }
        }
        return $html;
    }
}
