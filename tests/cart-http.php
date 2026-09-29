<?php
declare(strict_types=1);

// Router for the integration check: an Apache-like subdirectory and two requests.
require dirname(__DIR__) . '/src/bootstrap.php';

$cart = new SimpleStore\Checkout\CartSession('/simple-store/');
header('Content-Type: text/plain; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$cart->validToken($_POST['csrf'] ?? null)) {
        http_response_code(403);
        echo 'csrf rejected';
        return;
    }
    if (($_POST['action'] ?? '') === 'clear') {
        $cart->clear();
    }
    echo 'csrf accepted';
    return;
}

echo $cart->token();
