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

        $infoPages = [
            'doprava-a-platba' => 'Doprava a platba',
            'vymena-a-vraceni-zbozi' => 'Výměna a vrácení zboží',
            'obchodni-podminky' => 'Obchodní podmínky',
            'reklamacni-rad' => 'Reklamační řád',
            'ochrana-osobnich-udaju' => 'Ochrana osobních údajů',
            'kontakt' => 'Kontakt',
        ];
        $published = $hasContent ? array_flip($content->publishedPageSlugs(
            $url->getLanguage(), array_keys($infoPages))) : [];
        $footerInfoMenu = [];
        foreach ($infoPages as $slug => $label) {
            if (isset($published[$slug])) {
                $footerInfoMenu[] = ['label' => $label, 'href' => $url->path($slug)];
            }
        }

        return [
            'manager' => $menus,
            'hasContent' => $hasContent,
            'primaryMenu' => $menus->links('primary'),
            'utilityMenu' => $hasContent ? $menus->links('utility') : [],
            'footerMenu' => $menus->links('footer'),
            'footerInfoMenu' => $footerInfoMenu,
            'manualPrimaryMenu' => ($settings['primary']['source'] ?? '') === 'manual',
            'manualUtilityMenu' => ($settings['utility']['source'] ?? '') === 'manual',
            'manualFooterMenu' => ($settings['footer']['source'] ?? '') === 'manual',
            'manualCategoryMenu' => ($settings['category_tabs']['source'] ?? '') === 'manual',
            'categoryLabels' => array_column($categories->all($url->getLanguage()), 'title', 'path'),
        ];
    }
}
