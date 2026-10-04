<?php
declare(strict_types=1);

namespace SimpleStore\Admin;

use InvalidArgumentException;
use SimpleStore\Navigation\UrlManager;

/** Restrict the preview toggle's redirect to a public storefront route. */
final class StorefrontReturnUrl
{
    public function __construct(
        private string $basePath,
        private array $languages,
        private string $defaultLanguage
    ) {
    }

    public function fromRequest(mixed $requested): string
    {
        $fallback = $this->basePath . $this->defaultLanguage;
        if (!is_string($requested) || strlen($requested) > 2048 ||
            preg_match('/[\x00-\x1f\x7f]/', $requested)) return $fallback;

        $parts = parse_url($requested);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) ||
            isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) return $fallback;

        $path = $parts['path'] ?? '';
        try {
            $url = new UrlManager($path, $this->basePath . 'index.php', $this->languages, $this->defaultLanguage);
            if ($url->getBasePath() !== $this->basePath ||
                !in_array($url->route()['name'], ['catalog', 'category', 'product', 'blog', 'post',
                    'page', 'cart', 'checkout', 'order'], true)) return $fallback;
        } catch (InvalidArgumentException) {
            return $fallback;
        }

        $query = [];
        parse_str($parts['query'] ?? '', $input);
        foreach (['edit', 'manage', 'homepage_edit', 'visibility', 'pick', 'pick_offset',
            'draft_offset', 'search', 'sort', 'all', 'offset'] as $key) {
            if (isset($input[$key]) && is_string($input[$key]) && strlen($input[$key]) <= 200) {
                $query[$key] = $input[$key];
            }
        }
        return $path . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
