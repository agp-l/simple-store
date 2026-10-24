<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/src/bootstrap.php';
set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
$site = ['default_language' => 'cs', 'languages' => ['cs']];
$basePath = '/';
$accountUrl = '/account.php';
$screen = 'account';
$section = 'addresses';
$csrf = 'test-token';
$error = '';
$chrome = [];
$user = ['id' => 7, 'email' => 'customer@example.test', 'display_name' => 'Eva Nová', 'phone' => ''];
$addresses = [];
$orders = [];
$editAddress = null;
$priceDisplay = null;
require dirname(__DIR__, 2) . '/view/account/layout.php';
