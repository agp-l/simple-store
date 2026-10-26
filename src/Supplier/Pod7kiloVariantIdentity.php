<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Pod 7 kilo uses the model URL in Offer, but identifies the selected variant in page JSON. */
final class Pod7kiloVariantIdentity
{
    public static function offers(string $body,string $url,array $offer):array
    {
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        if(!in_array($host,['pod7kilo.cz','www.pod7kilo.cz'],true)||!self::localUrl($offer['url'],$host))return [];
        $parent=rtrim((string)parse_url($offer['url'],PHP_URL_PATH),'/');
        $path=rtrim((string)parse_url($url,PHP_URL_PATH),'/');
        if(!str_starts_with($parent,'/p/')||($path!==$parent&&!str_starts_with($path,$parent.'/')))return [];
        $data=EmbeddedVariantData::read($body);
        if(!$data||!is_string($data['currency']??null)||strtoupper($data['currency'])!=='CZK'||empty($data['variantsInfo'])||!is_array($data['variantsInfo']))return [];
        $selected=$data['variantsInfo'][$data['selectedVariantId']??'']??null;
        if(!is_array($selected)||PriceParser::amount($selected['price']??null)===null)return [];
        $out=[];
        foreach($data['variantsInfo']as$id=>$v){
            if(!is_array($v)||!self::sameIdentifier($v['id']??null,$id)||!self::sameIdentifier($v['productId']??null,$data['productId']??null)||
                PriceParser::amount($v['price']??null)===null||!is_string($v['url']??null)||!is_string($v['name']??null))return [];
            $source=$v['url'];
            if(str_starts_with($source,'/')&&!str_starts_with($source,'//'))$source='https://'.$host.$source;
            if(!self::localUrl($source,$host)||!str_starts_with((string)parse_url($source,PHP_URL_PATH),$parent.'/'))return [];
            preg_match_all('/(?:^|,\s+|\s+\/\s+)([^:,\/]+):\s*(.*?)(?=,\s+[^:,]+:|\s+\/\s+[^:]+:|$)/u',$v['name'],$parts,PREG_SET_ORDER);
            $values=[];foreach($parts as$p)$values[trim($p[1])]=trim($p[2]);
            $attributes=StructuredVariantScope::attributes($values);
            if($attributes!==null)$attributes+=($offer['attributes']??[]);
            if(!$attributes)return [];
            $out[]=['url'=>$source,'price'=>PriceParser::amount($v['price']),'amount'=>(string)$v['price'],
                'product_name'=>is_string($v['productNameWithVariant']??null)?$v['productNameWithVariant']:$offer['product_name'],'group_name'=>$offer['product_name'],'group_url'=>$offer['url'],
                'stock_evidence_version'=>1, 'reported_quantity'=>PartnerStockPolicy::textQuantity(is_string($v['availability']??null)?$v['availability']:''),'attributes'=>$attributes,'availability'=>PartnerStockPolicy::textAvailability(is_string($v['availability']??null)?$v['availability']:'',is_string($v['availability']??null)&&str_contains($v['availability'],'availability-in-stock'),is_string($v['availability']??null)&&str_contains($v['availability'],'availability-out-of-stock')),'identity_evidence'=>null];
        }
        // The base page may advertise the minimum, while preselecting a dearer version.
        // Exact variant pages must still agree with the selected SKU, without exception.
        $reference=$path===$parent?min(array_column($data['variantsInfo'],'price')):$selected['price'];
        if(PriceParser::amount($reference)===null||number_format((float)$reference,2,'.','')!==number_format((float)$offer['amount'],2,'.',''))return [];
        return $out;
    }
    /** Technical parameters only, never related-product descriptions or arbitrary digits. */
    public static function specifications(string $body,string $url):array
    {
        if(!in_array(strtolower((string)parse_url($url,PHP_URL_HOST)),['pod7kilo.cz','www.pod7kilo.cz'],true))return [];
        preg_match_all('~<tr\b[^>]*>\s*<th\b[^>]*>\s*Kapacita\s*</th>\s*<td\b[^>]*>(.*?)</td>\s*</tr>~isu',$body,$rows);
        $capacities=[];
        foreach($rows[1]as$row){
            $text=trim(html_entity_decode(strip_tags($row),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if(!preg_match('/^(\d{1,6})\s*mAh(?:\s|$)/u',$text,$m))return [];
            $capacities[]=$m[1].'mAh';
        }
        return count(array_unique($capacities))===1?['Kapacita'=>$capacities[0]]:[];
    }

    public static function verify(string $body, string $url, array $product, array $offer, callable $sameUrl): ?array
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['pod7kilo.cz', 'www.pod7kilo.cz'], true) || parse_url($url, PHP_URL_SCHEME) !== 'https') return null;
        $source = $offer['url'] ?? null;
        if (!is_string($source) || !self::localUrl($source, $host)) return null;
        $parent = rtrim((string)parse_url($source, PHP_URL_PATH), '/') . '/';
        $path = (string)parse_url($url, PHP_URL_PATH);
        // A model URL may identify its own variant, never an unrelated model or sibling variant.
        if (!str_starts_with($parent, '/p/') || !str_starts_with($path, $parent) || rtrim($path, '/') === rtrim($parent, '/')) return null;

        if (!preg_match('~<head\b[^>]*>(.*?)</head\s*>~is', $body, $head)) return null;
        preg_match_all('~<link\b([^>]*)>~is', $head[1], $links);
        $canonical = [];
        foreach ($links[1] as $tag) {
            $attributes = self::attributes($tag);
            if (in_array('canonical', preg_split('/\s+/', strtolower($attributes['rel'] ?? '')), true)) $canonical[] = $attributes['href'] ?? '';
        }
        if (count($canonical) !== 1 || !self::localUrl($canonical[0], $host) || !$sameUrl($canonical[0], $url)) return null;

        $data = EmbeddedVariantData::read($body);
        if ($data === null) return null;
        if (($data['variantsUrlEnabled'] ?? null) !== true || !is_string($data['currency'] ?? null) ||
            strtoupper($data['currency']) !== 'CZK' || !is_array($data['variantsInfo'] ?? null)) return null;
        $selected = $data['selectedVariantId'] ?? null;
        if ((!is_string($selected) && !is_int($selected)) || !ctype_digit((string)$selected)) return null;
        $variant = $data['variantsInfo'][$selected] ?? null;
        if (!is_array($variant) || !self::sameIdentifier($variant['id'] ?? null, $selected) ||
            !self::sameIdentifier($variant['productId'] ?? null, $data['productId'] ?? null)) return null;
        $variantUrl = $variant['url'] ?? null;
        if (!is_string($variantUrl)) return null;
        if (str_starts_with($variantUrl, '/') && !str_starts_with($variantUrl, '//')) $variantUrl = 'https://' . $host . $variantUrl;
        if (!self::localUrl($variantUrl, $host) || !$sameUrl($variantUrl, $url)) return null;

        // The selected variant must agree with the JSON-LD product, not just have a similar name.
        $ean = $product['gtin13'] ?? null;
        if ((!is_string($ean) && !is_int($ean)) || preg_match('/^\d{13}$/D', (string)$ean) !== 1 ||
            !self::sameIdentifier($ean, $variant['ean'] ?? null) ||
            !self::sameIdentifier($product['mpn'] ?? null, $variant['code'] ?? null)) return null;
        $price = $variant['price'] ?? null;
        if (PriceParser::amount($price) === null || PriceParser::amount($offer['price'] ?? null) === null ||
            number_format((float)$price, 2, '.', '') !== number_format((float)$offer['price'], 2, '.', '')) return null;
        if (($offer['priceCurrency'] ?? null) !== 'CZK') return null;

        return ['method' => 'pod7kilo_selected_variant', 'verified_url' => $url,
            'variant_id' => (string)$selected, 'ean' => (string)$ean];
    }

    public static function uniform(string $body,string $url,array $offer):bool
    {
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        if(!in_array($host,['pod7kilo.cz','www.pod7kilo.cz'],true))return false;
        $data=EmbeddedVariantData::read($body);
        if($data===null||!is_string($data['currency']??null)||strtoupper($data['currency'])!=='CZK'||($offer['priceCurrency']??'')!=='CZK'||
            !is_array($data['variantsInfo']??null)||count($data['variantsInfo'])<2)return false;
        foreach($data['variantsInfo']as$id=>$variant){
            if(!is_array($variant)||!self::sameIdentifier($variant['id']??null,$id)||
                !self::sameIdentifier($variant['productId']??null,$data['productId']??null)||
                PriceParser::amount($variant['price']??null)===null||PriceParser::amount($offer['price']??null)===null||
                number_format((float)$variant['price'],2,'.','')!==number_format((float)$offer['price'],2,'.',''))return false;
        }
        return true;
    }

    private static function sameIdentifier(mixed $a, mixed $b): bool
    {
        return (is_string($a) || is_int($a)) && (is_string($b) || is_int($b)) && (string)$a !== '' && (string)$a === (string)$b;
    }

    private static function localUrl(string $url, string $host): bool
    {
        try { $actual = HttpClient::url($url); } catch (\InvalidArgumentException $error) { return false; }
        return preg_replace('/^www\./', '', $actual) === preg_replace('/^www\./', '', $host);
    }

    private static function attributes(string $tag): array
    {
        preg_match_all('~\b([a-z][a-z0-9:_-]*)\s*=\s*(["\'])(.*?)\2~is', $tag, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (isset($attributes[$name])) return [];
            $attributes[$name] = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attributes;
    }
}
