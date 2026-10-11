<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;

/** Variant reservations share the surrounding order transaction and product inventory mutex. */
final class ProductVariantStock
{
    public function __construct(private MeekroDB $db) {}

    public function reserve(int $orderId, string $key, array $items): bool
    {
        $repository = new ProductVariantRepository($this->db);
        $snapshot = $repository->snapshot($key, true);
        $hasVariants = array_filter($items, static fn (array $item): bool => !empty($item['variant_key'])) !== [];
        if (!$snapshot['enabled']) {
            if ($hasVariants) throw new InvalidArgumentException('Sklad variant se změnil. Obnov košík.');
            return false;
        }
        $requested = [];
        foreach ($items as $item) {
            $id = $item['variant_key'] ?? '';
            $product = $this->db->queryFirstRow('SELECT * FROM shop_product_revisions WHERE product_key=%s
                AND language=%s AND published=1 AND active_product_key IS NOT NULL LIMIT 1 FOR UPDATE', $key, $item['language']);
            $resolved = $product === null ? null : ProductVariantRepository::resolve($product + ['variant_inventory' => $snapshot], $id);
            if ($resolved === null || $resolved['availability_status'] === 'out_of_stock' ||
                (int) $resolved['price_czk'] !== (int) $item['unit_price_czk'] ||
                $resolved['variant_choices'] != ($item['options'] ?? [])) {
                throw new InvalidArgumentException('Vybraná varianta nebo její cena se změnila. Obnov košík.');
            }
            if ($resolved['stock_status'] !== 'in_stock') continue;
            $requested[$id] = ($requested[$id] ?? 0) + (int) $item['quantity'];
        }
        ksort($requested, SORT_STRING);
        foreach ($requested as $id => $quantity) {
            $available = (int) $this->db->queryFirstField('SELECT available_quantity FROM shop_product_variants WHERE product_key=%s AND variant_key=%s FOR UPDATE', $key, $id);
            if ($quantity < 1 || $available < $quantity) throw new InvalidArgumentException('Vybraná varianta už není v požadovaném množství skladem.');
            $this->db->query('UPDATE shop_product_variants SET available_quantity=available_quantity-%i WHERE product_key=%s AND variant_key=%s', $quantity, $key, $id);
            $this->db->insert('shop_order_variant_reservations', ['order_id' => $orderId, 'product_key' => $key, 'variant_key' => $id, 'quantity' => $quantity, 'state' => 'reserved']);
        }
        return true;
    }

    public function transition(int $orderId, string $from, string $to, int $direction): void
    {
        if (!(new ProductVariantRepository($this->db))->installed()) return;
        $rows = $this->db->query('SELECT product_key,variant_key,quantity FROM shop_order_variant_reservations
            WHERE order_id=%i AND state=%s ORDER BY product_key,variant_key FOR UPDATE', $orderId, $from);
        foreach ($rows as $row) {
            $this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE', $row['product_key']);
            if ($direction !== 0) {
                $available = $this->db->queryFirstField('SELECT available_quantity FROM shop_product_variants WHERE product_key=%s AND variant_key=%s FOR UPDATE', $row['product_key'], $row['variant_key']);
                if ($available === null || ($direction < 0 && (int) $available < (int) $row['quantity'])) throw new InvalidArgumentException('Objednávku nelze obnovit: vybraná varianta nemá dost kusů.');
                $this->db->query('UPDATE shop_product_variants SET available_quantity=available_quantity+%i WHERE product_key=%s AND variant_key=%s', $direction * (int) $row['quantity'], $row['product_key'], $row['variant_key']);
            }
            $this->db->query('UPDATE shop_order_variant_reservations SET state=%s WHERE order_id=%i AND product_key=%s AND variant_key=%s AND state=%s', $to, $orderId, $row['product_key'], $row['variant_key'], $from);
        }
    }
    public function forget(int $orderId): void
    {
        if ((new ProductVariantRepository($this->db))->installed()) $this->db->query('DELETE FROM shop_order_variant_reservations WHERE order_id=%i', $orderId);
    }
}
