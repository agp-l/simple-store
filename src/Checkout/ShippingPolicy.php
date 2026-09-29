<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** Methods and prices come from merchant configuration, never sample rates. */
final class ShippingPolicy
{
    private const CODES = ['home', 'pickup'];
    private array $methods = [];

    /** @param array<string, array{label:string,price_czk:int,requires_address:bool}> $methods */
    public function __construct(array $methods = [])
    {
        foreach ($methods as $code => $method) {
            if (!in_array($code, self::CODES, true) || !is_array($method) ||
                !is_string($method['label'] ?? null) || trim($method['label']) === '' ||
                strlen($method['label']) > 100 || preg_match('/[\x00-\x1f\x7f]/', $method['label']) ||
                !is_int($method['price_czk'] ?? null) ||
                $method['price_czk'] < 0 || $method['price_czk'] > 100000 ||
                !is_bool($method['requires_address'] ?? null) ||
                $method['requires_address'] !== ($code === 'home')) {
                throw new InvalidArgumentException('Invalid shipping method or price.');
            }
            $this->methods[$code] = [
                'code' => $code, 'label' => trim($method['label']),
                'price_czk' => $method['price_czk'],
                'requires_address' => $method['requires_address'],
            ];
        }
    }

    public function options(): array
    {
        return array_values($this->methods);
    }

    public function quote(string $code): ?int
    {
        if (!in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException('Vyber známý způsob dopravy.');
        }
        return $this->methods[$code]['price_czk'] ?? null;
    }
}
