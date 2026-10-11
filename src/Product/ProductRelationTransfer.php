<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;

/** Additive relation-only JSON; ignores no unknown product fields silently. */
final class ProductRelationTransfer
{
    public const FORMAT = 'simple-store.product-relations.v1';
    private ProductRelationRepository $relations;
    public function __construct(MeekroDB $db, private ProductRepository $products, private array $languages)
    {
        $this->relations = new ProductRelationRepository($db, $languages);
    }

    public function prepare(array $documents): array
    {
        $items = []; $seen = [];
        foreach ($documents as $index => $document) {
            $item = ['kind' => 'relations', 'folder' => 'Položka ' . ($index + 1), 'status' => 'pending', 'message' => ''];
            try {
                if (!$document instanceof \stdClass || array_diff(array_keys((array) $document), ['product_key', 'language', 'name', 'related'])) throw new InvalidArgumentException('Neplatný objekt propojení produktů.');
                $key = $document->product_key ?? null; $language = $document->language ?? null;
                if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key) || !is_string($language) || !in_array($language, $this->languages, true)) throw new InvalidArgumentException('Uveď product_key a language z exportu.');
                $current = $this->products->current($key, $language);
                if ($current === null) throw new InvalidArgumentException('Produkt neexistuje.');
                $item['folder'] = $current['name'];
                if (!is_array($document->related ?? null) || count($document->related) > 100) throw new InvalidArgumentException('related musí být seznam nejvýše 100 propojení.');
                $links = []; $pendingPairs = [];
                foreach ($document->related as $link) {
                    if (!$link instanceof \stdClass || array_diff(array_keys((array) $link), ['product_key', 'note']) || !is_string($link->product_key ?? null) || !is_string($link->note ?? '')) throw new InvalidArgumentException('Propojení musí obsahovat product_key a volitelnou note.');
                    $note = trim($link->note ?? '');
                    $pair = $language . ':' . implode(':', $this->relations->pair($language, $key, $link->product_key, $note));
                    if (isset($seen[$pair]) || isset($pendingPairs[$pair])) throw new InvalidArgumentException('Stejnou dvojici uveď v dávce pouze jednou; propojení je obousměrné.');
                    if ($this->products->current($link->product_key, $language) === null) throw new InvalidArgumentException('Související produkt neexistuje.');
                    $pendingPairs[$pair] = true;
                    $links[] = ['product_key' => $link->product_key, 'note' => $note, 'expected' => $this->relations->note($language, $key, $link->product_key)];
                }
                $seen += $pendingPairs;
                $item += ['key' => $key, 'language' => $language, 'links' => $links];
                $item['message'] = 'Přidat nebo upravit ' . count($links) . ' obousměrných propojení';
            } catch (InvalidArgumentException $error) { $item['status'] = 'error'; $item['message'] = $error->getMessage(); }
            $items[] = $item;
        }
        return $items;
    }

    public function import(array $item): array
    {
        $this->relations->apply($item['language'], $item['key'], $item['links']);
        $current = $this->products->current($item['key'], $item['language']);
        return ['product_key' => $item['key'], 'language' => $item['language'], 'name' => $current['name'], 'slug' => $current['slug'], 'message' => $current['name'] . ' — související produkty propojeny'];
    }
}
