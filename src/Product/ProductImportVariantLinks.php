<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;

/** Embeds private, additive partner links in a complete product document. */
final class ProductImportVariantLinks
{
    public static function normalize(mixed $links): array
    {
        if (!is_array($links) || !array_is_list($links) || count($links) > 1000) {
            throw new InvalidArgumentException('suppliers varianty musí být seznam nejvýše 1 000 odkazů.');
        }
        $seen = []; $ids = [];
        foreach ($links as $link) {
            if (!is_array($link) || array_diff(array_keys($link), ['link_key', 'version', 'partner_key', 'label', 'url']) !== [] ||
                !is_string($link['label'] ?? null) || !is_string($link['url'] ?? null)) {
                throw new InvalidArgumentException('Odkaz varianty potřebuje label a url; stav ověření se neimportuje.');
            }
            ProductSupplierLinkRepository::validate($link['label'], $link['url']);
            if (isset($seen[$link['url']])) throw new InvalidArgumentException('Varianta obsahuje duplicitní URL dodavatele.');
            $seen[$link['url']] = true;
            if (array_key_exists('link_key', $link)) {
                if (!is_string($link['link_key']) || preg_match('/^[a-f0-9]{32}$/D', $link['link_key']) !== 1 ||
                    isset($ids[$link['link_key']]) || !is_int($link['version'] ?? null) || $link['version'] < 1) {
                    throw new InvalidArgumentException('Existující odkaz potřebuje link_key a version z aktuálního exportu.');
                }
                $ids[$link['link_key']] = true;
            } elseif (array_key_exists('version', $link)) {
                throw new InvalidArgumentException('Nový odkaz nemá version ani link_key.');
            }
            if (array_key_exists('partner_key', $link) && (!is_string($link['partner_key']) ||
                ($link['partner_key'] !== '' && preg_match('/^[a-f0-9]{32}$/D', $link['partner_key']) !== 1))) {
                throw new InvalidArgumentException('partner_key musí pocházet z exportu; jinak jej vynech.');
            }
        }
        return $links;
    }

    public static function export(array $links): array
    {
        return array_map(static fn(array $link): array => [
            'link_key' => $link['link_key'], 'version' => (int) $link['version'],
            'partner_key' => $link['partner_key'] ?? '', 'label' => $link['label'], 'url' => $link['url'],
        ], $links);
    }

    /** Product + variants + links commit together. The product/inventory locks are already held. */
    public static function apply(MeekroDB $db, string $key, array $groups): void
    {
        if ($groups === [] || !array_filter($groups)) return;
        $repository = new ProductVariantSupplierRepository($db);
        if (!$repository->installed()) throw new InvalidArgumentException('Pro import odkazů variant nejprve aktualizuj databázi.');
        $existing = $repository->forProduct($key, true);
        $partners = (new PurchasePartnerRepository($db))->all(true);
        $changed = false;
        foreach ($groups as $variant => $links) foreach (self::normalize($links) as $link) {
            $id = $link['link_key'] ?? ''; $old = null;
            foreach ($existing as $candidate) {
                if ($candidate['variant_key'] === $variant && ($id !== '' ? $candidate['link_key'] === $id : $candidate['url'] === $link['url'])) {
                    $old = $candidate; break;
                }
            }
            if ($id !== '' && ($old === null || $old['version'] !== $link['version'])) {
                throw new InvalidArgumentException('Partnerský odkaz se mezitím změnil. Použij nový export.');
            }
            $partner = $link['partner_key'] ?? ($old['partner_key'] ?? '');
            if (!array_key_exists('partner_key', $link) && $old === null) {
                $matches = array_values(array_filter($partners, static fn(array $p): bool =>
                    ProductPartnerAssignment::host($p['website_url'] ?? '') === ProductPartnerAssignment::host($link['url'])));
                if (count($matches) === 1) $partner = $matches[0]['partner_key'] ?? '';
            }
            if ($old !== null) {
                if ($old['url'] === $link['url'] && $old['label'] === $link['label'] && ($old['partner_key'] ?? '') === $partner) continue;
                if ($id === '') throw new InvalidArgumentException('Změna existujícího odkazu vyžaduje link_key a version z exportu.');
            }
            $repository->saveInTransaction($key, $variant, $id, $link['version'] ?? 0, $partner, $link['label'], $link['url']);
            $changed = true;
        }
        $queue = new PartnerRefreshQueue($db);
        if ($changed && $queue->installed()) $queue->request($key, time());
    }

    /** Copies keep URLs, never the identity/version of the original product's offers. */
    public static function forCopy(array $data): array
    {
        unset($data['product']['suppliers_expected']);
        foreach ($data['product']['variants']['items'] ?? [] as $i => $variant) {
            if (!is_array($variant) || !is_array($variant['suppliers'] ?? null)) continue;
            foreach ($variant['suppliers'] ?? [] as $j => $link) {
                if (!is_array($link)) continue;
                unset($link['link_key'], $link['version']);
                $data['product']['variants']['items'][$i]['suppliers'][$j] = $link;
            }
        }
        return $data;
    }

    /** Explicit backup restore rebases link versions at preview time, never at execution. */
    public static function restore(array $items, array $existing): array
    {
        foreach ($items as &$variant) {
            if (!is_array($variant) || !array_key_exists('suppliers', $variant)) continue;
            $variant['suppliers'] = self::normalize($variant['suppliers']);
            foreach ($variant['suppliers'] as &$link) {
                $old = null;
                foreach ($existing as $candidate) {
                    if ($candidate['variant_key'] === ($variant['id'] ?? '') &&
                        ($candidate['link_key'] === ($link['link_key'] ?? '') || $candidate['url'] === $link['url'])) {
                        $old = $candidate; break;
                    }
                }
                unset($link['link_key'], $link['version']);
                if ($old !== null) $link += ['link_key' => $old['link_key'], 'version' => $old['version']];
            }
            unset($link);
        }
        unset($variant);
        return $items;
    }
}
