<?php
declare(strict_types=1);
namespace SimpleStore\Content;

/** Stable category identifiers shared by the editor, JSON transfer and public filters. */
final class BlogTopics
{
    public const LABELS = [
        'cestopisy' => 'Cestopisy', 'spani-venku' => 'Spaní venku',
        'vybaveni' => 'Vybavení v praxi', 'rady-na-cesty' => 'Rady na cesty',
        'zivot-na-cestach' => 'Život na cestách', 'zapisnik' => 'Zápisník',
    ];
    public static function validate(mixed $topic): string
    {
        if (!is_string($topic) || !isset(self::LABELS[$topic])) throw new \InvalidArgumentException('Vyber platnou kategorii blogu.');
        return $topic;
    }
    public static function minutes(string $body): int
    {
        $text = implode(' ', array_map(static fn(array $s): string => $s['type'] === 'image' ? '' : $s['heading'].' '.$s['body'], ContentBody::decode($body)));
        return max(1, (int) ceil(count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []) / 220));
    }
}
