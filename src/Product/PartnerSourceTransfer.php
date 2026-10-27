<?php
declare(strict_types=1);
namespace SimpleStore\Product;

use InvalidArgumentException;
use MeekroDB;

/** Add sources only. Neither imported prices nor inferred variant identities are trusted. */
final class PartnerSourceTransfer
{
    public const FORMAT = 'simple-store.partner-sources.v1';
    public function __construct(private MeekroDB $db, private ProductRepository $products, private array $languages) {}

    public function prepare(array $documents): array
    {
        $items = []; $seen = [];
        foreach ($documents as $index => $document) {
            $item = ['kind'=>'partner-sources', 'folder'=>'Položka '.($index+1), 'status'=>'pending', 'message'=>''];
            try {
                self::fields($document, ['product_key','language','name','sources']);
                $key = $document->product_key ?? null; $language = $document->language ?? 'cs';
                if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key) || !is_string($language) || !in_array($language,$this->languages,true) || isset($seen[$key])) throw new InvalidArgumentException('Neplatný nebo opakovaný produkt / jazyk.');
                $seen[$key] = true;
                $current = $this->products->current($key,$language);
                if ($current === null) throw new InvalidArgumentException('Produkt již neexistuje.');
                $item['folder'] = $current['name'];
                $sources = $document->sources ?? null;
                if (!is_array($sources) || count($sources)>1000) throw new InvalidArgumentException('Použij seznam nejvýše 1 000 zdrojů produktu.');
                $validated = [];
                foreach ($sources as $source) {
                    self::fields($source,['url','label','partner_key','variant_key','choices']);
                    if (!is_string($source->url ?? null) || !is_string($source->label ?? null)) throw new InvalidArgumentException('Zdroj potřebuje label a url.');
                    $url = trim($source->url); $label = trim($source->label);
                    ProductSupplierLinkRepository::validate($label,$url);
                    \SimpleStore\Supplier\HttpClient::url($url);
                    $partner = $this->partner($url,$source->partner_key ?? '');
                    $variant = $source->variant_key ?? '';
                    if (!is_string($variant) || ($variant!=='' && !preg_match('/^[a-f0-9]{32}$/D',$variant))) throw new InvalidArgumentException('Neplatný klíč varianty.');
                    $choices = $source->choices ?? null;
                    if ($variant!=='' && !$choices instanceof \stdClass) throw new InvalidArgumentException('Přesný odkaz varianty potřebuje také její choices z exportu.');
                    if ($variant==='' && $choices!==null) throw new InvalidArgumentException('choices patří jen ke konkrétní variantě.');
                    $validated[] = ['url'=>$url,'label'=>$label,'partner_key'=>$partner,'variant_key'=>$variant,'choices'=>$choices===null?null:(array)$choices];
                }
                $this->validateVariants($key,$validated);
                $item += ['key'=>$key,'language'=>$language,'sources'=>$validated];
                $item['message'] = count($validated).' zdrojů k doplnění; existující odkazy, prodávající, ceny a sklad zůstávají. Shodu variant ověří načítání.';
            } catch (InvalidArgumentException $e) { $item['status']='error'; $item['message']=$e->getMessage(); }
            $items[] = $item;
        }
        return $items;
    }

    public function import(array $item): array
    {
        $variants = new ProductVariantSupplierRepository($this->db);
        return $variants->transaction($item['key'],function() use ($item,$variants): array {
            $current = $this->products->current($item['key'],$item['language']);
            if ($current===null) throw new InvalidArgumentException('Produkt již neexistuje.');
            $this->validateVariants($item['key'],$item['sources']);
            $common = new ProductSupplierLinkRepository($this->db);
            $existing = array_column($common->forProduct($item['key'],true),null,'url');
            $links = $variants->forProduct($item['key'],true,true); $added = 0; $inherit = false;
            foreach ($item['sources'] as $source) {
                $this->partner($source['url'],$source['partner_key']);
                if ($source['variant_key']==='') {
                    if (isset($existing[$source['url']])) continue;
                    $common->save($item['key'],0,$source['label'],$source['url']);
                    $existing[$source['url']]=$source; $added++; $inherit=true;
                } else {
                    $matches=array_filter($links,static fn(array $l):bool=>$l['variant_key']===$source['variant_key'] && $l['url']===$source['url']);
                    if ($matches!==[]) {
                        foreach ($matches as $match) if (!empty($match['removed'])) throw new InvalidArgumentException('Tento variantní odkaz byl dříve odebrán. Automaticky jej neobnovíme.');
                        continue;
                    }
                    $id=$variants->saveInTransaction($item['key'],$source['variant_key'],'',0,$source['partner_key'],$source['label'],$source['url']);
                    $links[]=['link_key'=>$id,'variant_key'=>$source['variant_key'],'url'=>$source['url'],'removed'=>0]; $added++;
                }
            }
            if ($inherit) $variants->inheritInTransaction($item['key']);
            if ($added>0) {
                (new PartnerRefreshQueue($this->db))->request($item['key'],0);
                (new \SimpleStore\Supplier\PartnerOfferCatalog($this->db))->request($item['key']);
            }
            return ['product_key'=>$item['key'],'language'=>$item['language'],'name'=>$current['name'],'slug'=>$current['slug'],
                'message'=>$current['name'].' — přidáno '.$added.' zdrojů; opakované odkazy přeskočeny.'];
        });
    }
    private function validateVariants(string $key,array $sources): void
    {
        $snapshot=(new ProductVariantRepository($this->db))->snapshot($key);
        $variants=array_column($snapshot['items'],null,'id');
        foreach ($sources as $source) if ($source['variant_key']!=='') {
            $variant=$variants[$source['variant_key']]??null;
            if (!$snapshot['enabled'] || !$variant || !$variant['enabled'] || $variant['choices']!=$source['choices']) throw new InvalidArgumentException('Varianta nebo její vlastnosti neodpovídají aktuálnímu produktu. Použij čerstvý export.');
        }
    }
    private function partner(string $url,mixed $key): string
    {
        if (!is_string($key)) throw new InvalidArgumentException('Neplatný partner.');
        $matches=array_values(array_filter((new PurchasePartnerRepository($this->db))->all(),static fn(array $p):bool=>ProductPartnerAssignment::host($p['website_url'])===ProductPartnerAssignment::host($url)));
        if (count($matches)!==1 || ($key!=='' && $matches[0]['partner_key']!==$key)) throw new InvalidArgumentException('URL musí patřit právě jednomu existujícímu partnerovi. Nejprve zkontroluj Prodávající.');
        return $matches[0]['partner_key'];
    }
    private static function fields(mixed $value,array $allowed): void
    {
        if (!$value instanceof \stdClass || array_diff(array_keys((array)$value),$allowed)) throw new InvalidArgumentException('Neplatný JSON zdrojů. Ceny, dostupnost ani výsledek ověření se neimportují.');
    }
}
