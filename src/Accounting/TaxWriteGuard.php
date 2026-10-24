<?php
declare(strict_types=1);
namespace SimpleStore\Accounting;

use InvalidArgumentException;
use MeekroDB;

/** Call inside the same transaction as all movements in a submitted form. */
final class TaxWriteGuard
{
    public static function claim(MeekroDB $db, array $input): void
    {
        if (!isset($input['request_key'])) return; // Internal/legacy callers have their own uniqueness guards.
        $key = $input['request_key'];
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) throw new InvalidArgumentException('Neplatný identifikátor formuláře.');
        $db->query('INSERT IGNORE INTO shop_tax_write_requests (request_key) VALUES (%s)', $key);
        if ($db->affectedRows() !== 1) throw new InvalidArgumentException('Tento formulář už byl uložen. Zkontroluj zapsané pohyby; nevkládej tutéž platbu podruhé.');
    }
}
