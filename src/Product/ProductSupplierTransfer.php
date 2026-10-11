<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;
use Throwable;
use SimpleStore\Category\CategoryPath;

/** Compact catalog overview and additive updates of private supplier links only. */
final class ProductSupplierTransfer
{
    public const FORMAT = 'simple-store.product-suppliers.v1';

    public function __construct(private MeekroDB $db, private ProductRepository $products, private array $languages) {}

    public function export(array $rows, string $baseUrl): string
    {
        $links = (new ProductSupplierLinkRepository($this->db))->forOrderItems($rows);
        $items = [];
        foreach ($rows as $row) {
            $key = $row['product_key'];
            if (isset($items[$key])) continue; // Suppliers are shared by all translations.
            $items[$key] = ['product_key' => $key, 'language' => $row['language'], 'name' => $row['name'],
                'url' => rtrim($baseUrl, '/') . '/' . rawurlencode($row['language']) . '/produkt/' . rawurlencode($row['slug']),
                'category_path' => CategoryPath::fromProduct($row), 'published' => (bool) $row['published'],
                'suppliers' => array_map(static fn (array $link): array => ['id' => (int) $link['id'], 'label' => $link['label'], 'url' => $link['url']], $links[$key] ?? [])];
        }
        if (count($items) > ProductBulkTransfer::COUNT_LIMIT) throw new InvalidArgumentException('Export může obsahovat nejvýše 10 000 produktů.');
        $json = json_encode(['format' => self::FORMAT, 'exported_at' => gmdate('c'), 'products' => array_values($items)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > ProductBulkTransfer::LIMIT) throw new InvalidArgumentException('Export přesahuje 32 MiB. Rozděl jej podle kategorií.');
        return $json;
    }

    public function prepare(array $documents): array
    {
        $items = []; $seen = [];
        $repository = new ProductSupplierLinkRepository($this->db);
        foreach ($documents as $index => $document) {
            $item = ['kind' => 'suppliers', 'folder' => 'Položka ' . ($index + 1), 'status' => 'pending', 'message' => ''];
            try {
                if (!$document instanceof \stdClass || array_diff(array_keys((array) $document), ['product_key', 'language', 'name', 'url', 'category_path', 'published', 'suppliers'])) throw new InvalidArgumentException('Neplatný objekt aktualizace dodavatelů.');
                $key = $document->product_key ?? null; $language = $document->language ?? null;
                if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key) || !is_string($language) || !in_array($language, $this->languages, true)) throw new InvalidArgumentException('Uveď platné product_key a language z exportu.');
                if (isset($seen[$key])) throw new InvalidArgumentException('Produkt je uveden vícekrát; dodavatelé jsou společné pro všechny jazyky.');
                $seen[$key] = true;
                $current = $this->products->current($key, $language);
                if ($current === null) throw new InvalidArgumentException('Produkt již neexistuje.');
                $item['folder'] = $current['name'];
                if (!is_array($document->suppliers ?? null) || count($document->suppliers) > 1000) throw new InvalidArgumentException('suppliers musí být seznam nejvýše 1 000 odkazů.');
                $incoming = [];
                foreach ($document->suppliers as $link) {
                    if (!$link instanceof \stdClass || array_diff(array_keys((array) $link), ['id', 'label', 'url']) || !is_string($link->label ?? null) || !is_string($link->url ?? null)) throw new InvalidArgumentException('Dodavatel musí obsahovat label a url; volitelně id.');
                    $id = $link->id ?? 0;
                    if (!is_int($id) || $id < 0) throw new InvalidArgumentException('ID dodavatele musí být nezáporné celé číslo.');
                    $label = trim($link->label); $url = trim($link->url);
                    ProductSupplierLinkRepository::validate($label, $url);
                    $incoming[] = ['id' => $id, 'label' => $label, 'url' => $url];
                }
                $existing = $repository->forProduct($key);
                $merged = self::merge($existing, $incoming);
                $item += ['key' => $key, 'language' => $language, 'incoming' => $incoming, 'expected_links' => $existing];
                $item['message'] = 'Pouze dodavatelé: ' . count($existing) . ' → ' . count($merged) . ' odkazů; ostatní údaje beze změny';
            } catch (InvalidArgumentException $error) {
                $item['status'] = 'error'; $item['message'] = $error->getMessage();
            }
            $items[] = $item;
        }
        return $items;
    }

    /** IDs identify edits; exact URLs identify additions already present. Never match by shop/domain. */
    public static function merge(array $existing, array $incoming): array
    {
        $result = array_values($existing); $touched = [];
        foreach ($incoming as $link) {
            $target = null;
            foreach ($result as $i => $stored) {
                if ($link['id'] > 0 ? (int) $stored['id'] === $link['id'] : $stored['url'] === $link['url']) { $target = $i; break; }
            }
            if ($link['id'] > 0 && $target === null) throw new InvalidArgumentException('ID dodavatele již nepatří tomuto produktu. Exportuj aktuální seznam.');
            if ($target === null) { $target = count($result); $result[] = $link; }
            if (isset($touched[$target])) throw new InvalidArgumentException('Stejný dodavatelský odkaz je v položce uveden vícekrát.');
            $touched[$target] = true;
            foreach ($result as $i => $stored) if ($i !== $target && $stored['url'] === $link['url']) throw new InvalidArgumentException('Tato adresa už patří jinému odkazu produktu.');
            $result[$target] = ['id' => (int) $result[$target]['id'], 'label' => $link['label'], 'url' => $link['url']];
        }
        return $result;
    }

    public function import(array $item): array
    {
        $this->db->startTransaction();
        try {
            $rows = $this->db->query('SELECT name, slug, language FROM shop_product_revisions WHERE product_key=%s AND active_product_key IS NOT NULL ORDER BY language FOR UPDATE', $item['key']);
            $current = null;
            foreach ($rows as $row) if ($row['language'] === $item['language']) $current = $row;
            if ($current === null) throw new InvalidArgumentException('Produkt již neexistuje.');
            $links = $this->db->query('SELECT id, label, url FROM shop_product_supplier_links WHERE product_key=%s ORDER BY id FOR UPDATE', $item['key']);
            if ($links != $item['expected_links']) throw new InvalidArgumentException('Dodavatelé se od přípravy změnili. Připrav import znovu.');
            $merged = self::merge($links, $item['incoming']);
            $repository = new ProductSupplierLinkRepository($this->db);
            foreach ($merged as $index => $link) {
                if (isset($links[$index]) && $links[$index] == $link) continue;
                $repository->save($item['key'], (int) $link['id'], $link['label'], $link['url']);
            }
            $this->db->commit();
            return ['product_key' => $item['key'], 'language' => $item['language'], 'name' => $current['name'], 'slug' => $current['slug'], 'message' => $current['name'] . ' — dodavatelé aktualizováni; ostatní údaje beze změny'];
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }
}
