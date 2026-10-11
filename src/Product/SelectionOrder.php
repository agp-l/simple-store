<?php
declare(strict_types=1);
namespace SimpleStore\Product;
use InvalidArgumentException;
final class SelectionOrder
{
    public static function validate(array $current, array $order, array $expected): array
    {
        if ($current !== $expected) throw new InvalidArgumentException('Výběr se mezitím změnil. Obnov stránku.');
        foreach ($order as $key) {
            if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) throw new InvalidArgumentException('Neplatné pořadí.');
        }
        $a = $current; $b = $order; sort($a); sort($b);
        if ($a !== $b || count($order) !== count(array_unique($order))) throw new InvalidArgumentException('Pořadí musí obsahovat přesně produkty z výběru.');
        return array_values($order);
    }
}
