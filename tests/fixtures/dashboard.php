<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__,2).'/src/bootstrap.php';
set_error_handler(static function(int $severity,string $message):never { throw new RuntimeException($message); });
$data = require __DIR__.'/dashboard-data.php';
$dashboard = \SimpleStore\Admin\StoreDashboard::summarize(...[...array_values($data), 'cs']);
$dashboardScope = 'published'; $dashboardError = '';
$basePath='/'; $adminUrl='/admin/'; $csrf='test-token'; $error=''; $screen='dashboard';
$site=['languages'=>['cs','en'],'default_language'=>'cs'];
require dirname(__DIR__,2).'/view/admin/layout.php';
