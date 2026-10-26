<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use SimpleStore\Product\ProductPartnerAssignment;

/** One fetch per partner/URL, with explicit local variant and link identities. */
final class VariantOfferPlan
{
    public static function activeVariants(array $snapshot): array
    {
        if (empty($snapshot['variantSettings']['enabled'])) return [];
        return array_column(array_values(array_filter($snapshot['variants'] ?? [], static fn(array $v): bool => !empty($v['enabled']))), null, 'variant_key');
    }

    /** null means an unmigrated/simple product; [] means no usable variant source. */
    public static function candidates(array $snapshot): ?array
    {
        $variants = self::activeVariants($snapshot);
        $links = $snapshot['variantLinks'] ?? [];
        if ($variants === [] || $links === []) return null;
        $partners = array_column($snapshot['partners'], null, 'partner_key');
        $specific = [];
        foreach ($links as $link) if (empty($link['removed']) && ($link['origin'] ?? 'manual') !== 'inherited') {
            $specific[$link['variant_key'] . ':' . ($link['partner_key'] ?? '')] = true;
        }
        $sources = [];
        foreach ($links as $link) {
            if (!empty($link['removed']) || !isset($variants[$link['variant_key']])) continue;
            $pair = $link['variant_key'] . ':' . ($link['partner_key'] ?? '');
            if (($link['origin'] ?? '') === 'inherited' && isset($specific[$pair])) continue;
            $partner = $partners[$link['partner_key'] ?? ''] ?? null;
            if ($partner === null || ProductPartnerAssignment::host($partner['website_url']) !== ProductPartnerAssignment::host($link['url'])) continue;
            try { HttpClient::url($link['url']); } catch (\InvalidArgumentException $e) { continue; }
            $key = $partner['id'] . ':' . $link['url'];
            if (!isset($sources[$key])) $sources[$key] = [
                'id' => 0, 'kind' => 'variant', 'url' => $link['url'], 'partner_id' => (int) $partner['id'],
                'partner_key' => $partner['partner_key'], 'name' => $partner['name'], 'priority' => (int) $partner['priority'],
                'selected' => (int) $partner['id'] === (int) ($snapshot['sale']['partner_id'] ?? 0), 'links' => [],
            ];
            $sources[$key]['links'][] = array_intersect_key($link, array_flip(['link_key','variant_key','origin']));
        }
        $sources = array_values($sources);
        usort($sources, static fn(array $a, array $b): int =>
            [(int) !$a['selected'], $a['priority'], $a['partner_id'], $a['url']] <=>
            [(int) !$b['selected'], $b['priority'], $b['partner_id'], $b['url']]);
        return $sources;
    }
}
