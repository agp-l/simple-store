<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/src/bootstrap.php';
set_error_handler(static function(int $severity, string $message): never { throw new RuntimeException($message); });
$basePath='/'; $adminUrl='/admin.php'; $csrf='test-token'; $error='';
$site=['languages'=>['cs'],'default_language'=>'cs']; $screen='product-import';
$language='cs'; $importTab='help'; $importError=$transferError=$promptError='';
$aiPrompt='Piš česky a věcně. Starší vlastní zadání zůstane zachované.';
$importCategories=[['path'=>'batohy','title'=>'Batohy']];
$categories=new class { public function findForAdmin(string $language, string $path): array { return ['path'=>$path]; } };
require dirname(__DIR__,2).'/view/admin/layout.php';
