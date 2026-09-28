<?php
declare(strict_types=1);

namespace SimpleStore\Rendering;

use InvalidArgumentException;

/** Pass explicit data to PHP views without embedding SQL or Twig in templates. */
final class PageRenderer
{
    private const PAGES = ['catalog', 'product', 'page', 'blog', 'post', 'not-found', 'unavailable'];

    public function __construct(private string $viewPath)
    {
    }

    public function render(string $page, array $data = [], int $status = 200): void
    {
        if (!in_array($page, self::PAGES, true)) {
            throw new InvalidArgumentException('Unknown view.');
        }

        http_response_code($status);
        $pageTitle = (string) ($data['title'] ?? 'Dobrodruzi.cz — vybavení na každou cestu');
        $pageDescription = (string) ($data['description'] ?? 'Batohy, stany, spacáky a vybavení na cesty ven.');
        $language = (string) ($data['language'] ?? 'cs');
        $basePath = (string) ($data['basePath'] ?? '/');
        $siteRoot = htmlspecialchars($basePath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $menuLinks = $data['menuLinks'] ?? [];
        $content = $data['content'] ?? null;
        $posts = $data['posts'] ?? [];
        $backLink = (string) ($data['backLink'] ?? '');
        require $this->viewPath . '/layout.php';
    }
}
