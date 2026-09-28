<?php
declare(strict_types=1);

use SimpleStore\Content\ContentRepository;
use SimpleStore\Database\ConnectionFactory;
use SimpleStore\Navigation\MenuManager;
use SimpleStore\Navigation\UrlManager;
use SimpleStore\Rendering\PageRenderer;

$site = require __DIR__ . '/src/bootstrap.php';
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/produkt-topo-terraventure.php'), '/') . '/';
$url = new UrlManager($base, $_SERVER['SCRIPT_NAME'] ?? '/produkt-topo-terraventure.php',
    $site['languages'], $site['default_language']);
$data = [
    'title' => "Topo Athletic Terraventure 5 Men's — dobrodruzi.cz",
    'description' => "Trailové boty Topo Athletic Terraventure 5 Men's v obchodě Dobrodruzi.",
    'language' => $url->getLanguage(),
    'basePath' => $url->getBasePath(),
    'showErrors' => $site['debug'],
];

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    $data['setupNotice'] = 'Chybí knihovny Composeru. V kořeni projektu spusť: composer install';
}
if (!is_file(__DIR__ . '/config/database.php')) {
    $data['setupNotice'] = ($data['setupNotice'] ?? '') . "\nChybí přístup k databázi. Spusť: cp config/database.example.php config/database.php";
} elseif (is_file(__DIR__ . '/vendor/autoload.php')) {
    try {
        $database = require __DIR__ . '/config/database.php';
        $db = ConnectionFactory::create($database);
        $data['menuLinks'] = (new MenuManager(new ContentRepository($db, $site['languages']), $url))->links();
    } catch (Throwable $error) {
        error_log((string) $error);
        $data['debugError'] = (string) $error;
    }
}
(new PageRenderer(__DIR__ . '/view'))->render('product', $data);
