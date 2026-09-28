<?php
declare(strict_types=1);

namespace SimpleStore\Navigation;

use SimpleStore\Content\ContentRepository;

/** Build link data; HTML stays in view/menu.php. */
final class MenuManager
{
    public function __construct(private ContentRepository $content, private UrlManager $url)
    {
    }

    public function links(): array
    {
        $links = [['label' => 'Blog', 'href' => $this->url->path('blog')]];
        foreach ($this->content->menuPages($this->url->getLanguage()) as $page) {
            $links[] = [
                'label' => $page['title'],
                'href' => $this->url->path($page['slug']),
            ];
        }
        return $links;
    }
}
