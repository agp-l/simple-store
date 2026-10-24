<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2) . '/src/bootstrap.php';
set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
$case = $argv[1] ?? 'start';
$basePath = '/'; $adminUrl = '/admin.php'; $csrf = 'test-token'; $error = '';
$site = ['languages' => ['cs'], 'default_language' => 'cs'];
$screen = 'settings'; $settingsTab = 'products'; $settingsError = ''; $form = [];
$massNotice = null; $massCount = $case === 'empty' ? 0 : 100;
$massValues = ['quantity' => '', 'stock_status' => '', 'visibility' => '']; $massPreview = null;
$variantBulkQuantity = '10'; $variantBulkPreview = null;
$saleBulkReady = $case !== 'uninstalled'; $saleBulkCounts = ['own' => 80, 'assisted' => 20];
$saleBulkMode = in_array($case, ['assisted', 'own', 'same'], true) ? ($case === 'same' ? 'own' : $case) : '';
$saleBulkPreview = $saleBulkMode === '' ? null : ['mode' => $saleBulkMode, 'total' => 100,
    'changed' => $case === 'same' ? 0 : 80, 'unchanged' => $case === 'same' ? 100 : 20, 'token' => 'preview-token',
    'issues' => $case !== 'assisted' ? [] : [
        ['name' => 'Lehká bunda <test>', 'slug' => 'lehka-bunda', 'language' => 'cs', 'reasons' => ['Není vybraný prodávající.']],
        ['name' => 'Ultralehký turistický batoh s mnoha barevnými variantami', 'slug' => 'batoh', 'language' => 'cs',
            'reasons' => ['Chybí cena dodavatele u produktu nebo některé nabízené varianty.']],
    ]];
require dirname(__DIR__, 2) . '/view/admin/layout.php';
