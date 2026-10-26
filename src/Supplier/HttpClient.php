<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

use InvalidArgumentException;
use RuntimeException;
use SimpleStore\Product\ProductPartnerAssignment;

/** An unauthenticated, bounded, DNS-pinned request; redirects are handled by the queue. */
final class HttpClient
{
    public static function url(string $url): string
    {
        $p = parse_url($url);
        if (!filter_var($url,FILTER_VALIDATE_URL) || strlen($url)>1000 || !is_array($p) ||
            ($p['scheme']??'') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) ||
            (isset($p['port']) && $p['port'] !== 443) || preg_match('/[\x00-\x20\x7f\\\\]/',$url) ||
            !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/Di',$p['host']??'')) {
            throw new InvalidArgumentException('Použij veřejný odkaz HTTPS bez přihlašovacích údajů a nestandardního portu.');
        }
        return strtolower($p['host']);
    }

    public static function publicIp(string $ip): bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
        // Exclude IPv4 transition/mapping, documentation, multicast, shared and benchmark ranges.
        if (str_contains($ip,':')) return preg_match('/^[23][0-9a-f]{3}:/i',$ip) === 1 &&
            !preg_match('/^(?:2001:(?:0:|db8:|2:|10:|20:)|2002:)/i',$ip);
        $n = ip2long($ip);
        foreach ([['100.64.0.0',10],['192.0.0.0',24],['192.0.2.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4]] as [$net,$bits]) {
            $mask = -1 << (32-$bits); if (($n & $mask) === (ip2long($net) & $mask)) return false;
        }
        return true;
    }

    public static function redirect(string $from, string $location): string
    {
        if (str_starts_with($location,'/') && !str_starts_with($location,'//')) $location = 'https://' . self::url($from) . $location;
        self::url($location);
        if (ProductPartnerAssignment::host($from) !== ProductPartnerAssignment::host($location)) throw new InvalidArgumentException('Přesměrování vede na jiného prodejce. Oprav dodavatelský odkaz.');
        return $location;
    }

    public function get(string $url): array
    {
        $host = self::url($url);
        if (!function_exists('curl_init')) throw new RuntimeException('Hosting nemá aktivní PHP cURL.');
        $records = dns_get_record($host,DNS_A|DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null; if ($ip === null) continue;
            if (!self::publicIp($ip)) throw new InvalidArgumentException('Dodavatelská doména nevede výhradně na veřejný server.');
            $ips[] = $ip;
        }
        if ($ips === []) throw new RuntimeException('Doménu dodavatele se nepodařilo přeložit.');
        usort($ips,static fn(string $a,string $b): int => (int)str_contains($a,':') <=> (int)str_contains($b,':'));
        $ip = str_contains($ips[0],':') ? '['.$ips[0].']' : $ips[0];
        $body = ''; $headers = []; $tooLarge = false;
        $curl = curl_init($url);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',
            CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_USERAGENT=>'DobrodruziSupplierCheck/1.0 (+https://eshop.dobrodruzi.cz/)',
            CURLOPT_HTTPHEADER=>[str_ends_with($url,'/robots.txt')?'Accept: text/plain':'Accept: text/html, text/markdown;q=0.8','Accept-Language: cs-CZ,cs;q=0.9'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,&$tooLarge):int{
                if(strlen($body)+strlen($chunk)>2097152){$tooLarge=true;return 0;} $body.=$chunk;return strlen($chunk);
            },CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers):int{
                if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=substr(trim($v),0,1200);}return strlen($line);
            }]);
        try {
            $ok = curl_exec($curl); $code = (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if($ok===false)throw new RuntimeException($tooLarge?'Stránka přesáhla limit 2 MB.':'Dodavatel neodpověděl včas nebo selhalo bezpečné spojení.');
            return ['code'=>$code,'body'=>$body,'headers'=>$headers];
        } finally { curl_close($curl); }
    }
}
