<?php
declare(strict_types=1);
namespace SimpleStore\Supplier;

final class Robots
{
    public static function policy(string $body, string $url): array
    {
        $groups = []; $agents = []; $rules = []; $started = false;
        foreach (preg_split('/\r?\n/', $body) as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (!str_contains($line, ':')) continue;
            [$key, $value] = array_map('trim', explode(':', $line, 2)); $key = strtolower($key);
            if ($key === 'user-agent') {
                if ($started) { $groups[] = [$agents, $rules]; $agents = []; $rules = []; $started = false; }
                $agents[] = strtolower($value);
            } elseif ($agents !== [] && in_array($key, ['allow','disallow','crawl-delay'], true)) {
                $rules[] = [$key,$value]; $started = true;
            }
        }
        if ($agents !== []) $groups[] = [$agents,$rules];
        $matches = []; $specificity = -1;
        foreach ($groups as [$agents,$rules]) {
            $length = -1;
            foreach ($agents as $agent) $length = max($length, $agent === '*' ? 0 :
                ($agent !== '' && str_contains('dobrodruzisuppliercheck', $agent) ? strlen($agent) : -1));
            if ($length < 0 || $length < $specificity) continue;
            if ($length > $specificity) { $matches = []; $specificity = $length; }
            $matches = array_merge($matches,$rules);
        }
        $path = (string)(parse_url($url,PHP_URL_PATH) ?: '/');
        $query = parse_url($url,PHP_URL_QUERY); if ($query !== null) $path .= '?' . $query;
        $allowed = true; $longest = -1; $delay = 0;
        foreach ($matches as [$key,$value]) {
            if ($key === 'crawl-delay') { if (is_numeric($value)) $delay = max($delay,(int)ceil((float)$value)); continue; }
            if ($value === '') continue;
            $end = str_ends_with($value,'$'); $pattern = $end ? substr($value,0,-1) : $value;
            $regex = '~^' . str_replace('\\*','.*',preg_quote($pattern,'~')) . ($end ? '$' : '') . '~';
            if (preg_match($regex,$path) !== 1 && preg_match($regex,rawurldecode($path)) !== 1) continue;
            $length = strlen(str_replace('*','',$pattern));
            if ($length > $longest || ($length === $longest && $key === 'allow')) { $longest = $length; $allowed = $key === 'allow'; }
        }
        return ['allowed'=>$allowed,'delay'=>$delay];
    }
}
