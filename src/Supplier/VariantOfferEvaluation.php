<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use InvalidArgumentException;

/** Pure evidence collection and all-or-nothing price decisions for one merchant. */
final class VariantOfferEvaluation
{
    public static function observe(array $document, string $sourceUrl, array $snapshot, array $candidate, int $observedAt): array
    {
        $variants = VariantOfferPlan::activeVariants($snapshot);
        $observations = []; $issues = [];
        foreach ($candidate['links'] as $link) {
            $variant = $variants[$link['variant_key']] ?? null;
            if ($variant === null) continue;
            $match = VariantLinkMatcher::match($document, $sourceUrl, $snapshot['products'][0]['name'], $variant);
            if ($match['status'] === 'exact' && ($link['origin'] ?? 'manual') === 'manual' &&
                !PriceParser::sameOfferUrl($match['url'], $candidate['url']) &&
                (empty($candidate['resolved_url']) || !PriceParser::sameOfferUrl($match['url'], $candidate['resolved_url']))) {
                $match = ['status'=>'variant_identity', 'message'=>'Ručně uložený odkaz nevede na odpovídající variantu. Oprav jeho adresu.'];
            }
            if ($match['status'] !== 'exact') {
                $issues[] = $link + ['url'=>$candidate['url'], 'status'=>$match['status'], 'message'=>$match['message']];
                continue;
            }
            $offer = $match['offer'];
            $observations[$link['link_key']] = [
                'link_key'=>$link['link_key'], 'variant_key'=>$link['variant_key'], 'partner_id'=>$candidate['partner_id'],
                'partner_key'=>$candidate['partner_key'], 'url'=>$match['url'], 'source_url'=>$sourceUrl, 'requested_url'=>$candidate['url'],
                'price'=>(int) $offer['price'], 'amount'=>number_format((float) $offer['amount'], 2, '.', ''),
                'currency'=>'CZK', 'availability'=>$offer['availability'] ?? 'unknown', 'observed_at'=>$observedAt,
                'identity_evidence'=>$match['evidence'],
            ];
        }
        return compact('observations', 'issues');
    }

    public static function assemble(array $snapshot, array $observations, int $partnerId, int $now, array $pricing): array
    {
        $variants = VariantOfferPlan::activeVariants($snapshot);
        if ($variants === []) throw new InvalidArgumentException('Produkt nemá aktivní varianty.');
        $offers = []; $oldest = $now;
        foreach ($variants as $key => $variant) {
            $found = array_values(array_filter($observations, static fn(array $o): bool =>
                (int) $o['partner_id'] === $partnerId && $o['variant_key'] === $key));
            if ($found === []) throw new InvalidArgumentException('Chybí přesná nabídka varianty: ' . self::label($variant) . '. Ceny zůstaly beze změny.');
            $first = $found[0];
            foreach ($found as $offer) {
                if ($offer['amount'] !== $first['amount'] || !PriceParser::sameOfferUrl($offer['url'], $first['url'])) {
                    throw new InvalidArgumentException('Rozporné partnerské nabídky varianty: ' . self::label($variant) . '. Ceny zůstaly beze změny.');
                }
                if ((int) $offer['observed_at'] <= $now - 86400 || (int) $offer['observed_at'] > $now) {
                    throw new InvalidArgumentException('Uložená nabídka některé varianty vypršela. Spusť novou kontrolu.');
                }
                $oldest = min($oldest, (int) $offer['observed_at']);
            }
            $states = array_unique(array_column($found, 'availability'));
            $offers[$key] = $first + ['urls'=>[$first['url']]];
            $offers[$key]['availability'] = count($states) === 1 ? reset($states) : 'unknown';
            $offers[$key]['link_keys'] = array_column($found, 'link_key');
        }
        $first = reset($offers); $uniform = count(array_unique(array_column($offers, 'amount'))) === 1;
        $states = array_unique(array_column($offers, 'availability'));
        $result = [
            'price'=>min(array_column($offers, 'price')), 'amount'=>min(array_column($offers, 'amount')), 'url'=>$first['url'],
            'availability'=>count($states) === 1 ? reset($states) : 'unknown', 'uncertain'=>false, 'uniform_variants'=>$uniform,
            'observed_at'=>$oldest, 'canonical_variant_offers'=>$offers,
            'identity_evidence'=>['method'=>'variant_partner_links', 'local_variants'=>count($variants),
                'matched_offers'=>array_values($offers)],
        ];
        $inherited = PriceEvaluation::mayEnableVariantPricing($snapshot, $pricing);
        if (!empty($snapshot['variantPriceMode']['enabled']) || !$uniform) $result['variant_offers'] = $offers;
        if ($inherited && !$uniform) $result['enable_variant_pricing'] = true;
        return $result;
    }

    private static function label(array $variant): string
    {
        $choices = json_decode($variant['choices_json'], true);
        return implode(' / ', is_array($choices) ? array_values($choices) : [$variant['variant_key']]);
    }
}
