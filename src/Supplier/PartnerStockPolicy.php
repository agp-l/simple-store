<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

/** Partner quantity is an order limit, never physical inventory owned by this shop. */
final class PartnerStockPolicy
{
    public static function quantity(mixed $value): ?int
    {
        if (is_array($value)) $value = $value['value'] ?? null;
        if(is_float($value)&&is_finite($value)&&floor($value)===$value&&$value>=0&&$value<=1000000)$value=(int)$value;
        if(is_string($value)&&preg_match('/^\d{1,7}\.0+$/D',$value))$value=(int)$value;
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d{1,7}$/D', $value))) return null;
        $number = (int)$value;
        return $number >= 0 && $number <= 1000000 ? $number : null;
    }
    /** Only call on the exact SKU's availability field, never the entire page. */
    public static function textQuantity(string $html): ?int
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if (preg_match('/(?:poslední\s+(?:1\s+)?kus\b|poslední\s+1\s*ks\b)/iu', $text)) return 1;
        if (preg_match('/(?:skladem\s*:?\s*|poslední(?:ch)?\s+)(\d+)\s*(?:ks\b|kus(?:y|ů)?\b)/iu', $text, $m)) return self::quantity($m[1]);
        return null;
    }
    public static function textAvailability(string $html, bool $inStock, bool $unavailable): string
    {
        if($unavailable)return 'out_of_stock';
        $count=self::textQuantity($html);
        if($count!==null)return $count>0?'in_stock':'out_of_stock';
        if(preg_match('/poslední(?:ch)?\s+(?:kusy|kusů)/iu',strip_tags($html)))return 'limited';
        return $inStock?'in_stock':'unknown';
    }
    public static function evidence(array $offers): array
    {
        $states=array_unique(array_column($offers,'availability'));
        $counts=array_unique(array_map(static fn(array $o):string=>json_encode($o['reported_quantity']??null),$offers));
        $complete=count(array_filter($offers,static fn(array $o):bool=>!empty($o['stock_evidence_version'])))===count($offers);
        return ['stock_evidence_version'=>$complete?1:0,'availability'=>count($states)===1&&count($counts)===1?reset($states):'unknown','reported_quantity'=>count($counts)===1?json_decode(reset($counts),true):null];
    }
    public static function limit(array $offer): ?int
    {
        $state = $offer['availability'] ?? 'unknown';
        if (in_array($state, ['out_of_stock','on_order'], true)) return 0;
        if (!in_array($state, ['in_stock','limited'], true)) return null;
        $reported = self::quantity($offer['reported_quantity'] ?? null);
        if ($reported !== null) return $reported > 1 ? $reported : 0;
        return $state === 'limited' ? 0 : 3;
    }
}
