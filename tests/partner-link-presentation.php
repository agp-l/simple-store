<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use SimpleStore\Product\PartnerLinkPresentation;
$exact = ['link_key'=>'exact', 'variant_key'=>'blue-42', 'partner_key'=>'shop', 'url'=>'https://example.test/p?size=42', 'verification'=>'verified', 'origin'=>'discovered', 'result'=>['status'=>'exact','expires_at'=>200]];
$duplicate = ['link_key'=>'old', 'variant_key'=>'blue-42', 'partner_key'=>'shop', 'url'=>'https://example.test/p', 'origin'=>'inherited', 'result'=>['status'=>'duplicate','url'=>$exact['url']]];
$check = static function(bool $ok): void { if (!$ok) throw new RuntimeException('Unsafe partner-link presentation.'); };
$groups = PartnerLinkPresentation::partition([$exact,$duplicate],100);
$check($groups['active'] === [$exact] && $groups['redundant'] === [$duplicate]);
foreach ([array_replace($duplicate,['origin'=>'manual']),array_replace($duplicate,['partner_key'=>'another']),array_replace($duplicate,['variant_key'=>'red-42'])] as $keep) {
    $check(count(PartnerLinkPresentation::partition([$exact,$keep],100)['active']) === 2);
}
$check(count(PartnerLinkPresentation::partition([$exact,$duplicate],201)['active']) === 2);
$check(PartnerLinkPresentation::partition([$duplicate],100)['active'] === [$duplicate]);
$check(PartnerLinkPresentation::partition([array_replace($exact,['removed'=>1]),$duplicate],100)['active'] === [$duplicate]);
echo "Partner presentation preserves manual, stale and unmatched sources; only verified duplicates are secondary.\n";
