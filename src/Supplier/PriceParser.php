<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use InvalidArgumentException;

/** Reads explicit offers, never guesses a price from arbitrary visible numbers. */
final class PriceParser
{
    public const VERSION = 5;
    /** Gramino advertises this public mirror in /.well-known/ai-catalog.json and /openapi.json. */
    public static function requestUrl(string $url):string
    {
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));$path=(string)parse_url($url,PHP_URL_PATH);
        if(in_array($host,['gramino.cz','www.gramino.cz'],true)&&str_starts_with($path,'/p/')){
            $query=parse_url($url,PHP_URL_QUERY);
            return 'https://'.$host.'/api/md'.$path.($query!==null?'?'.$query:'');
        }
        return HttpClient::withoutFragment($url);
    }

    public static function read(string $body, string $url, string $expectedName = ''): array
    {
        return self::select(self::document($body, $url), $url, $expectedName);
    }

    /** Cache the offer set, not a choice made for a particular local product. */
    public static function document(string $body, string $url): array
    {
        $documents = [];
        preg_match_all('~<script\b[^>]*\btype\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script\s*>~is', $body, $scripts);
        preg_match_all('~^```(?:json|jsonld)\s*\n(.*?)^```~ms', $body, $fences);
        foreach (array_merge($scripts[1], $fences[1]) as $json) {
            $node = json_decode(trim($json), true, 40);
            if (is_array($node)) $documents[] = $node;
        }
        $products = [];
        foreach ($documents as $node) self::products($node, $products);
        $offers = []; $singleOffers = true;
        foreach ($products as $product) {
            $raw = $product['offers'] ?? [];
            if (!is_array($raw)) {$singleOffers=false;continue;}
            if (isset($raw['@type'])) $raw = [$raw];
            if(count($raw)!==1)$singleOffers=false;
            foreach ($raw as $offer) {
                if (!is_array($offer) || !self::type($offer, 'Offer') || !isset($offer['price'], $offer['priceCurrency'])) continue;
                // Conditional/member prices and expired offers must not become the general cost.
                if (isset($offer['validForMemberTier']) || isset($offer['eligibleCustomerType']) ||
                    (isset($offer['priceValidUntil']) && (!is_string($offer['priceValidUntil']) || strtotime($offer['priceValidUntil'].' 23:59:59 UTC') < time()))) continue;
                if ($offer['priceCurrency'] !== 'CZK') continue;
                $price = self::amount($offer['price']);
                if ($price === null) continue;
                $source = is_string($offer['url'] ?? null) ? $offer['url'] : (is_string($product['url'] ?? null) ? $product['url'] : '');
                $availability = basename(str_replace('#', '/', (is_string($offer['availability'] ?? null) ? $offer['availability'] : '')));
                $evidence = count($products) === 1 && count($raw) === 1 ? Pod7kiloVariantIdentity::verify($body, $url, $product, $offer,
                    static fn(string $a, string $b): bool => self::identity($a) === self::identity($b)) : null;
                $offers[] = ['price' => $price, 'amount' => (string)$offer['price'], 'url' => $source,
                    'attributes'=>StructuredVariantScope::productAttributes($product,$body,$url,count($products)===1),
                    'supplier_uniform' => count($products)===1 && count($raw)===1 && Pod7kiloVariantIdentity::uniform($body,$url,$offer),
                    'identity_evidence' => $evidence, 'product_name' => is_string($product['name']??null)?$product['name']:'',
                    'group_url' => $product['_group_url']??'', 'group_name' => $product['_group_name']??'', 'availability' => match($availability) {
                        'InStock', 'LimitedAvailability' => 'in_stock',
                        'OutOfStock', 'SoldOut', 'Discontinued' => 'out_of_stock',
                        'PreOrder', 'PreSale', 'BackOrder' => 'on_order', default => 'unknown',
                    }];
            }
        }
        $document=['offers'=>$offers, 'product_count'=>count($products), 'single_offers'=>$singleOffers, 'parser_version'=>self::VERSION];
        if(count($products)===1&&count($offers)===1&&$singleOffers){
            $scoped=Pod7kiloVariantIdentity::offers($body,$url,$offers[0]);
            if($scoped===[])$scoped=FourCampingVariantIdentity::offers($body,$url,$offers[0]);
            if($scoped!==[])$document['scoped_offers']=$scoped;
        }
        return $document;
    }

    public static function select(array $document, string $url, string $expectedName = ''): array
    {
        $fourCamping=FourCampingVariantIdentity::select($document,$url);
        if($fourCamping!==null)return $fourCamping;
        $offers=$document['offers']??[];
        if ($offers === []) throw new InvalidArgumentException('Stránka neposkytla jednoznačnou strukturovanou cenu v CZK. Cena zůstala.');
        $all=$offers;
        $uniform=!empty($document['single_offers']) && count($all)>1 && count($all)===$document['product_count'] &&
            count(array_unique(array_column($all,'amount')))===1 &&
            count(array_unique(array_column($all,'group_url')))===1 && ($all[0]['group_url']??'')!=='' &&
            self::sameModel($all[0]['group_url'],$url);
        $exact = array_values(array_filter($offers, static fn(array $o): bool => self::identity($o['url']) === self::identity($url) || $o['identity_evidence'] !== null));
        $repair=null;
        // Gramino publishes stable variant URLs and full variant names in ProductGroup.
        // Repair an old/missing v_id only from ONE exact full-name match in the same model.
        if ($exact===[] && $expectedName!=='' && in_array(strtolower((string)parse_url($url,PHP_URL_HOST)),['gramino.cz','www.gramino.cz'],true)) {
            $matches=array_values(array_filter($offers,static fn(array $o):bool=>
                self::sameModel($url,$o['url']) && self::sameModel($url,$o['group_url']??'') &&
                self::name($o['product_name'])!=='' && self::name($o['product_name'])===self::name($expectedName)));
            if(count($matches)===1){$exact=$matches;$repair=['method'=>'exact_variant_name','verified_url'=>$matches[0]['url'],'name'=>$matches[0]['product_name']];}
        }
        if ($exact !== []) $offers = $exact;
        elseif ($uniform && self::identity($all[0]['group_url'])===self::identity($url)) $offers=$all;
        elseif (($document['product_count']??0) !== 1 || count($offers) !== 1) throw new InvalidArgumentException('Nabídka není jednoznačná. Doplň odkaz na konkrétní variantu; původní cena zůstala.');
        if (count(array_unique(array_column($offers,'amount'))) !== 1) throw new InvalidArgumentException('Nabídky uvádějí různé ceny. Vyžaduje kontrolu konkrétní varianty.');
        $result = $offers[0];
        if (count(array_unique(array_column($offers, 'availability'))) > 1) $result['availability'] = 'unknown';
        if($repair!==null){$result['identity_evidence']=$repair;$result['corrected_url']=$repair['verified_url'];}
        $groupExact=$uniform && self::identity($all[0]['group_url'])===self::identity($url);
        if($groupExact){$result['url']=$url;$result['identity_evidence']=['method'=>'uniform_product_group','verified_url'=>$url,'variants'=>count($all)];}
        $result['uniform_variants']=$uniform || !empty($result['supplier_uniform']);
        $result['uncertain'] = $exact === [] && !$groupExact && (parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null ||
            ($result['url'] !== '' && self::identity($result['url']) !== self::identity($url)));
        return $result;
    }

    private static function name(string $value): string
    {
        $value=str_replace(['™','®','’','‘'],['','','\'','\''],$value);
        $value=str_replace(['—','–','−'], '-', $value);
        $value=preg_replace('/\s*-\s*/u','-',trim($value));
        $value=preg_replace('/\s+/u',' ',$value);
        return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
    }

    public static function sameProductName(array $result,string $name):bool
    {
        $name=self::name($name);
        return $name!==''&&in_array($name,[self::name($result['product_name']??''),self::name($result['group_name']??'')],true);
    }

    public static function sameModel(string $a,string $b):bool
    {
        try{if(preg_replace('/^www\./','',HttpClient::url($a))!==preg_replace('/^www\./','',HttpClient::url($b)))return false;}catch(InvalidArgumentException $e){return false;}
        if(rtrim((string)parse_url($a,PHP_URL_PATH),'/')!==rtrim((string)parse_url($b,PHP_URL_PATH),'/'))return false;
        foreach([$a,$b]as$url){parse_str((string)parse_url($url,PHP_URL_QUERY),$query);if(array_diff(array_keys($query),['v_id'])!==[])return false;}
        return true;
    }

    public static function amount(mixed $value): ?int
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', (string)$value)) return null;
        $amount = (float)$value;
        return $amount > 0 && $amount <= 9999999 ? (int)ceil($amount) : null;
    }

    private static function type(array $node, string $type): bool
    {
        return in_array($type, (array)($node['@type'] ?? []), true);
    }

    private static function products(array $node, array &$products): void
    {
        if (self::type($node, 'Product')) $products[] = $node;
        // Do not traverse ItemList, recommendations or arbitrary nested page content.
        foreach (['@graph', 'hasVariant', 'mainEntity'] as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) continue;
            $items = isset($node[$key]['@type']) ? [$node[$key]] : $node[$key];
            foreach ($items as $item) if (is_array($item)) {
                if($key==='hasVariant' && self::type($node,'ProductGroup')){
                    $item['_group_url']=is_string($node['url']??null)?$node['url']:'';
                    $item['_group_name']=is_string($node['name']??null)?$node['name']:'';
                }
                self::products($item, $products);
            }
        }
        if (array_is_list($node)) foreach ($node as $item) if (is_array($item)) self::products($item, $products);
    }

    public static function sameOfferUrl(string $a, string $b): bool
    {
        return self::identity($a) !== '' && self::identity($a) === self::identity($b);
    }

    private static function identity(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) return '';
        $host = preg_replace('/^www\./', '', strtolower($p['host']));
        parse_str($p['query'] ?? '', $query);
        foreach (array_keys($query) as $key) if (str_starts_with($key, 'utm_') || in_array($key, ['gclid','fbclid'], true)) unset($query[$key]);
        ksort($query);
        return $host . rtrim($p['path'] ?? '/', '/') . '?' . http_build_query($query) . (isset($p['fragment']) ? '#' . $p['fragment'] : '');
    }
}
