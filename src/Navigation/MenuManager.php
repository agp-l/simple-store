<?php
declare(strict_types=1);

namespace SimpleStore\Navigation;

use InvalidArgumentException;
use SimpleStore\Category\CategoryPath;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;

/** Build named menu trees without mixing database queries into HTML views. */
final class MenuManager
{
    public function __construct(
        private ContentRepository $content,
        private CategoryRepository $categories,
        private UrlManager $url,
        private array $slots
    ) {
    }

    /** Each item has label, href, active and children; views choose how to display them. */
    public function links(string $slot = 'utility', ?string $contextPath = null): array
    {
        if (!isset($this->slots[$slot]) || !is_array($this->slots[$slot])) {
            throw new InvalidArgumentException('Unknown menu placement.');
        }
        $settings = $this->slots[$slot];
        $source = $settings['source'] ?? '';
        if ($source === 'categories') {
            $parent = $settings['parent'] ?? '';
            if ($parent === '@context') {
                $parent = $contextPath;
            }
            if (!is_string($parent) || ($parent !== '' && !CategoryPath::valid($parent))) {
                throw new InvalidArgumentException('Invalid menu category path.');
            }
            return $this->categoryLinks($this->categories->tree($this->url->getLanguage(), $parent));
        }
        if ($source === 'content') {
            $links = [];
            if ($settings['include_blog'] ?? false) {
                $links[] = $this->item('Blog', $this->url->path('blog'),
                    in_array($this->url->route()['name'], ['blog', 'post'], true));
            }
            foreach ($this->content->menuPages($this->url->getLanguage()) as $page) {
                $links[] = $this->item($page['title'], $this->url->path($page['slug']),
                    $this->url->getSegments() === [$page['slug']]);
            }
            return $links;
        }
        if ($source === 'manual') {
            return $this->manualLinks($settings['items'] ?? []);
        }
        throw new InvalidArgumentException('Unknown menu source.');
    }

    private function categoryLinks(array $rows): array
    {
        $links = [];
        $current = $this->url->categoryPath();
        foreach ($rows as $row) {
            $link = $this->item($row['title'], $this->url->category($row['path']),
                $current !== null && CategoryPath::contains($row['path'], $current),
                $this->categoryLinks($row['children']));
            $link['path'] = $row['path'];
            $links[] = $link;
        }
        return $links;
    }

    private function manualLinks(array $items): array
    {
        $links = [];
        foreach ($items as $item) {
            $path = $item['path'] ?? null;
            $category = $item['category'] ?? null;
            $external = $item['external'] ?? null;
            if (!is_string($item['label'] ?? null) || !is_array($item['children'] ?? [])) {
                throw new InvalidArgumentException('Invalid manual menu item.');
            }
            if (is_string($external) && MenuDefinitionRepository::validExternal($external)) {
                $href = $external;
                $active = false;
            } elseif (is_string($category)) {
                $href = $this->url->category($category);
                $active = $this->url->categoryPath() !== null &&
                    CategoryPath::contains($category, $this->url->categoryPath());
            } elseif (is_string($path)) {
                $href = $this->url->path($path);
                $requestedPath = implode('/', $this->url->getSegments());
                $active = $requestedPath === trim($path, '/') ||
                    (trim($path, '/') === 'blog' && str_starts_with($requestedPath, 'blog/'));
            } else {
                throw new InvalidArgumentException('A manual menu item needs a path or category.');
            }
            $children = $this->manualLinks($item['children'] ?? []);
            $active = $active || in_array(true, array_column($children, 'active'), true);
            $links[] = $this->item($item['label'], $href, $active, $children);
        }
        return $links;
    }

    private function item(string $label, string $href, bool $active, array $children = []): array
    {
        return compact('label', 'href', 'active', 'children');
    }
}
