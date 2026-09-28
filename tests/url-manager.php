<?php
declare(strict_types=1);

use SimpleStore\Navigation\UrlManager;

require dirname(__DIR__) . '/src/Navigation/UrlManager.php';

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$home = new UrlManager('/index.php?category=batohy', '/index.php');
check($home->getSegment(0) === null, 'Root catalog');
check($home->getBasePath() === '/', 'Root base path');
check($home->path('blog') === '/cs/blog', 'Blog link');

$nested = new UrlManager('/shop/cs/blog/na-ceste?x=1', '/shop/index.php', ['cs', 'en'], 'cs');
check($nested->getLanguage() === 'cs', 'Locale prefix');
check($nested->getSegment(0) === 'blog' && $nested->getSegment(1) === 'na-ceste', 'URL segments');
check($nested->path('o-nas', 'en') === '/shop/en/o-nas', 'Nested localized path');

foreach (['/shop/cs/%2e%2e/config', '/shop/cs/blog%2ftest', '/shop/cs//blog', '/shop2/cs/blog'] as $bad) {
    try {
        new UrlManager($bad, '/shop/index.php');
        throw new RuntimeException('Invalid path was accepted: ' . $bad);
    } catch (InvalidArgumentException $expected) {
        // These paths must never resolve to CMS pages.
    }
}

echo "URL manager tests passed.\n";
