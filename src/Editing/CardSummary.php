<?php
declare(strict_types=1);

namespace SimpleStore\Editing;

use InvalidArgumentException;

/** Keep newly edited card summaries concise; existing longer revisions remain readable. */
final class CardSummary
{
    public const MAX_CHARACTERS = 180;

    public static function validate(string $value): void
    {
        $characters = preg_match_all('/./us', $value);
        if ($characters === false || $characters > self::MAX_CHARACTERS) {
            throw new InvalidArgumentException('Perex může mít nejvýše ' . self::MAX_CHARACTERS . ' znaků.');
        }
    }
}
