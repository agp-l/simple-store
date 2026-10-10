<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use SimpleStore\Navigation\Slugger;

/** Resolve explicit identities first; names alone are never a duplicate identifier. */
final class ProductZipConflictPolicy
{
    public const LABELS = ['skip' => 'Přeskočit existující', 'update' => 'Aktualizovat existující', 'copy' => 'Vytvořit novou kopii'];

    public static function validate(string $mode): void
    {
        if (!isset(self::LABELS[$mode])) throw new InvalidArgumentException('Vyber způsob importu existujících produktů.');
    }

    public static function resolve(ProductRepository $products, array $data, string $newKey, string $mode): array
    {
        self::validate($mode);
        $key = $data['product_key'] ?? null;
        $slug = $data['product']['slug'] ?? '';
        if (!is_string($slug)) throw new InvalidArgumentException('slug musí být text.');
        $slug = trim($slug);
        if ($slug === '' && is_string($data['product']['name'] ?? null)) $slug = Slugger::fromTitle($data['product']['name']);
        $current = $key !== null ? $products->current($key, $data['language'])
            : ($slug === '' ? null : $products->findCurrentBySlug($slug, $data['language']));
        if ($current !== null && $mode === 'skip') return ['skipped' => true, 'message' => 'Produkt již existuje: ' . $current['name'] . '. Podle zvolené volby byl přeskočen.'];
        if ($mode === 'copy' && ($current !== null || $key !== null)) {
            unset($data['product_key'], $data['revision']);
            $key = null;
            $current = null;
            if ($slug !== '') $data['product']['slug'] = rtrim(substr($slug, 0, 170), '-') . '-kopie-' . substr($newKey, 0, 8);
            $data['product']['published'] = false;
        } elseif ($key !== null && $current === null) {
            return ['skipped' => true, 'message' => 'Produkt s uvedeným product_key v tomto jazyce neexistuje. Pro nový produkt vynech klíč a revizi nebo zvol vytvoření kopie.'];
        } elseif ($current !== null) {
            if ($key !== null && $data['revision'] !== (int) $current['revision_number']) {
                return ['skipped' => true, 'message' => 'Konflikt revizí: ZIP vychází z revize ' . $data['revision'] . ', aktuální revize je ' . $current['revision_number'] . '. Použij nový export.'];
            }
            // Selecting update explicitly authorizes matching a keyless package by its exact URL.
            $key = $current['product_key'];
            $data['product_key'] = $key;
            $data['revision'] = (int) $current['revision_number'];
        }
        return ['data' => $data, 'key' => $key, 'current' => $current];
    }
}
