<?php
declare(strict_types=1);

namespace SimpleStore\Configuration;

use InvalidArgumentException;
use RuntimeException;

/** One installation-owned address; never infer an origin from an HTTP request or database. */
final class Installation
{
    private string $base;
    private array $previous;

    public function __construct(string $base, array $previous = [])
    {
        if (!array_is_list($previous) || count($previous) > 32) throw new InvalidArgumentException('Invalid previous addresses.');
        $this->base = self::normalize($base);
        $this->previous = [];
        foreach ($previous as $url) {
            if (!is_string($url)) throw new InvalidArgumentException('Invalid previous installation address.');
            $this->previous[] = self::normalize($url);
        }
        $this->previous = array_values(array_unique($this->previous));
    }

    public static function current(): self
    {
        // Isolated CLI/browser tests may select a different configuration FILE.
        $file = getenv('SIMPLE_STORE_INSTALLATION_FILE');
        return self::fromFile(is_string($file) && $file !== '' ? $file :
            dirname(__DIR__, 2) . '/config/installation.json');
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            if ($file !== dirname(__DIR__, 2) . '/config/installation.json') {
                throw new RuntimeException('Installation configuration file is missing.');
            }
            $file = dirname(__DIR__, 2) . '/config/installation.example.json';
        }
        $json = @file_get_contents($file);
        if ($json === false || strlen($json) > 16384) throw new RuntimeException('Cannot read installation configuration.');
        try {
            $decoded = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
            if (!$decoded instanceof \stdClass) throw new InvalidArgumentException('Expected a JSON object.');
            $data = get_object_vars($decoded);
            if (!is_array($data) || !is_string($data['public_base_url'] ?? null) ||
                !is_array($data['previous_base_urls'] ?? []) ||
                array_diff(array_keys($data), ['public_base_url', 'previous_base_urls'])) {
                throw new InvalidArgumentException('Invalid installation fields.');
            }
            return new self($data['public_base_url'], $data['previous_base_urls'] ?? []);
        } catch (\JsonException | InvalidArgumentException $error) {
            throw new RuntimeException('Invalid config/installation.json. Check the public address and JSON syntax.', 0, $error);
        }
    }

    public static function normalize(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (strlen($url) > 1000 || !is_array($parts) || !filter_var($url, FILTER_VALIDATE_URL) ||
            preg_match('/[\x00-\x20\x7f\\\\]/', $url) || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Invalid installation address.');
        }
        $host = strtolower($parts['host']);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if (($parts['scheme'] ?? '') !== 'https' && !(($parts['scheme'] ?? '') === 'http' && $local)) {
            throw new InvalidArgumentException('Installation address must use HTTPS.');
        }
        $path = $parts['path'] ?? '';
        if ($path !== '' && preg_match('~^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $path) !== 1) {
            throw new InvalidArgumentException('Invalid installation directory.');
        }
        $port = isset($parts['port']) && !(($parts['scheme'] === 'https' && $parts['port'] === 443) ||
            ($parts['scheme'] === 'http' && $parts['port'] === 80)) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $host . $port . $path;
    }

    public function baseUrl(): string { return $this->base; }
    public function basePath(): string { return rtrim((string) parse_url($this->base, PHP_URL_PATH), '/') . '/'; }
    public function previousBaseUrls(): array { return $this->previous; }
    public function url(string $relative = ''): string
    {
        if (str_starts_with($relative, '/') || preg_match('/[\x00-\x20\x7f\\\\]/', $relative) ||
            preg_match('~(?:^|/)\.\.(?:/|$)~', $relative)) throw new InvalidArgumentException('Invalid installation-relative URL.');
        return $this->base . ($relative === '' ? '' : '/' . $relative);
    }

    /** Relocate only explicitly owned origins and path boundaries; keep partners untouched. */
    public function relocate(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) ||
            isset($parts['user']) || isset($parts['pass']) || empty($parts['host'])) return $url;
        $origin = strtolower($parts['scheme'] . '://' . $parts['host']) .
            (isset($parts['port']) && $parts['port'] !== ($parts['scheme'] === 'https' ? 443 : 80) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '';
        $bases = array_unique(array_merge([$this->base], $this->previous));
        usort($bases, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($bases as $base) {
            $p = parse_url($base);
            $baseOrigin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            $prefix = $p['path'] ?? '';
            if ($origin !== $baseOrigin || ($path !== $prefix && !str_starts_with($path, $prefix . '/'))) continue;
            return $this->base . substr($path, strlen($prefix)) .
                (isset($parts['query']) ? '?' . $parts['query'] : '') .
                (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
        }
        return $url;
    }

    /** Presentation only: stored revisions, orders and legal snapshots remain unchanged. */
    public function relocateText(string $text): string
    {
        return preg_replace_callback('~https?://[^\s<>"\'\[\]{}()]+~u',
            fn (array $m): string => $this->relocate($m[0]), $text) ?? $text;
    }

    public function checkout(array $settings): array
    {
        foreach (['comgate', 'gopay', 'btcpay'] as $gateway) {
            $settings[$gateway]['return_base_url'] = $this->base;
        }
        if (is_string($settings['terms_url'] ?? null)) {
            $settings['terms_url'] = $this->relocatePath($settings['terms_url']);
        }
        return $settings;
    }

    /** A stored installation-owned path, e.g. the checkout terms page. */
    public function relocatePath(string $path): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) return $path;
        $prefixes = array_unique(array_map(static fn (string $base): string =>
            rtrim((string) parse_url($base, PHP_URL_PATH), '/') . '/', array_merge([$this->base], $this->previous)));
        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) return $this->basePath() . substr($path, strlen($prefix));
        }
        return $path;
    }
}
