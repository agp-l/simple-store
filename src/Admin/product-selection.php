<?php
declare(strict_types=1);

use SimpleStore\Product\CategoryProductSelection;
use SimpleStore\Product\HomepageProductSelection;

// Authentication and CSRF have already been checked by admin.php.
$isReorder = ($_POST['action'] ?? '') === 'product-selection-order';
try {
    $language = $_POST['language'] ?? '';
    $scope = $_POST['scope'] ?? '';
    if (!is_string($language) || !in_array($language, $site['languages'], true) || !is_string($scope)) throw new InvalidArgumentException('Neplatný výběr.');
    $home = new HomepageProductSelection($db, $site['languages']);
    $tips = new CategoryProductSelection($db, $site['languages']);
    if ($isReorder) {
        $order = json_decode(is_string($_POST['order'] ?? null) ? $_POST['order'] : '', true);
        $expected = json_decode(is_string($_POST['expected'] ?? null) ? $_POST['expected'] : '', true);
        if (!is_array($order) || !is_array($expected)) throw new InvalidArgumentException('Neplatné pořadí.');
        if ($scope === 'home') $home->reorder($language, $order, $expected);
        else $tips->change($language, $scope, 'reorder', '', $order, $expected);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['saved' => true], JSON_THROW_ON_ERROR);
        exit;
    }
    $key = $_POST['key'] ?? '';
    $operation = $_POST['operation'] ?? '';
    if (!is_string($key) || !is_string($operation) || !in_array($operation, ['add', 'remove'], true)) throw new InvalidArgumentException('Neplatná akce.');
    $row = $db->queryFirstRow('SELECT slug FROM shop_product_revisions WHERE product_key=%s AND language=%s AND active_product_key IS NOT NULL', $key, $language);
    if ($row === null && $operation !== 'remove') throw new InvalidArgumentException('Produkt již neexistuje.');
    if ($scope === 'home') $home->change($language, $operation, $key);
    else $tips->change($language, $scope, $operation, $key);
    $returnUrl = $scope !== 'home' && ($_POST['return_category'] ?? '') === '1'
        ? $basePath . $language . '/kategorie-produktu/' . $scope . '#produkty'
        : ($row === null ? $basePath . $language : $basePath . $language . '/produkt/' . rawurlencode($row['slug']) . '?edit=1&selection_saved=1');
    header('Location: ' . $returnUrl, true, 303);
    exit;
} catch (InvalidArgumentException $error) {
    if (!$isReorder) throw $error;
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
