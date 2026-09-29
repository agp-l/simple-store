<?php
declare(strict_types=1);

use SimpleStore\Product\ProductRepository;

// admin.php has already checked the administrator session and CSRF token.
$key = $_POST['key'] ?? null;
$language = $_POST['language'] ?? null;
$revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT);
if (!is_string($key) || !is_string($language) || !is_int($revision) ||
    ($_POST['confirm'] ?? null) !== '1') {
    throw new InvalidArgumentException('Potvrď odstranění aktuální verze produktu.');
}

(new ProductRepository($db, $site['languages']))->deleteProduct($key, $language, $revision);
header('Location: ' . $basePath . $language . '?manage=1&deleted=1#produkty', true, 303);
