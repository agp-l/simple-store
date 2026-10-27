<?php
declare(strict_types=1);
namespace SimpleStore\Seo;

use MeekroDB;
use SimpleStore\Navigation\UrlManager;

/** Bounded sitemap shards contain public, current revisions only. */
final class Sitemap
{
    public const PAGE_SIZE=1000;
    public function __construct(private MeekroDB $db,private array $languages) {}
    private function query(): array
    {
        $marks=implode(',',array_fill(0,count($this->languages),'%s'));
        $sql="SELECT 'product' AS kind, language, slug FROM shop_product_revisions WHERE published=1 AND active_product_key IS NOT NULL AND language IN ($marks)";
        $args=$this->languages;
        if((int)$this->db->queryFirstField('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s','shop_content_revisions')===1){
            $sql.=" UNION ALL SELECT type AS kind, language, slug FROM shop_content_revisions WHERE published=1 AND active_document_key IS NOT NULL AND type IN ('page','post') AND language IN ($marks)";
            $args=array_merge($args,$this->languages);
        }
        return [$sql,$args];
    }
    public function pages(): int
    {
        [$sql,$args]=$this->query();
        return max(1,(int)ceil((int)$this->db->queryFirstField('SELECT COUNT(*) FROM ('.$sql.') public_pages',...$args)/self::PAGE_SIZE));
    }
    public function urls(int $page,UrlManager $url,string $base): array
    {
        [$sql,$args]=$this->query();
        $rows=$this->db->query('SELECT kind,language,slug FROM ('.$sql.') public_pages ORDER BY language,kind,slug LIMIT %i OFFSET %i',...array_merge($args,[self::PAGE_SIZE,($page-1)*self::PAGE_SIZE]));
        $urls=[];
        if($page===1)foreach($this->languages as$language)$urls[]=PublicSite::absolute($base,$url->path('',$language),$url->getBasePath());
        foreach($rows as$row){
            $relative=match($row['kind']){'product'=>'produkt/','post'=>'blog/',default=>''}.$row['slug'];
            try{
                $path=$url->path($relative,$row['language']);
                $route=(new UrlManager($path,$url->getBasePath().'index.php',$this->languages,$this->languages[0]))->route();
                if($route['name']!==$row['kind'])continue;
                $urls[]=PublicSite::absolute($base,$path,$url->getBasePath());
            }
            catch(\InvalidArgumentException $error){continue;} // Invalid legacy slugs are not crawlable routes.
        }
        return array_values(array_unique($urls));
    }
    public static function xml(array $urls,bool $index=false): string
    {
        $root=$index?'sitemapindex':'urlset';$tag=$index?'sitemap':'url';
        $xml='<?xml version="1.0" encoding="UTF-8"?>'."\n".'<'.$root.' xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach($urls as$url)$xml.='  <'.$tag.'><loc>'.htmlspecialchars($url,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></'.$tag.'>'."\n";
        return $xml.'</'.$root.'>'."\n";
    }
}
