<?php
declare(strict_types=1);

// admin.php has already checked administrator authentication and CSRF.
$language = $_POST['language'] ?? null;
$key = $_POST['key'] ?? null;
$operation = $_POST['operation'] ?? null;
$expected = $_POST['expected'] ?? null;
if (!is_string($language) || !is_string($key) || !is_string($operation) || !is_string($expected)) {
    throw new InvalidArgumentException('Neplatný požadavek na připnutí.');
}
(new \SimpleStore\Content\BlogPlacement($db, $site['languages']))->change($language, $operation, $key, $expected);
$return = (new \SimpleStore\Admin\StorefrontReturnUrl($basePath, $site['languages'], $site['default_language']))
    ->fromRequest($_POST['return_to'] ?? $basePath.$language.'/blog?manage=1');
header('Location: ' . \SimpleStore\Navigation\PanelUrl::pretty($return), true, 303);
exit;
