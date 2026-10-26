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
            'count' => count(array_unique(array_column($rows, 'product_key'))),
            'changed' => count(self::changes($rows, $choices))];
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
            $changes = self::changes($rows, $choices);
            foreach ($changes as $key => $change) {
                if ($change['inventory'] !== []) {
                    $this->db->update('shop_product_inventory', $change['inventory'], 'product_key=%s', $key);
                }
                foreach ($change['variants'] as $variant => $values) {
                    $this->db->update('shop_product_variants', $values, 'product_key=%s AND variant_key=%s', $key, $variant);
                }
                if ($change['variants'] !== []) {
                    // Stock edits must not run variant creation/inheritance or change pricing modes.
                    $this->db->query('UPDATE shop_product_variant_settings SET version=version+1 WHERE product_key=%s', $key);
                }
                foreach ($change['revisions'] as $revision) {
                    $row = $revision['row'];
                    $this->db->query('UPDATE shop_product_revisions SET active_product_key=NULL, active_slug=NULL WHERE id=%i', $row['id']);
                    unset($row['id'], $row['saved_at'], $row['available_quantity'], $row['variant_inventory']);
                    $row['revision_number'] = (int) $row['revision_number'] + 1;
                    $row = array_replace($row, $revision['values']);
                    $this->db->insert('shop_product_revisions', $row);
                    if ($row['revision_number'] > max(1, $this->revisionLimit)) {
                        $this->db->query('DELETE FROM shop_product_revisions WHERE product_key=%s AND language=%s AND active_product_key IS NULL AND revision_number<=%i',
                            $key, $row['language'], $row['revision_number'] - max(1, $this->revisionLimit));
                    }
                }
            }
            $this->db->commit();
            return count($changes);
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    /** One plan for the preview count and the actual write; unchanged variants are never rewritten. */
    private static function changes(array $rows, array $choices): array
    {
        $changes = []; $seen = [];
        foreach ($rows as $row) {
            $key = $row['product_key'];
            $change = $changes[$key] ?? ['inventory' => [], 'variants' => [], 'revisions' => []];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                if ($row['variant_inventory']['enabled']) {
                    foreach ($row['variant_inventory']['items'] as $variant) {
                        if (!$variant['enabled']) continue;
                        $values = [];
                        if ($choices['quantity'] !== '' && $variant['quantity'] !== (int) $choices['quantity']) $values['available_quantity'] = (int) $choices['quantity'];
                        if ($choices['stock_status'] !== '' && $variant['stock_status'] !== $choices['stock_status']) $values['stock_status'] = $choices['stock_status'];
                        if ($values !== []) $change['variants'][$variant['id']] = $values;
                    }
                } elseif ($choices['quantity'] !== '' && (int) $row['available_quantity'] !== (int) $choices['quantity']) {
                    $change['inventory'] = ['available_quantity' => (int) $choices['quantity']];
                }
            }
            $values = [];
            if ($choices['stock_status'] !== '' && $row['stock_status'] !== $choices['stock_status']) $values['stock_status'] = $choices['stock_status'];
            if ($choices['visibility'] !== '' && (int) $row['published'] !== (int) ($choices['visibility'] === 'public')) $values['published'] = (int) ($choices['visibility'] === 'public');
            if ($values !== []) $change['revisions'][] = ['row' => $row, 'values' => $values];
            if ($change['inventory'] !== [] || $change['variants'] !== [] || $change['revisions'] !== []) $changes[$key] = $change;
        }
        return $changes;
    }

    private function rows(bool $lock = false): array
    {
        if ($lock) {
            foreach ($this->db->query('SELECT DISTINCT product_key FROM shop_product_revisions WHERE active_product_key IS NOT NULL ORDER BY product_key') as $identity) {
                (new ProductStockRepository($this->db))->ensure($identity['product_key']);
                $this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s FOR UPDATE', $identity['product_key']);
            }
        }
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
