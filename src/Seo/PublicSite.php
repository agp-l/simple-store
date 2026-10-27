<?php
declare(strict_types=1);
namespace SimpleStore\Seo;

/** Absolute public addresses come from configuration, never from the request Host. */
final class PublicSite
{
    public static function base(string $configured, string $basePath): string
    {
        $parts=parse_url($configured);
        if(!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])||
            isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||
            rtrim($parts['path']??'','/')!==rtrim($basePath,'/')||preg_match('/[\x00-\x20\x7f]/',$configured))return '';
        return rtrim($configured,'/');
    }
    public static function absolute(string $base,string $path,string $basePath): string
    {
        if($base===''||!str_starts_with($path,$basePath)||str_contains($path,'\\'))return '';
        return $base.'/'.ltrim(substr($path,strlen($basePath)),'/');
    }
    public static function robots(string $base,string $basePath,array $languages): string
    {
        $text="User-agent: *\n";
        foreach(['admin','account','admin.php','account.php','support.php','discovery.php','src/','config/','database/','vendor/']as$path)$text.='Disallow: '.$basePath.$path."\n";
        foreach($languages as$language)foreach(['kosik','pokladna','objednavka/']as$path)$text.='Disallow: '.$basePath.$language.'/'.$path."\n";
        // Query-driven editor/search pages carry noindex in HTML; allowing the
        // crawl lets robots see that directive. robots.txt is not access control.
        if($base!=='')$text.="\nSitemap: ".$base."/sitemap.xml\n";
        return $text;
    }
}
