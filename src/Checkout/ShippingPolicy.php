<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;

/** Carrier identity and server-side prices. Saved orders keep their own snapshot. */
final class ShippingPolicy
{
    private const CATALOG = [
        'ppl_pickup' => ['label' => 'PPL – výdejní místo nebo box', 'price_czk' => 80, 'group' => 'pickup', 'locator_url' => 'https://www.ppl.cz/mapa-vydejnich-mist'],
        'zasilkovna_pickup' => ['label' => 'Zásilkovna – výdejní místo', 'price_czk' => 90, 'group' => 'pickup', 'locator_url' => 'https://mapa.zasilkovna.cz/pobocky'],
        'gls_pickup' => ['label' => 'GLS – ParcelShop', 'price_czk' => 59, 'group' => 'pickup', 'locator_url' => 'https://maps.gls-czech.cz/'],
        'balikovna_pickup' => ['label' => 'Balíkovna – výdejní místo nebo box', 'price_czk' => 120, 'group' => 'pickup', 'locator_url' => 'https://www.balikovna.cz/cs/vyhledat-balikovnu'],
        'gls_home' => ['label' => 'GLS – na adresu', 'price_czk' => 79, 'group' => 'home', 'locator_url' => ''],
        'ppl_home' => ['label' => 'PPL – na adresu', 'price_czk' => 99, 'group' => 'home', 'locator_url' => ''],
        'ceska_posta_home' => ['label' => 'Česká pošta – Balík Do ruky', 'price_czk' => 121, 'group' => 'home', 'locator_url' => ''],
        'dpd_home' => ['label' => 'DPD – na adresu', 'price_czk' => 99, 'group' => 'home', 'locator_url' => ''],
        'zasilkovna_home' => ['label' => 'Zásilkovna domů HD', 'price_czk' => 121, 'group' => 'home', 'locator_url' => ''],
    ];
    private array $methods = [];

    public static function defaults(): array
    {
        $methods = [];
        foreach (self::CATALOG as $code => $definition) {
            $methods[$code] = ['label' => $definition['label'],
                'price_czk' => $definition['price_czk'],
                'requires_address' => $definition['group'] === 'home', 'enabled' => true];
        }
        return $methods;
    }

    public static function isPickup(string $code): bool
    {
        return $code === 'pickup' || (self::CATALOG[$code]['group'] ?? '') === 'pickup';
    }

    public static function known(string $code): bool
    {
        return isset(self::CATALOG[$code]) || in_array($code, ['home', 'pickup'], true);
    }

    public function __construct(array $methods = [])
    {
        foreach ($methods as $code => $method) {
            if (!is_string($code) || !self::known($code) || !is_array($method) ||
                !is_string($method['label'] ?? null) || trim($method['label']) === '' ||
                strlen($method['label']) > 120 || preg_match('/[\x00-\x1f\x7f]/', $method['label']) ||
                !is_int($method['price_czk'] ?? null) ||
                $method['price_czk'] < 0 || $method['price_czk'] > 100000 ||
                !is_bool($method['requires_address'] ?? null) ||
                $method['requires_address'] === self::isPickup($code) ||
                !is_bool($method['enabled'] ?? true)) {
                throw new InvalidArgumentException('Neplatný způsob nebo cena dopravy.');
            }
            if (($method['enabled'] ?? true) === false) continue;
            $this->methods[$code] = [
                'code' => $code, 'label' => trim($method['label']),
                'price_czk' => $method['price_czk'],
                'requires_address' => $method['requires_address'],
                'group' => self::isPickup($code) ? 'pickup' : 'home',
                'locator_url' => self::CATALOG[$code]['locator_url'] ?? '',
            ];
        }
    }

    public function options(): array
    {
        return array_values($this->methods);
    }

    public function method(string $code): ?array
    {
        if (!self::known($code)) {
            throw new InvalidArgumentException('Vyber známý způsob dopravy.');
        }
        return $this->methods[$code] ?? null;
    }

    public function quote(string $code): ?int
    {
        return $this->method($code)['price_czk'] ?? null;
    }
}
