<?php
declare(strict_types=1);

// An HTTP request starts the cart before the administrator, as on the real site.
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleStore\Auth\RoleAuth;
use SimpleStore\Checkout\CartSession;

final class TestAdminAuth extends RoleAuth
{
    public function __construct()
    {
        parent::__construct('/simple-store/', 'simple_store_admin', 'admin', 'Strict');
    }

    protected function byName(string $name): ?array
    {
        return $name === 'admin' ? $this->byId(1) : null;
    }

    protected function byId(int $id): ?array
    {
        // Stable across requests, like the password hash stored in the database.
        return $id === 1 ? ['id' => 1,
            'password_hash' => '$2y$12$K6oaVzh/wtRKsxHqtw5uruJZTUHo31wM59WpvCyyoBrU9GwRhFfgC'] : null;
    }
}

(new CartSession('/simple-store/'))->count();
$auth = new TestAdminAuth();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$auth->validToken($_POST['csrf'] ?? null)) {
        http_response_code(403);
        echo 'csrf rejected';
        return;
    }
    if (($_POST['action'] ?? '') === 'login') {
        if (!$auth->signIn('admin', 'password')) {
            http_response_code(403);
            echo 'login rejected';
            return;
        }
        echo 'login accepted';
        return;
    }
    echo $auth->signedIn() ? 'action accepted' : 'login required';
    return;
}

echo $auth->token() . '|' . ($auth->signedIn() ? 'signed' : 'anonymous');
