<?php
declare(strict_types=1);

// admin.php has already checked the administrator session and CSRF token.
$key = $_POST['key'] ?? null;
$language = $_POST['language'] ?? null;
$type = $_POST['type'] ?? null;
$revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
if (!is_string($key) || !is_string($language) || !is_string($type) || !is_int($revision) ||
    ($_POST['confirm'] ?? null) !== '1') {
    throw new InvalidArgumentException('Potvrď odstranění aktuální verze stránky nebo článku.');
}

$content->deleteDocument($key, $language, $type, $revision);
header('Location: ' . ($type === 'post' ? $basePath . $language . '/blog?manage=1&deleted=1' :
    $adminUrl . '?section=contents&type=page&language=' . rawurlencode($language) . '&deleted=1'), true, 303);
