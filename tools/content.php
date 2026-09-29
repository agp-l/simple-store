<?php
declare(strict_types=1);

use SimpleStore\Content\ContentRepository;
use SimpleStore\Database\ConnectionFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
$site = require $root . '/src/bootstrap.php';
$missing = [];
if (!is_file($root . '/vendor/autoload.php')) {
    $missing[] = 'Chybí vendor/autoload.php. V kořeni projektu spusť: composer install';
}
if (!is_file($root . '/config/database.php')) {
    $missing[] = 'Chybí config/database.php. Spusť: cp config/database.example.php config/database.php a uprav přístupové údaje.';
}
if ($missing !== []) {
    fwrite(STDERR, implode(PHP_EOL, $missing) . PHP_EOL);
    exit(1);
}

$database = require $root . '/config/database.php';
$content = new ContentRepository(
    ConnectionFactory::create($database),
    $site['languages'],
    $site['revision_limit']
);

$command = $argv[1] ?? '';
try {
    if ($command === 'history' && count($argv) === 4) {
        foreach ($content->history($argv[2], $argv[3]) as $row) {
            echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
        }
        exit;
    }

    if ($command === 'create' && count($argv) === 7) {
        [$type, $language, $slug, $title, $body] = array_slice($argv, 2);
        $key = null;
        $revision = null;
    } elseif ($command === 'update' && count($argv) === 9) {
        [$key, $number, $type, $language, $slug, $title, $body] = array_slice($argv, 2);
        if (filter_var($number, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Revision number must be an integer.');
        }
        $revision = (int) $number;
    } else {
        fwrite(STDERR, "Use:\n"
            . "  php tools/content.php create page cs o-nas 'O nás' 'Text stránky'\n"
            . "  php tools/content.php update KEY REVISION page cs o-nas 'O nás' 'Nový text'\n"
            . "  php tools/content.php history KEY cs\n");
        exit(1);
    }

    $saved = $content->saveRevision([
        'type' => $type,
        'language' => $language,
        'slug' => $slug,
        'title' => $title,
        'body' => $body,
        'published' => true,
        'visible_in_menu' => $type === 'page',
    ], $key, $revision);
    echo json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
