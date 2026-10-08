<?php
declare(strict_types=1);

namespace SimpleStore\Navigation;

use MeekroDB;
use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;

/** Prepare the same storefront navigation for public, admin and customer pages. */
final class StorefrontMenus
{
    public static function load(MeekroDB $db, UrlManager $url, ContentRepository $content,
        CategoryRepository $categories): array
    {
        $definitions = new MenuDefinitionRepository($db,
            require dirname(__DIR__, 2) . '/config/menus.php', $categories);
        $settings = $definitions->settings($url->getLanguage());
        $menus = new MenuManager($content, $categories, $url, $settings);
        $hasContent = (int) $db->queryFirstField(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            'content_revisions'
        ) > 0;

        $footer = $menus->links('footer');
        // A page selected for the footer becomes a link only once it is published.
        $pageSlugs = [];
        $prefix = $url->path() . '/';
        $collect = static function (array $links) use (&$collect, &$pageSlugs, $prefix): void {
            foreach ($links as $link) {
                if (str_starts_with($link['href'], $prefix)) {
                    $relative = substr($link['href'], strlen($prefix));
                    if (!str_contains($relative, '/') &&
                        !in_array($relative, ['blog', 'kosik', 'pokladna'], true)) {
                        $pageSlugs[$relative] = true;
                    }
                }
                $collect($link['children']);
            }
        };
        $collect($footer);
        $published = $hasContent ? array_flip($content->publishedPageSlugs(
            $url->getLanguage(), array_keys($pageSlugs))) : [];
        $hiddenUrls = [];
        foreach (array_keys($pageSlugs) as $slug) {
            if (!isset($published[$slug])) $hiddenUrls[$url->path($slug)] = true;
        }
        $filter = static function (array $links) use (&$filter, $hiddenUrls): array {
            $visible = [];
            foreach ($links as $link) {
                if (isset($hiddenUrls[$link['href']])) continue;
                $link['children'] = $filter($link['children']);
                $visible[] = $link;
            }
            return $visible;
        };

        $utility = $hasContent ? $menus->links('utility') : [];
        if (!$hasContent && ($settings['utility']['items'] ?? []) !== []) {
            $fallback = new MenuManager($content, $categories, $url, [
                'utility' => ['source' => 'manual', 'items' => $settings['utility']['items']],
            ]);
            $utility = $fallback->links('utility');
        }

        return [
            'manager' => $menus,
            'hasContent' => $hasContent,
            'primaryMenu' => $menus->links('primary'),
            'utilityMenu' => $utility,
            'footerMenu' => $filter($footer),
            'footerTitle' => $settings['footer']['title'] ?? 'Informace',
            'manualPrimaryMenu' => ($settings['primary']['source'] ?? '') === 'manual',
            'manualUtilityMenu' => ($settings['utility']['source'] ?? '') === 'manual',
            'manualFooterMenu' => ($settings['footer']['source'] ?? '') === 'manual',
            'manualCategoryMenu' => ($settings['category_tabs']['source'] ?? '') === 'manual',
            'categoryLabels' => array_column($categories->all($url->getLanguage()), 'title', 'path'),
        ];
    }
}
