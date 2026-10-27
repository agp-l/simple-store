<?php
declare(strict_types=1);

namespace SimpleStore\Navigation;

use InvalidArgumentException;

/** One route grammar for private HTML pages, redirects and password recovery links. */
final class PanelUrl
{
    private const SECTIONS = [
        'admin' => ['contents', 'products', 'product-removal', 'purchase-partners', 'supplier-prices', 'product-import', 'content-import',
            'orders', 'returns', 'accounting', 'settings', 'users', 'database', 'media', 'categories', 'menus'],
        'account' => ['orders', 'returns', 'addresses', 'payments', 'settings'],
    ];
    private const MODES = ['login' => '', 'register' => 'register', 'forgot' => 'forgot-password', 'reset' => 'reset-password'];

    /** Normalize old internal URL builders while retaining filters, IDs and fragments. */
    public static function pretty(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['host']) || isset($parts['scheme']) || preg_match('~^(.*?/)(admin|account)(?:\.php|/)$~D', $parts['path'] ?? '', $match) !== 1) return $url;
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $path = self::path($match[1], $match[2], $query);
        return $path . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public static function escape(mixed $url): string
    {
        return htmlspecialchars(self::pretty((string) $url), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function path(string $basePath, string $area, array $query = []): string
    {
        if (!isset(self::SECTIONS[$area]) || !str_starts_with($basePath, '/') || str_starts_with($basePath, '//') ||
            preg_match('/[\\\\\x00-\x20\x7f?#]/', $basePath) || preg_match('~(?:^|/)\.\.?(?:/|$)~', $basePath)) throw new InvalidArgumentException('Neplatná adresa účtu.');
        $route = '';
        $mode = $query['mode'] ?? 'login';
        $section = $query['section'] ?? '';
        if (!is_string($section) || ($section !== '' && $section !== 'overview' && !in_array($section, self::SECTIONS[$area], true))) {
            throw new InvalidArgumentException('Neznámá stránka účtu.');
        }
        if ($section !== '' && $section !== 'overview') {
            $route = $section;
            if (is_string($mode) && isset(self::MODES[$mode])) unset($query['mode']);
        } else {
            if (!is_string($mode) || !isset(self::MODES[$mode]) || ($area === 'admin' && $mode === 'register')) {
                throw new InvalidArgumentException('Neznámá stránka účtu.');
            }
            $route = self::MODES[$mode];
            unset($query['mode']);
        }
        unset($query['section']);
        $parameters = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return rtrim($basePath, '/') . '/' . $area . '/' . ($route !== '' ? $route . '/' : '') .
            ($parameters !== '' ? '?' . $parameters : '');
    }

    /** Path wins over legacy query selectors; POST is dispatched without redirecting its body. */
    public static function request(string $area, string $basePath, string $uri, array $query): array
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!isset(self::SECTIONS[$area]) || !is_string($path) || preg_match('/%(?:2f|5c|00)/i', $path)) {
            throw new InvalidArgumentException('Neznámá stránka účtu.');
        }
        $root = rtrim($basePath, '/') . '/' . $area;
        if ($path === $root . '.php') return ['query' => $query, 'url' => self::path($basePath, $area, $query)];
        if ($path !== $root && !str_starts_with($path, $root . '/')) throw new InvalidArgumentException('Neznámá stránka účtu.');
        $suffix = trim(substr($path, strlen($root)), '/');
        // Reject repeated slashes and extra path segments instead of silently showing another screen.
        if ($path !== $root && $path !== $root . '/' && $path !== $root . '/' . $suffix && $path !== $root . '/' . $suffix . '/') {
            throw new InvalidArgumentException('Neznámá stránka účtu.');
        }
        if ($suffix !== '') {
            unset($query['section']);
            $mode = array_search($suffix, self::MODES, true);
            if (is_string($mode) && !($area === 'admin' && $mode === 'register')) $query['mode'] = $mode;
            elseif (in_array($suffix, self::SECTIONS[$area], true)) {
                $query['section'] = $suffix;
                if (is_string($query['mode'] ?? null) && isset(self::MODES[$query['mode']])) unset($query['mode']);
            }
            else throw new InvalidArgumentException('Neznámá stránka účtu.');
        }
        return ['query' => $query, 'url' => self::path($basePath, $area, $query)];
    }

    public static function dispatch(string $area, string $basePath): void
    {
        try {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? self::path($basePath, $area));
            $route = self::request($area, $basePath, $uri, $_GET);
            if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && $uri !== $route['url']) {
                // Private/token-bearing routes must not be cached as permanent redirects.
                header('Cache-Control: private, no-store');
                header('Location: ' . $route['url'], true, 302);
                exit;
            }
            $_GET = $route['query'];
        } catch (InvalidArgumentException) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Stránka nebyla nalezena.';
            exit;
        }
    }
}
