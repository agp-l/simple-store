<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use SimpleStore\Category\CategoryPath;

/** Data-only catalog snapshots and revision-aware plans for a batch of independent product writes. */
final class ProductBulkTransfer
{
    public const FORMAT = 'simple-store.products.v1';
    public const LIMIT = 33554432;
    public const COUNT_LIMIT = 10000;

    public function __construct(private MeekroDB $db, private ProductRepository $products, private array $languages) {}

    /** Authenticated export only: includes drafts and every requested language, never historical revisions. */
    public function rows(string $language = '', string $category = '', string $search = ''): array
    {
        if (($language !== '' && !in_array($language, $this->languages, true)) || strlen($search) > 200 ||
            ($category !== '' && !CategoryPath::valid($category))) throw new InvalidArgumentException('Neplatný filtr exportu.');
        $where = 'active_product_key IS NOT NULL';
        $values = [];
        if ($language !== '') { $where .= ' AND language=%s'; $values[] = $language; }
        if ($search !== '') { $where .= ' AND (LOCATE(%s, name)>0 OR LOCATE(%s, slug)>0)'; array_push($values, $search, $search); }
        $rows = $this->db->query('SELECT * FROM shop_product_revisions WHERE ' . $where . ' ORDER BY name, language', ...$values);
        if ($category !== '') $rows = array_values(array_filter($rows, static function (array $row) use ($category): bool {
            $path = CategoryPath::fromProduct($row);
            return $path === $category || str_starts_with($path, $category . '/');
        }));
        return (new ProductStockRepository($this->db))->decorate($rows);
    }

    public static function identity(array $row): string { return $row['product_key'] . ':' . $row['language']; }

    public static function select(array $rows, string $selection): array
    {
        try { $ids = json_decode($selection, true, 4, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new InvalidArgumentException('Neplatný výběr produktů.'); }
        if (!is_array($ids) || count($ids) > self::COUNT_LIMIT || $ids === []) throw new InvalidArgumentException('Vyber alespoň jeden produkt.');
        $index = [];
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^[a-f0-9]{32}:[a-z]{2}$/D', $id) !== 1) throw new InvalidArgumentException('Neplatný výběr produktu.');
            $index[$id] = true;
        }
        $selected = array_values(array_filter($rows, static fn (array $row): bool => isset($index[self::identity($row)])));
        if (count($selected) !== count($index)) throw new InvalidArgumentException('Některý vybraný produkt již není dostupný. Obnov seznam.');
        return $selected;
    }

    public function export(array $rows): string
    {
        if (count($rows) > self::COUNT_LIMIT) throw new InvalidArgumentException('Rozděl export podle kategorií (nejvýše 10 000 položek v dávce).');
        $suppliers = (new ProductSupplierLinkRepository($this->db))->forOrderItems($rows);
        $documents = [];
        foreach ($rows as $row) {
            $document = json_decode(ProductImport::export($row, $suppliers[$row['product_key']] ?? []), true, 32, JSON_THROW_ON_ERROR);
            if (strlen(json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > ProductImport::LIMIT) throw new InvalidArgumentException('Produkt ' . $row['name'] . ' přesahuje limit 128 KiB pro zpětný import. Zkrať jeho obsah.');
            $document['stock_quantity'] = (int) ($row['stock_quantity'] ?? 0);
            $documents[] = $document;
        }
        $json = json_encode(['format' => self::FORMAT, 'exported_at' => gmdate('c'), 'products' => $documents],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::LIMIT) throw new InvalidArgumentException('Export přesahuje 32 MiB. Rozděl jej podle kategorií.');
        return $json;
    }

    public function supplierOverview(array $rows, string $baseUrl): string
    {
        return (new ProductSupplierTransfer($this->db, $this->products, $this->languages))->export($rows, $baseUrl);
    }

    public function exportRelations(array $rows): string
    {
        if (count($rows) > self::COUNT_LIMIT) throw new InvalidArgumentException('Vyber nejvýše 10 000 produktů.');
        $documents = (new ProductRelationRepository($this->db, $this->languages))->exportDocuments($rows);
        if ($documents === []) throw new InvalidArgumentException('Vybrané produkty nemají žádná propojení.');
        $json = json_encode(['format' => ProductRelationTransfer::FORMAT, 'products' => $documents], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::LIMIT) throw new InvalidArgumentException('Export přesahuje 32 MiB. Rozděl jej podle kategorií.');
        return $json;
    }

    public static function decode(string $json, ?string &$format = null): array
    {
        if (strlen($json) > self::LIMIT) throw new InvalidArgumentException('Hromadný JSON může mít nejvýše 32 MiB.');
        $json = trim($json);
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/sD', $json, $match)) $json = $match[1];
        try { $data = json_decode($json, false, 40, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new InvalidArgumentException('Neplatný JSON: ' . $error->getMessage()); }
        if (!$data instanceof \stdClass || !in_array($data->format ?? null, [self::FORMAT, ProductSupplierTransfer::FORMAT, ProductRelationTransfer::FORMAT], true) || !is_array($data->products ?? null) ||
            array_diff(array_keys((array) $data), ['format', 'exported_at', 'products']) !== []) {
            throw new InvalidArgumentException('Očekávám format simple-store.products.v1 , simple-store.product-suppliers.v1 nebo simple-store.product-relations.v1 a seznam products.');
        }
        if (count($data->products) < 1 || count($data->products) > self::COUNT_LIMIT) throw new InvalidArgumentException('Dávka musí obsahovat 1 až 10 000 produktů.');
        $format = $data->format;
        return $data->products;
    }

    /** Planning is read-only. Restore rebases once here; execution still checks for intervening edits. */
    public function prepare(string $json, bool $restore, bool $includeStock): array
    {
        $documents = self::decode($json, $format);
        if ($format === ProductRelationTransfer::FORMAT) {
            if ($restore || $includeStock) throw new InvalidArgumentException('Propojení importuj v běžném režimu bez obnovy skladu.');
            return (new ProductRelationTransfer($this->db, $this->products, $this->languages))->prepare($documents);
        }
        if ($format === ProductSupplierTransfer::FORMAT) {
            if ($restore || $includeStock) throw new InvalidArgumentException('JSON dodavatelů použij v běžném režimu aktualizace bez přepisu skladu.');
            return (new ProductSupplierTransfer($this->db, $this->products, $this->languages))->prepare($documents);
        }
        $items = []; $seen = []; $stockValues = [];
        foreach ($documents as $index => $document) {
            $item = ['folder' => 'Položka ' . ($index + 1), 'status' => 'pending', 'message' => '', 'new_key' => bin2hex(random_bytes(16))];
            try {
                if (!$document instanceof \stdClass) throw new InvalidArgumentException('Produkt musí být JSON objekt.');
                if (is_string($document->product->name ?? null)) $item['folder'] = $document->product->name;
                $quantity = $document->stock_quantity ?? null;
                unset($document->stock_quantity);
                $data = ProductImport::decode(json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                if (!in_array($data['language'], $this->languages, true)) throw new InvalidArgumentException('Neplatný jazyk produktu.');
                $key = $data['product_key'] ?? null;
                $current = $key === null ? null : $this->products->current($key, $data['language']);
                $identity = $key === null ? null : $key . ':' . $data['language'];
                if ($identity !== null && isset($seen[$identity])) throw new InvalidArgumentException('Stejný produkt a jazyk je v dávce uveden vícekrát.');
                if ($identity !== null) $seen[$identity] = true;
                $item['key'] = $key;
                $item['expected'] = $data['revision'] ?? null;
                if ($restore && $key !== null) {
                    if ($current !== null) {
                        $data['revision'] = (int) $current['revision_number'];
                        $item['expected'] = $data['revision'];
                    } else {
                        unset($data['product_key'], $data['revision']);
                        $exists = $this->db->queryFirstField('SELECT product_key FROM shop_product_revisions WHERE product_key=%s LIMIT 1', $key) !== null;
                        $item['key'] = $exists ? $key : null;
                        $item['expected'] = $exists ? 0 : null;
                        $item['new_key'] = $key;
                    }
                }
                $fields = ProductImport::fields($data, $current);
                if ((new \SimpleStore\Category\CategoryRepository($this->db))->findForAdmin($data['language'], $fields['category_path']) === null) throw new InvalidArgumentException('Kategorie produktu neexistuje.');
                $details = ProductDetails::fromForm($fields);
                $images = array_merge([$fields['image_path']], $details['gallery']);
                foreach ($details['sections'] as $section) if ($section['type'] === 'image') $images[] = $section['body'];
                foreach (array_unique($images) as $image) {
                    if (!is_file(dirname(__DIR__, 2) . '/' . $image)) throw new InvalidArgumentException('Na webu chybí fotografie: ' . $image . '. Nahraj ji před importem.');
                }
                $item['folder'] = $fields['name'] . ' (' . $data['language'] . ')';
                $item['message'] = $current === null ? 'Vytvořit produkt' : ($restore ? 'Obnovit údaje zálohy jako novou revizi' : 'Upravit revizi ' . $data['revision']);
                $item['data'] = $data;
                if ($includeStock && $quantity !== null) {
                    if (!is_int($quantity) || $quantity < 0 || $quantity > 1000000) throw new InvalidArgumentException('stock_quantity musí být celé číslo od 0 do 1 000 000.');
                    $stockKey = $key ?? $item['new_key'];
                    if (isset($stockValues[$stockKey]) && $stockValues[$stockKey] !== $quantity) throw new InvalidArgumentException('Překlady jednoho produktu mají rozdílný počet kusů.');
                    if (!isset($stockValues[$stockKey])) {
                        $expected = (int) ($this->db->queryFirstField('SELECT available_quantity FROM shop_product_inventory WHERE product_key=%s', $stockKey) ?? 0);
                        $item['inventory'] = ['quantity' => $quantity, 'expected_quantity' => $expected];
                        $stockValues[$stockKey] = $quantity;
                        $item['message'] .= '; sklad ' . $expected . ' → ' . $quantity;
                    }
                }
            } catch (InvalidArgumentException $error) {
                $item['status'] = 'error'; $item['message'] = $error->getMessage(); unset($item['data']);
            }
            $items[] = $item;
        }
        return $items;
    }

    public function import(array $item): array
    {
        if (($item['kind'] ?? '') === 'relations') return (new ProductRelationTransfer($this->db, $this->products, $this->languages))->import($item);
        if (($item['kind'] ?? '') === 'suppliers') return (new ProductSupplierTransfer($this->db, $this->products, $this->languages))->import($item);
        $data = $item['data'];
        $current = isset($data['product_key']) ? $this->products->current($data['product_key'], $data['language']) : null;
        $fields = ProductImport::fields($data, $current);
        $saved = $this->products->saveRevision($fields, $item['key'], $item['expected'], ProductImport::suppliers($data),
            $item['key'] === null ? $item['new_key'] : null, $item['inventory'] ?? null);
        return $saved + ['name' => $fields['name'], 'slug' => $fields['slug']];
    }
}
