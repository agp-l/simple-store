<?php
declare(strict_types=1);

namespace SimpleStore\Checkout;

use InvalidArgumentException;
use SimpleStore\Product\ProductDetails;
use SimpleStore\Product\ProductRepository;

/** Resolve cart identities against current published products; browser prices are never accepted. */
final class CartService
{
    public function __construct(private ProductRepository $products, private array $languages = ['cs'])
    {
    }

    /** Form choices are indexed to match the product's current option-group order. */
    public function add(CartSession $cart, string $key, string $language, array $choices, int $quantity = 1): string
    {
        $product = $this->publishedProduct($key, $language);
        if ($product === null || !in_array($product['stock_status'] ?? '', ['in_stock', 'on_order'], true)) {
            throw new InvalidArgumentException('Tento produkt už není možné přidat do košíku.');
        }
        $options = self::optionsFromForm($product, $choices);
        return $cart->add($key, $language, $options, $quantity);
    }

    /**
     * Invalid lines remain visible so the customer can remove them; they block checkout.
     * No order may use a partial subtotal.
     */
    public function summary(CartSession $cart): array
    {
        $stored = $cart->state()['items'];
        $items = [];
        $issues = [];
        $count = 0;
        $subtotal = 0;
        foreach ($stored as $lineId => $line) {
            if (!is_array($line) || !is_string($lineId)) {
                $issues[] = 'Košík obsahuje poškozenou položku. Odeber ji a přidej znovu.';
                continue;
            }
            $key = $line['key'] ?? '';
            $language = $line['language'] ?? '';
            $options = $line['options'] ?? [];
            $quantity = $line['quantity'] ?? 0;
            try {
                $identityValid = is_string($key) && is_string($language) && is_array($options) &&
                    hash_equals($lineId, CartSession::lineId($key, $language, $options));
            } catch (InvalidArgumentException $error) {
                $identityValid = false;
            }
            if (!$identityValid || !is_int($quantity) || $quantity < 1 || $quantity > 99) {
                $issues[] = 'Košík obsahuje poškozenou položku. Odeber ji a přidej znovu.';
                continue;
            }
            $count += $quantity;
            try {
                $product = $this->publishedProduct($key, $language);
            } catch (InvalidArgumentException $error) {
                $product = null;
            }
            $issue = null;
            if ($product === null) {
                $issue = 'Produkt už není v nabídce.';
            } elseif (!in_array($product['stock_status'] ?? '', ['in_stock', 'on_order'], true)) {
                $issue = 'Produkt momentálně není skladem.';
            } elseif (!self::validStoredOptions($product, $options)) {
                $issue = 'Možnosti produktu se změnily. Odeber položku a vyber variantu znovu.';
            }
            $price = $product === null ? null : filter_var($product['price_czk'] ?? null, FILTER_VALIDATE_INT);
            if ($price === false || $price === null || $price < 1 || $price > 10000000) {
                $issue ??= 'Cenu produktu se nepodařilo ověřit.';
                $price = null;
            }
            if ($issue !== null) $issues[] = $issue;
            $lineTotal = $issue === null ? $price * $quantity : null;
            if ($lineTotal !== null) $subtotal += $lineTotal;
            $items[] = [
                'line_id' => $lineId,
                'product_key' => $key,
                'language' => $language,
                'slug' => $product['slug'] ?? '',
                'name' => $product['name'] ?? 'Nedostupný produkt',
                'image_path' => $product['image_path'] ?? '',
                'options' => $options,
                'quantity' => $quantity,
                'unit_price_czk' => $price,
                'line_total_czk' => $lineTotal,
                'stock_status' => $product['stock_status'] ?? '',
                'issue' => $issue,
            ];
        }
        if ($issues === [] && $subtotal > 9999999) {
            $issues[] = 'Celková částka přesahuje limit objednávky. Uprav počet kusů v košíku.';
        }
        return [
            'items' => $items,
            'count' => $count,
            'subtotal_czk' => $issues === [] ? $subtotal : null,
            'issues' => $issues,
            'can_continue' => $count > 0 && $issues === [],
        ];
    }

    private function publishedProduct(string $key, string $language): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1 || !in_array($language, $this->languages, true)) {
            throw new InvalidArgumentException('Neplatný produkt nebo jazyk.');
        }
        return $this->products->findPublishedByKey($key, $language);
    }

    /** @return array<string, string> */
    private static function optionsFromForm(array $product, array $choices): array
    {
        $groups = ProductDetails::decode($product['details_json'] ?? null, $product['sizes'] ?? '')['options'];
        if (count($choices) !== count($groups) ||
            ($choices !== [] && array_keys($choices) !== range(0, count($choices) - 1))) {
            throw new InvalidArgumentException('Vyber všechny možnosti produktu.');
        }
        $selected = [];
        foreach ($groups as $index => $group) {
            $value = $choices[$index] ?? null;
            if (!is_string($value) || !in_array($value, $group['values'], true)) {
                throw new InvalidArgumentException('Vybraná možnost už není dostupná.');
            }
            $selected[$group['name']] = $value;
        }
        return $selected;
    }

    private static function validStoredOptions(array $product, array $selected): bool
    {
        $groups = ProductDetails::decode($product['details_json'] ?? null, $product['sizes'] ?? '')['options'];
        if (count($selected) !== count($groups)) return false;
        foreach ($groups as $group) {
            if (!isset($selected[$group['name']]) || !is_string($selected[$group['name']]) ||
                !in_array($selected[$group['name']], $group['values'], true)) return false;
        }
        return true;
    }
}
