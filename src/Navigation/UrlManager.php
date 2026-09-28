<?php
declare(strict_types=1);

namespace SimpleStore\Navigation;

use InvalidArgumentException;

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
        if (!in_array($defaultLanguage, $languages, true)) {
            throw new InvalidArgumentException('Default language must be supported.');
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
