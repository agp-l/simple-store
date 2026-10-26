<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Maps one local combination to one explicit offer. Equal prices never prove identity. */
final class VariantLinkMatcher
{
    public const VERSION = 1;

    public static function match(array $document, string $source, string $name, array $variant, ?string $exactUrl = null): array
    {
        $none = ['status' => 'no_match', 'message' => 'Nabídka nepotvrzuje přesně tuto kombinaci vlastností.'];
        try { $host = preg_replace('/^www\./', '', HttpClient::url($source)); }
        catch (\InvalidArgumentException $e) { return $none; }
        if (!in_array($host, ['gramino.cz', 'pod7kilo.cz', 'hudy.cz'], true)) return ['status' => 'unsupported', 'message' => 'Tento obchod zatím nepodporuje automatické párování variant.'];
        // Version 3 already preserves Gramino's explicit ProductGroup names and URLs.
        if ((int) ($document['parser_version'] ?? 0) < 3) return ['status' => 'old_parser', 'message' => 'Uložená nabídka vyžaduje novější načtení s vlastnostmi variant.'];
        if($exactUrl!==null){
            // A saved exact URL selects its SKU even when another SKU has identical colour/size.
            // All model and attribute checks below still apply; inherited model links never select.
            $complete=count($document['offers']??[])===($document['product_count']??0);
            foreach(['offers','scoped_offers'] as $field)if(isset($document[$field]))
                $document[$field]=array_values(array_filter($document[$field],static fn(array $offer):bool=>PriceParser::sameOfferUrl($offer['url'],$exactUrl)));
            if($complete)$document['product_count']=count($document['offers']??[]);
        }
        $choices = json_decode($variant['choices_json'] ?? '{}', true);
        if (!is_array($choices) || !$choices) return $none;
        $id = $variant['variant_key'];
        $scope = ['products' => [['name' => $name]], 'variantSettings' => ['enabled' => 1], 'variantPriceMode' => ['enabled' => 1],
            'variants' => [array_replace($variant, ['enabled' => 1])]];
        $result = GraminoVariantScope::select($document, $source, $scope);
        if ($result === null) {
            // Structured attributes alone do not prove that a URL belongs to the local model.
            $result = StructuredVariantScope::select($document, $source, $scope);
            if ($result !== null && !PriceParser::sameProductName($result, SupplierCatalogAliases::name($name, $result['group_name'] ?? '', $source))) $result = null;
        }
        if ($result === null || empty($result['variant_offers'][$id])) return $none;
        $urls = $result['variant_offers'][$id]['urls'];
        if (count($urls) !== 1 || count($result['identity_evidence']['matched_offers'] ?? []) !== 1) return ['status' => 'ambiguous', 'message' => 'Kombinaci odpovídá více nabídek. Přesný odkaz vyžaduje kontrolu.'];
        $target = $urls[0];
        try { if (preg_replace('/^www\./', '', HttpClient::url($target)) !== $host) return $none; }
        catch (\InvalidArgumentException $e) { return $none; }
        // One address shared by multiple SKUs is not a variant deep link.
        $offers = $document['scoped_offers'] ?? $document['offers'] ?? [];
        $sameUrl = array_values(array_filter($offers, static fn(array $offer): bool => ($offer['url'] ?? '') === $target));
        if (count($sameUrl) !== 1) return ['status' => 'ambiguous', 'message' => 'Dodavatel používá stejnou adresu pro více nabídek.'];
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        if ($host === 'hudy.cz' && (empty($query['variant']) || (isset(StructuredVariantScope::attributes($choices)['size']) && empty($query['size'])))) return ['status' => 'missing_selector', 'message' => 'Odkaz HUDY neobsahuje konkrétní barvu a velikost.'];
        $offer = $sameUrl[0];
        return ['status' => 'exact', 'message' => 'Jedna nabídka odpovídá modelu i vlastnostem této varianty.', 'url' => $target,
            'offer' => array_intersect_key($offer, array_flip(['url', 'product_name', 'group_name', 'attributes', 'amount', 'price', 'availability'])),
            'evidence' => $result['identity_evidence'], 'choices' => $choices];
    }
}
