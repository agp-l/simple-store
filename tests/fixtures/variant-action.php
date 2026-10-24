<?php
declare(strict_types=1);
// Controller integration runner for a disposable CI database. Never a web endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$db = \SimpleStore\Database\ConnectionFactory::create(['host' => '127.0.0.1', 'user' => 'root',
    'password' => (string) getenv('MYSQL_TEST_PASSWORD'), 'database' => 'simple_store', 'port' => 3306]);
$site = ['languages' => ['cs', 'en']];
$basePath = '/';
$_SESSION = [];
$_POST = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
register_shutdown_function(static function (): void {
    echo json_encode($_SESSION, JSON_THROW_ON_ERROR);
});
require dirname(__DIR__, 2) . '/src/Admin/product-variants.php';
