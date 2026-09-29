<?php
declare(strict_types=1);

use SimpleStore\Category\CategoryRepository;
use SimpleStore\Content\ContentRepository;
use SimpleStore\Navigation\StorefrontMenus;
use SimpleStore\Navigation\UrlManager;

class MeekroDB
{
    public function queryFirstField(string $sql, mixed ...$args): int
    {
        return ($args[0] ?? '') === 'content_revisions' ? 1 : 0;
    }

    public function query(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, 'SELECT slug FROM content_revisions')) {
            return [['slug' => 'obchodni-podminky']];
        }
        return [];
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

$db = new MeekroDB();
$menu = StorefrontMenus::load($db,
    new UrlManager('/shop/cs', '/shop/index.php'), new ContentRepository($db),
    new CategoryRepository($db));
$links = $menu['footerMenu'];
if ($menu['footerTitle'] !== 'Informace' || count($links) !== 1 ||
    $links[0]['href'] !== '/shop/cs/obchodni-podminky' ||
    $links[0]['label'] !== 'Obchodní podmínky') {
    throw new RuntimeException('The default footer must hide unpublished pages and keep published links.');
}

echo "Storefront footer passed.\n";
