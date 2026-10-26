<?php
declare(strict_types=1);
namespace SimpleStore\Product;

/** Presentation only: retain every stored link, including useful fallback and manual addresses. */
final class PartnerLinkPresentation
{
    public static function partition(array $links, int $now): array
    {
        $verified = [];
        foreach ($links as $link) {
            if (($link['verification'] ?? '') === 'verified' && ($link['result']['status'] ?? '') === 'exact' &&
                (int) ($link['result']['expires_at'] ?? 0) > $now && empty($link['removed'])) {
                $verified[($link['variant_key'] ?? '') . ':' . $link['url']] = $link;
            }
        }
        $active = []; $redundant = [];
        foreach ($links as $link) {
            if (!empty($link['removed'])) continue;
            $target = $verified[($link['variant_key'] ?? '') . ':' . ($link['result']['url'] ?? '')] ?? null;
            if (($link['origin'] ?? '') === 'inherited' && ($link['result']['status'] ?? '') === 'duplicate' && $target &&
                $target['link_key'] !== $link['link_key'] && ($target['partner_key'] ?? '') === ($link['partner_key'] ?? '')) $redundant[] = $link;
            else $active[] = $link;
        }
        return compact('active', 'redundant');
    }

}
