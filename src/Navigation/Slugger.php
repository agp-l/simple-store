<?php
declare(strict_types=1);

namespace SimpleStore\Navigation;

use InvalidArgumentException;

final class Slugger
{
    public static function fromTitle(string $title): string
    {
        // Transliterate Czech characters even when iconv is not installed.
        $ascii = strtr($title, [
            'á'=>'a', 'č'=>'c', 'ď'=>'d', 'é'=>'e', 'ě'=>'e', 'í'=>'i', 'ň'=>'n', 'ó'=>'o',
            'ř'=>'r', 'š'=>'s', 'ť'=>'t', 'ú'=>'u', 'ů'=>'u', 'ý'=>'y', 'ž'=>'z',
            'Á'=>'A', 'Č'=>'C', 'Ď'=>'D', 'É'=>'E', 'Ě'=>'E', 'Í'=>'I', 'Ň'=>'N', 'Ó'=>'O',
            'Ř'=>'R', 'Š'=>'S', 'Ť'=>'T', 'Ú'=>'U', 'Ů'=>'U', 'Ý'=>'Y', 'Ž'=>'Z',
        ]);
        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ascii);
            if ($transliterated !== false) {
                $ascii = $transliterated;
            }
        }
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)) ?? '', '-');
        $slug = trim(substr($slug, 0, 190), '-');
        if ($slug === '') {
            throw new InvalidArgumentException('Název musí obsahovat alespoň jedno písmeno nebo číslici pro adresu.');
        }
        return $slug;
    }
}
