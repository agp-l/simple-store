<?php
declare(strict_types=1);

namespace SimpleStore\Database;

use RuntimeException;

/** Splits a checked-in SQL script without breaking semicolons in quoted values. */
final class SqlStatementParser
{
    /** @return list<string> */
    public static function split(string $source): array
    {
        $statements = [];
        $buffer = '';
        $quote = '';
        $comment = '';
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            $next = $source[$i + 1] ?? '';
            if ($comment === 'line') {
                if ($char === "\n") {
                    $comment = '';
                    $buffer .= ' ';
                }
                continue;
            }
            if ($comment === 'block') {
                if ($char === '*' && $next === '/') {
                    $comment = '';
                    $buffer .= ' ';
                    $i++;
                }
                continue;
            }
            if ($quote !== '') {
                $buffer .= $char;
                if ($char === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = '';
                    }
                }
                continue;
            }
            if (($char === '-' && $next === '-' && ctype_space($source[$i + 2] ?? ' ')) || $char === '#') {
                $comment = 'line';
                if ($char === '-') $i++;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $comment = 'block';
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                if (trim($buffer) !== '') $statements[] = trim($buffer);
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        if ($quote !== '' || $comment === 'block' || trim($buffer) !== '') {
            throw new RuntimeException('Soubor SQL obsahuje neúplný příkaz.');
        }
        return $statements;
    }
}
