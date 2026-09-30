<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use RuntimeException;
use Throwable;

/** Sellable pieces are shared by every language and every variant of a product. */
final class ProductStockRepository
{
    public function __construct(private MeekroDB $db)
    {
    }

    public function installed(): bool
    {
        foreach (['shop_product_inventory', 'shop_order_stock_reservations'] as $table) {
            if ((int) $this->db->queryFirstField(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table
            ) !== 1) return false;
        }
        return true;
    }

    public function ensure(string $key): void
    {
        self::validKey($key);
        $this->db->query('INSERT IGNORE INTO shop_product_inventory (product_key, available_quantity)
            VALUES (%s, 0)', $key);
    }

    /** @param array<array<string, mixed>> $rows */
    public function decorate(array $rows): array
    {
        if ($rows === []) return [];
        $keys = array_values(array_unique(array_filter(array_column($rows, 'product_key'),
            static fn (mixed $key): bool => is_string($key) && preg_match('/^[a-f0-9]{32}$/D', $key) === 1)));
        $quantities = [];
        if ($keys !== []) {
            $placeholders = implode(',', array_fill(0, count($keys), '%s'));
            foreach ($this->db->query('SELECT product_key, available_quantity FROM shop_product_inventory
                WHERE product_key IN (' . $placeholders . ')', ...$keys) as $row) {
                $quantities[$row['product_key']] = (int) $row['available_quantity'];
            }
        }
        foreach ($rows as &$row) {
            $quantity = $quantities[$row['product_key'] ?? ''] ?? 0;
            $row['stock_quantity'] = $quantity;
            $row['availability_status'] = ($row['stock_status'] ?? '') === 'in_stock' && $quantity < 1
                ? 'out_of_stock' : ($row['stock_status'] ?? 'out_of_stock');
        }
        unset($row);
        return $rows;
    }

    public function setAvailable(string $key, int $expected, int $quantity): void
    {
        self::validKey($key);
        if ($expected < 0 || $quantity < 0 || $quantity > 1000000) {
            throw new InvalidArgumentException('Počet kusů musí být mezi 0 a 1 000 000.');
        }
        $this->db->startTransaction();
        try {
            $this->ensure($key);
            $row = $this->db->queryFirstRow('SELECT available_quantity FROM shop_product_inventory
                WHERE product_key=%s FOR UPDATE', $key);
            if ($row === null || (int) $row['available_quantity'] !== $expected) {
                throw new RuntimeException('Počet kusů se mezitím změnil. Obnov stránku a zkus úpravu znovu.');
            }
            $this->db->query('UPDATE shop_product_inventory SET available_quantity=%i WHERE product_key=%s',
                $quantity, $key);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    /** Run inside the order transaction, after the order row exists. */
    public function reserve(int $orderId, array $items): void
    {
        $requested = [];
        foreach ($items as $item) {
            $key = (string) ($item['product_key'] ?? '');
            self::validKey($key);
            $requested[$key]['quantity'] = ($requested[$key]['quantity'] ?? 0) + (int) $item['quantity'];
            $requested[$key]['languages'][(string) $item['language']] = true;
        }
        ksort($requested, SORT_STRING);
        foreach ($requested as $key => $line) {
            $stock = $this->db->queryFirstRow('SELECT available_quantity FROM shop_product_inventory
                WHERE product_key=%s FOR UPDATE', $key);
            if ($stock === null) throw new InvalidArgumentException('Produkt ještě nemá nastavený sklad.');
            $needsStock = false;
            foreach (array_keys($line['languages']) as $language) {
                $product = $this->db->queryFirstRow('SELECT stock_status FROM product_revisions
                    WHERE product_key=%s AND language=%s AND active_product_key IS NOT NULL
                    AND published=1 LIMIT 1', $key, $language);
                if ($product === null || $product['stock_status'] === 'out_of_stock') {
                    throw new InvalidArgumentException('Produkt už není dostupný. Obnov košík.');
                }
                if ($product['stock_status'] === 'in_stock') $needsStock = true;
            }
            if (!$needsStock) continue;
            if ((int) $stock['available_quantity'] < $line['quantity']) {
                throw new InvalidArgumentException('Produkt už není v požadovaném množství skladem. Obnov košík.');
            }
            $this->db->query('UPDATE shop_product_inventory SET available_quantity=available_quantity-%i
                WHERE product_key=%s', $line['quantity'], $key);
            $this->db->insert('shop_order_stock_reservations', [
                'order_id' => $orderId, 'product_key' => $key,
                'quantity' => $line['quantity'], 'state' => 'reserved',
            ]);
        }
    }

    /** A cancelled or deleted unshipped order returns its reserved pieces once. */
    public function release(int $orderId): void
    {
        foreach ($this->db->query('SELECT product_key, quantity FROM shop_order_stock_reservations
            WHERE order_id=%i AND state=%s ORDER BY product_key FOR UPDATE', $orderId, 'reserved') as $row) {
            $this->db->query('UPDATE shop_product_inventory
                SET available_quantity=available_quantity+%i WHERE product_key=%s',
                (int) $row['quantity'], $row['product_key']);
            $this->db->query('UPDATE shop_order_stock_reservations SET state=%s
                WHERE order_id=%i AND product_key=%s AND state=%s',
                'released', $orderId, $row['product_key'], 'reserved');
        }
    }

    public function consume(int $orderId): void
    {
        $this->db->query('UPDATE shop_order_stock_reservations SET state=%s
            WHERE order_id=%i AND state=%s', 'consumed', $orderId, 'reserved');
    }

    /** An exceptional reopening has to reclaim the released stock first. */
    public function reopen(int $orderId): void
    {
        foreach ($this->db->query('SELECT product_key, quantity FROM shop_order_stock_reservations
            WHERE order_id=%i AND state=%s ORDER BY product_key FOR UPDATE', $orderId, 'released') as $row) {
            $stock = $this->db->queryFirstRow('SELECT available_quantity FROM shop_product_inventory
                WHERE product_key=%s FOR UPDATE', $row['product_key']);
            if ($stock === null || (int) $stock['available_quantity'] < (int) $row['quantity']) {
                throw new InvalidArgumentException('Objednávku nelze obnovit: kusy už nejsou skladem.');
            }
            $this->db->query('UPDATE shop_product_inventory SET available_quantity=available_quantity-%i
                WHERE product_key=%s', (int) $row['quantity'], $row['product_key']);
            $this->db->query('UPDATE shop_order_stock_reservations SET state=%s
                WHERE order_id=%i AND product_key=%s AND state=%s',
                'reserved', $orderId, $row['product_key'], 'released');
        }
    }

    public function forget(int $orderId): void
    {
        $this->db->query('DELETE FROM shop_order_stock_reservations WHERE order_id=%i', $orderId);
    }

    private static function validKey(string $key): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Neplatný produkt.');
        }
    }
}
