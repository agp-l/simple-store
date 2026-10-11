<?php
declare(strict_types=1);

namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use Throwable;

/** Applies an explicitly previewed catalog-wide change in one transaction. */
final class ProductMassUpdate
{
    public function __construct(private MeekroDB $db, private int $revisionLimit = 50) {}

    public static function choices(array $input): array
    {
        $quantity = $input['quantity'] ?? '';
        $status = $input['stock_status'] ?? '';
        $visibility = $input['visibility'] ?? '';
        if (!is_string($quantity) || !is_string($status) || !is_string($visibility)) {
            throw new InvalidArgumentException('Neplatné zadání hromadné změny.');
        }
        $quantity = trim($quantity);
        if ($quantity !== '' && (preg_match('/^[0-9]{1,7}$/D', $quantity) !== 1 || (int) $quantity > 1000000)) {
            throw new InvalidArgumentException('Počet kusů musí být celé číslo od 0 do 1 000 000.');
        }
        if (!in_array($status, ['', 'in_stock', 'on_order', 'out_of_stock'], true) ||
            !in_array($visibility, ['', 'public', 'hidden'], true)) {
            throw new InvalidArgumentException('Vyber platnou dostupnost a viditelnost.');
        }
        if ($quantity === '' && $status === '' && $visibility === '') {
            throw new InvalidArgumentException('Vyber alespoň jednu změnu.');
        }
        return ['quantity' => $quantity, 'stock_status' => $status, 'visibility' => $visibility];
    }

    public function preview(array $input): array
    {
        $choices = self::choices($input);
        $rows = $this->rows();
        if ($rows === []) throw new InvalidArgumentException('V obchodě zatím nejsou žádné produkty.');
        return ['choices' => $choices, 'fingerprint' => self::fingerprint($rows),
            'count' => count(array_unique(array_column($rows, 'product_key')))];
    }

    public function count(): int
    {
        return (int) $this->db->queryFirstField('SELECT COUNT(DISTINCT product_key) FROM shop_product_revisions WHERE active_product_key IS NOT NULL');
    }

    public function apply(array $preview): int
    {
        $choices = self::choices($preview['choices'] ?? []);
        $this->db->startTransaction();
        try {
            $rows = $this->rows(true);
            if (!hash_equals((string) ($preview['fingerprint'] ?? ''), self::fingerprint($rows))) {
                throw new InvalidArgumentException('Produkty nebo sklad se od náhledu změnily. Zobraz nový náhled a změnu potvrď znovu.');
            }
            $changed = [];
            $stockDone = [];
            foreach ($rows as $row) {
                $key = $row['product_key'];
                $variantState = $row['variant_inventory'];
                if ($variantState['enabled'] && !isset($stockDone[$key]) && ($choices['quantity'] !== '' || $choices['stock_status'] !== '')) {
                    $stockDone[$key] = true;
                    foreach ($variantState['items'] as &$variant) {
                        if (!$variant['enabled']) continue;
                        if ($choices['quantity'] !== '') $variant['quantity'] = (int) $choices['quantity'];
                        if ($choices['stock_status'] !== '') $variant['stock_status'] = $choices['stock_status'];
                    }
                    unset($variant);
                    (new ProductVariantRepository($this->db))->apply($key, $variantState);
                    $changed[$key] = true;
                }
                if (!$variantState['enabled'] && $choices['quantity'] !== '' && !isset($stockDone[$key])) {
                    $stockDone[$key] = true;
                    $quantity = (int) $choices['quantity'];
                    $this->db->query('INSERT IGNORE INTO shop_product_inventory (product_key, available_quantity) VALUES (%s, 0)', $key);
                    if ((int) $row['available_quantity'] !== $quantity) {
                        $this->db->query('UPDATE shop_product_inventory SET available_quantity=%i WHERE product_key=%s', $quantity, $key);
                        $changed[$key] = true;
                    }
                }
                $status = $choices['stock_status'] !== '' ? $choices['stock_status'] : $row['stock_status'];
                $published = $choices['visibility'] !== '' ? (int) ($choices['visibility'] === 'public') : (int) $row['published'];
                if ($status === $row['stock_status'] && $published === (int) $row['published']) continue;
                $this->db->query('UPDATE shop_product_revisions SET active_product_key=NULL, active_slug=NULL WHERE id=%i', $row['id']);
                unset($row['id'], $row['saved_at'], $row['available_quantity'], $row['variant_inventory']);
                $row['revision_number'] = (int) $row['revision_number'] + 1;
                $row['stock_status'] = $status;
                $row['published'] = $published;
                $this->db->insert('shop_product_revisions', $row);
                if ($row['revision_number'] > max(1, $this->revisionLimit)) {
                    $this->db->query('DELETE FROM shop_product_revisions WHERE product_key=%s AND language=%s AND active_product_key IS NULL AND revision_number<=%i',
                        $key, $row['language'], $row['revision_number'] - max(1, $this->revisionLimit));
                }
                $changed[$key] = true;
            }
            $this->db->commit();
            return count($changed);
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function rows(bool $lock = false): array
    {
        $rows = $this->db->query('SELECT r.*, COALESCE(i.available_quantity, 0) AS available_quantity
            FROM shop_product_revisions r LEFT JOIN shop_product_inventory i ON i.product_key=r.product_key
            WHERE r.active_product_key IS NOT NULL ORDER BY r.product_key, r.language' . ($lock ? ' FOR UPDATE' : ''));
        $keys = array_values(array_unique(array_column($rows, 'product_key')));
        $variants = (new ProductVariantRepository($this->db))->snapshots($keys, $lock);
        foreach ($rows as &$row) $row['variant_inventory'] = $variants[$row['product_key']] ?? ProductVariantRepository::empty();
        unset($row);
        return $rows;
    }

    private static function fingerprint(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
