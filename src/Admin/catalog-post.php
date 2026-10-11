<?php
declare(strict_types=1);

// Shared administrator authentication and CSRF checks run before dispatch.
$language = $_POST['language'] ?? null; $key = $_POST['key'] ?? null;
$operation = $_POST['operation'] ?? null; $rawExpected = $_POST['expected'] ?? null;
if (!is_string($language) || !is_string($key) || !is_string($operation) || !is_string($rawExpected)) throw new InvalidArgumentException('Neplatný požadavek.');
$expected = json_decode($rawExpected, true);
if (!is_array($expected)) throw new InvalidArgumentException('Neplatný výběr.');
(new \SimpleStore\Content\CatalogPostSelection($db, $site['languages']))->change($language, $operation, $key, $expected);
header('Location: ' . $basePath . $language . '#kategorie', true, 303);
exit;
