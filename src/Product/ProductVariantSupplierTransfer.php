<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;

/** Versioned, private, additive JSON contract. Imported observations are never trusted. */
final class ProductVariantSupplierTransfer
{
    public const FORMAT = 'simple-store.variant-suppliers.v1';
    public function __construct(private MeekroDB $db, private ProductRepository $products, private array $languages) {}
    public function export(array $rows): string
    {
        $repository = new ProductVariantSupplierRepository($this->db);
        if (!$repository->installed()) throw new InvalidArgumentException('Nejprve aktualizuj databázi v administraci.');
        $items = [];
        foreach ($rows as $row) {
            $key = $row['product_key']; if (isset($items[$key])) continue;
            $links = $repository->forProduct($key);
            $variants = (new ProductVariantRepository($this->db))->snapshot($key);
            $items[$key] = ['product_key' => $key, 'language' => $row['language'], 'name' => $row['name'],
                'variants' => array_map(static fn(array $variant): array => [
                    'variant_key' => $variant['id'], 'choices' => $variant['choices'], 'enabled' => $variant['enabled'],
                    'links' => array_values(array_map(static fn(array $link): array => [
                        'link_key' => $link['link_key'], 'version' => $link['version'], 'partner_key' => $link['partner_key'],
                        'label' => $link['label'], 'url' => $link['url'], 'origin' => $link['origin'], 'verification' => $link['verification'],
                        'checked_at' => $link['checked_at'], 'result' => (object) $link['result'],
                    ], array_filter($links, static fn(array $link): bool => $link['variant_key'] === $variant['id']))),
                ], $variants['items'])];
        }
        $json = json_encode(['format' => self::FORMAT, 'exported_at' => gmdate('c'), 'products' => array_values($items)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > ProductBulkTransfer::LIMIT) throw new InvalidArgumentException('Export přesahuje 32 MiB. Rozděl jej podle kategorií.');
        return $json;
    }
    public function prepare(array $documents): array
    {
        $repository = new ProductVariantSupplierRepository($this->db); $items = []; $seen = [];
        if (!$repository->installed()) throw new InvalidArgumentException('Nejprve aktualizuj databázi v administraci.');
        foreach ($documents as $index => $document) {
            $item = ['kind' => 'variant-suppliers', 'folder' => 'Položka ' . ($index + 1), 'status' => 'pending', 'message' => ''];
            try {
                self::fields($document, ['product_key', 'language', 'name', 'variants']);
                $key = $document->product_key ?? null; $language = $document->language ?? null;
                if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key) || !is_string($language) || !in_array($language, $this->languages, true)) throw new InvalidArgumentException('Neplatný produkt nebo jazyk.');
                if (isset($seen[$key])) throw new InvalidArgumentException('Produkt je v dávce vícekrát.');
                $seen[$key] = true; $current = $this->products->current($key, $language);
                if ($current === null) throw new InvalidArgumentException('Produkt již neexistuje.');
                $item['folder'] = $current['name'];
                $existing = $repository->forProduct($key); $incoming = []; $touched = [];
                $variants = (new ProductVariantRepository($this->db))->snapshot($key);
                $byId = array_column($variants['items'], null, 'id');
                if (!is_array($document->variants ?? null) || count($document->variants) > ProductVariants::LIMIT) throw new InvalidArgumentException('Neplatný seznam variant.');
                foreach ($document->variants as $variant) {
                    self::fields($variant, ['variant_key', 'choices', 'enabled', 'links']);
                    $id = $variant->variant_key ?? null;
                    if (!is_string($id) || !isset($byId[$id]) || isset($touched[$id])) throw new InvalidArgumentException('Neplatná nebo opakovaná varianta produktu.');
                    $touched[$id] = true;
                    if (isset($variant->choices) && (array) $variant->choices != $byId[$id]['choices']) throw new InvalidArgumentException('Vlastnosti varianty neodpovídají exportu.');
                    if (!is_array($variant->links ?? null) || count($variant->links) > 1000) throw new InvalidArgumentException('U varianty může být nejvýše 1 000 odkazů.');
                    foreach ($variant->links as $link) {
                        self::fields($link, ['link_key', 'version', 'partner_key', 'label', 'url', 'origin', 'verification', 'checked_at', 'result']);
                        $linkId = $link->link_key ?? ''; $version = $link->version ?? 0; $partner = $link->partner_key ?? '';
                        if (!is_string($linkId) || ($linkId !== '' && !preg_match('/^[a-f0-9]{32}$/D', $linkId)) || !is_int($version) || $version < 0 || !is_string($partner) || !is_string($link->label ?? null) || !is_string($link->url ?? null)) throw new InvalidArgumentException('Neplatný odkaz, partner nebo verze.');
                        $label = trim($link->label); $url = trim($link->url); ProductSupplierLinkRepository::validate($label, $url);
                        $stored = null;
                        foreach ($existing as $old) if ($old['variant_key'] === $id && ($linkId !== '' ? $old['link_key'] === $linkId : $old['url'] === $url)) { $stored = $old; break; }
                        if ($linkId !== '' && ($stored === null || $stored['version'] !== $version)) throw new InvalidArgumentException('Odkaz se od exportu změnil. Exportuj znovu.');
                        if ($linkId === '' && $stored !== null) {
                            // Repeating an additive import is a no-op, never an implicit edit.
                            if ($stored['label'] !== $label || ($stored['partner_key'] ?? '') !== $partner) throw new InvalidArgumentException('Pro změnu existujícího odkazu použij link_key a version z exportu.');
                            continue;
                        }
                        $identity = $id . ':' . $url;
                        if (isset($touched[$identity])) throw new InvalidArgumentException('Stejný odkaz je uveden vícekrát.');
                        $touched[$identity] = true;
                        if ($stored !== null && $stored['label'] === $label && $stored['url'] === $url && ($stored['partner_key'] ?? '') === $partner) continue;
                        $incoming[] = ['variant' => $id, 'id' => $linkId, 'version' => $version, 'partner' => $partner, 'label' => $label, 'url' => $url];
                    }
                }
                $item += ['key' => $key, 'language' => $language, 'incoming' => $incoming, 'expected_links' => $existing];
                $item['message'] = count($incoming) . ' změn partnerských odkazů variant; ceny, sklad a prodávající se nemění.';
            } catch (InvalidArgumentException $error) { $item['status'] = 'error'; $item['message'] = $error->getMessage(); }
            $items[] = $item;
        }
        return $items;
    }
    public function import(array $item): array
    {
        $repository = new ProductVariantSupplierRepository($this->db);
        return $repository->transaction($item['key'], function() use ($repository, $item): array {
            if ($repository->forProduct($item['key'], true) != $item['expected_links']) throw new InvalidArgumentException('Odkazy se od přípravy změnily. Připrav import znovu.');
            $current = $this->products->current($item['key'], $item['language']);
            if ($current === null) throw new InvalidArgumentException('Produkt již neexistuje.');
            foreach ($item['incoming'] as $link) $repository->saveInTransaction($item['key'], $link['variant'], $link['id'], $link['version'], $link['partner'], $link['label'], $link['url']);
            return ['product_key' => $item['key'], 'language' => $item['language'], 'name' => $current['name'], 'slug' => $current['slug'], 'message' => $current['name'] . ' — odkazy variant aktualizovány'];
        });
    }
    private static function fields(mixed $value, array $allowed): void
    {
        if (!$value instanceof \stdClass || array_diff(array_keys((array) $value), $allowed)) throw new InvalidArgumentException('Neplatná struktura JSON odkazů variant.');
    }
}
