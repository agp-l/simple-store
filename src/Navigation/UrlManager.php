<?php
declare(strict_types=1);

namespace SimpleStore\Navigation;

use InvalidArgumentException;
use SimpleStore\Category\CategoryPath;

/** Parse local paths without trusting the Host header or server protocol. */
final class UrlManager
{
    private string $basePath;
    private string $language;
    private array $segments = [];
    private array $languages;

    public function __construct(
        string $requestUri,
        string $scriptName,
        array $languages = ['cs'],
        string $defaultLanguage = 'cs'
    ) {
        if ($languages === [] || !in_array($defaultLanguage, $languages, true)) {
            throw new InvalidArgumentException('Default language must be supported.');
        }
        $seen = [];
        foreach ($languages as $language) {
            // The schema stores ISO 639-1 codes in CHAR(2).
            if (!is_string($language) || preg_match('/^[a-z]{2}$/D', $language) !== 1 ||
                isset($seen[$language])) {
                throw new InvalidArgumentException('Languages must use distinct two-letter lowercase codes.');
            }
            $seen[$language] = true;
        }

        $this->languages = $languages;
        $this->language = $defaultLanguage;
        $folder = str_replace('\\', '/', dirname($scriptName));
        $this->basePath = $folder === '/' || $folder === '.' ? '/' : '/' . trim($folder, '/') . '/';

        $rawPath = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($rawPath) || preg_match('/%(?:2f|5c|00)/i', $rawPath)) {
            throw new InvalidArgumentException('Invalid URL path.');
        }

        $path = rawurldecode($rawPath);
        if (!str_starts_with($path, $this->basePath) || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            throw new InvalidArgumentException('Invalid URL path.');
        }

        $relative = trim(substr($path, strlen($this->basePath)), '/');
        if ($relative === '' || $relative === 'index.php') {
            return;
        }

        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $segment) !== 1) {
                throw new InvalidArgumentException('Invalid URL segment.');
            }
        }

        if (in_array($segments[0], $this->languages, true)) {
            $this->language = array_shift($segments);
        }
        $this->segments = $segments;
    }

    public function getSegment(int $index): ?string
    {
        if ($index < 0) {
            throw new InvalidArgumentException('Segment index must not be negative.');
        }
        return $this->segments[$index] ?? null;
    }

    public function getSegments(): array
    {
        return $this->segments;
    }

    public function categoryPath(): ?string
    {
        $route = $this->route();
        return $route['name'] === 'category' ? $route['path'] : null;
    }

    /** Keep the public URL grammar in one place; index.php chooses the data source. */
    public function route(): array
    {
        $parts = $this->segments;
        if ($parts === []) {
            return ['name' => 'catalog'];
        }
        if ($parts[0] === 'kategorie-produktu') {
            $path = implode('/', array_slice($parts, 1));
            return count($parts) >= 2 && CategoryPath::valid($path)
                ? ['name' => 'category', 'path' => $path] : ['name' => 'not-found'];
        }
        if ($parts[0] === 'produkt') {
            return count($parts) === 2
                ? ['name' => 'product', 'slug' => $parts[1]] : ['name' => 'not-found'];
        }
        if ($parts[0] === 'blog') {
            if (count($parts) === 1) return ['name' => 'blog'];
            return count($parts) === 2
                ? ['name' => 'post', 'slug' => $parts[1]] : ['name' => 'not-found'];
        }
        if ($parts[0] === 'kosik' || $parts[0] === 'pokladna') {
            return count($parts) === 1
                ? ['name' => $parts[0] === 'kosik' ? 'cart' : 'checkout']
                : ['name' => 'not-found'];
        }
        if ($parts[0] === 'objednavka') {
            return count($parts) === 2 && preg_match('/^[a-f0-9]{64}$/D', $parts[1]) === 1
                ? ['name' => 'order', 'token' => $parts[1]] : ['name' => 'not-found'];
        }
        return count($parts) === 1
            ? ['name' => 'page', 'slug' => $parts[0]] : ['name' => 'not-found'];
    }

    public function category(string $path, ?string $language = null): string
    {
        if (!CategoryPath::valid($path)) {
            throw new InvalidArgumentException('Invalid category path.');
        }
        return $this->path('kategorie-produktu/' . $path, $language);
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function path(string $relative = '', ?string $language = null): string
    {
        $language ??= $this->language;
        if (!in_array($language, $this->languages, true)) {
            throw new InvalidArgumentException('Unsupported language.');
        }

        $relative = trim($relative, '/');
        if ($relative !== '' && preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$~D', $relative) !== 1) {
            throw new InvalidArgumentException('Invalid path to build.');
        }
        return $this->basePath . $language . ($relative === '' ? '' : '/' . $relative);
    }
}
