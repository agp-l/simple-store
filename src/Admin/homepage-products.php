<?php
declare(strict_types=1);

use SimpleStore\Product\HomepageProductSelection;

// admin.php verifies the administrator session and CSRF token before reaching this action.
$language = $_POST['language'] ?? null;
$operation = $_POST['operation'] ?? null;
$key = $_POST['key'] ?? '';
$pick = $_POST['pick'] ?? '';
if (!is_string($language) || !in_array($language, $site['languages'], true) ||
    !is_string($operation) || !is_string($key) || !is_string($pick) || strlen($pick) > 200) {
    throw new InvalidArgumentException('Vyber platný produkt a jazyk.');
}
$params = ['homepage_edit' => '1'];
try {
    (new HomepageProductSelection($db, $site['languages']))->change($language, $operation, $key);
    $params['saved'] = '1';
} catch (InvalidArgumentException $error) {
    $params['homepage_error'] = $error->getMessage();
}
if (trim($pick) !== '') $params['pick'] = trim($pick);
header('Location: ' . $basePath . $language . '?' . http_build_query($params) . '#homepage-editor', true, 303);
exit;
